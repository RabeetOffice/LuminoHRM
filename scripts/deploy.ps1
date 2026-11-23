<#
.SYNOPSIS
    Prepares a clean staging directory (build\deploy) to hand to the Hostinger uploader.
    Does NOT upload anything. See docs/deploy.md.

.DESCRIPTION
    1. refuses to run with uncommitted changes (unless -AllowDirty)
    2. runs the test suite (stops on failure)
    3. npm run build
    4. copies only what the server needs into build\deploy
    5. composer install --no-dev --optimize-autoloader there
    6. removes local-only files, creates the storage skeleton, writes release.txt

    Compatible with Windows PowerShell 5.1.
#>
[CmdletBinding()]
param(
    [switch]$AllowDirty
)

$ErrorActionPreference = 'Stop'

$PhpDir = 'C:\php84'
$Php = Join-Path $PhpDir 'php.exe'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$Stage = Join-Path $RepoRoot 'build\deploy'

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Assert-LastExit([string]$What) {
    if ($LASTEXITCODE -ne 0) {
        throw "$What failed (exit code $LASTEXITCODE)."
    }
}

function Write-Utf8NoBom([string]$Path, [string]$Text) {
    [System.IO.File]::WriteAllText($Path, $Text, (New-Object System.Text.UTF8Encoding($false)))
}

if (-not (Test-Path $Php)) {
    throw "PHP 8.4 not found at $Php (the php on PATH may be a different version and must not be used)."
}

$ComposerPhar = $null
foreach ($candidate in @($env:COMPOSER_PHAR, 'C:\ProgramData\ComposerSetup\bin\composer.phar')) {
    if ($candidate -and (Test-Path $candidate)) { $ComposerPhar = $candidate; break }
}
if (-not $ComposerPhar) {
    throw 'composer.phar not found. Set COMPOSER_PHAR to its path.'
}

Set-Location $RepoRoot

# Put PHP 8.4 first on PATH for this script only (npm scripts and composer scripts call bare "php").
$env:PATH = "$PhpDir;$env:PATH"

# --- 1. clean working tree -------------------------------------------------
Write-Step 'Checking git state'
$dirty = git status --porcelain
Assert-LastExit 'git status'
if ($dirty -and -not $AllowDirty) {
    Write-Host $dirty
    throw 'Uncommitted changes. Commit them (so release.txt matches a commit) or pass -AllowDirty.'
}
$sha = (git rev-parse --short HEAD).Trim()
Assert-LastExit 'git rev-parse'

# --- 2. tests --------------------------------------------------------------
Write-Step 'Running the test suite'
& $Php artisan test
Assert-LastExit 'Test suite'

# --- 3. front-end build ----------------------------------------------------
Write-Step 'Building front-end assets'
npm run build
Assert-LastExit 'npm run build'
if (-not (Test-Path (Join-Path $RepoRoot 'public\build\manifest.json'))) {
    throw 'public\build\manifest.json missing after build.'
}

# --- 4. staging directory --------------------------------------------------
Write-Step "Preparing $Stage"
$buildDir = Join-Path $RepoRoot 'build'
if (Test-Path $Stage) {
    $resolved = (Resolve-Path $Stage).Path
    if (-not $resolved.StartsWith($buildDir + '\')) {
        throw "Refusing to delete $resolved (outside $buildDir)."
    }
    Remove-Item -LiteralPath $resolved -Recurse -Force
}
New-Item -ItemType Directory -Path $Stage -Force | Out-Null

$items = @('app', 'bootstrap', 'config', 'database', 'public', 'routes', 'deploy', 'lang', 'artisan', 'composer.json', 'composer.lock', '.htaccess')
foreach ($item in $items) {
    $src = Join-Path $RepoRoot $item
    if (Test-Path $src) {
        Copy-Item -LiteralPath $src -Destination (Join-Path $Stage $item) -Recurse -Force
    } elseif ($item -ne 'lang') {
        throw "Expected $item in the repository root."
    }
}
# resources: only the Blade views (js/css are compiled into public/build)
New-Item -ItemType Directory -Path (Join-Path $Stage 'resources') -Force | Out-Null
Copy-Item -LiteralPath (Join-Path $RepoRoot 'resources\views') -Destination (Join-Path $Stage 'resources\views') -Recurse -Force
if (Test-Path (Join-Path $RepoRoot 'resources\lang')) {
    Copy-Item -LiteralPath (Join-Path $RepoRoot 'resources\lang') -Destination (Join-Path $Stage 'resources\lang') -Recurse -Force
}

# storage skeleton (only used when ../shared is absent; cron.sh creates the shared one)
$skeleton = @('app\private', 'app\public', 'framework\cache\data', 'framework\sessions', 'framework\views', 'logs')
foreach ($dir in $skeleton) {
    $p = Join-Path $Stage "storage\$dir"
    New-Item -ItemType Directory -Path $p -Force | Out-Null
    Write-Utf8NoBom (Join-Path $p '.gitignore') "*`n!.gitignore`n"
}

# --- 5. composer -----------------------------------------------------------
Write-Step 'composer install --no-dev --optimize-autoloader'
Push-Location $Stage
try {
    & $Php $ComposerPhar install --no-dev --optimize-autoloader --no-interaction
    Assert-LastExit 'composer install'
} finally {
    Pop-Location
}

# --- 6. clean up local-only files -----------------------------------------
Write-Step 'Removing local-only files'
Get-ChildItem -LiteralPath (Join-Path $Stage 'bootstrap\cache') -Filter '*.php' -Force -ErrorAction SilentlyContinue | Remove-Item -Force
Get-ChildItem -LiteralPath $Stage -Recurse -Force -File -Filter '.env*' | Remove-Item -Force
Get-ChildItem -LiteralPath (Join-Path $Stage 'database') -Recurse -Force -File -Filter '*.sqlite*' -ErrorAction SilentlyContinue | Remove-Item -Force
foreach ($rel in @('public\hot', 'public\storage')) {
    $p = Join-Path $Stage $rel
    if (Test-Path $p) { Remove-Item -LiteralPath $p -Recurse -Force }
}
# composer sometimes installs a package from source, leaving a .git directory in vendor: not needed at runtime
Get-ChildItem -LiteralPath (Join-Path $Stage 'vendor') -Recurse -Force -Directory -Filter '.git' -ErrorAction SilentlyContinue |
    Sort-Object { $_.FullName.Length } -Descending | ForEach-Object { Remove-Item -LiteralPath $_.FullName -Recurse -Force }
# the uploader treats a package.json as a Node app: it must never be in the staging directory
Get-ChildItem -LiteralPath $Stage -Recurse -Force -File -Filter 'package.json' |
    Where-Object { $_.FullName -notmatch '\\vendor\\' } | Remove-Item -Force

# cron.sh runs under /bin/sh on Linux: force LF line endings
$cron = Join-Path $Stage 'deploy\hostinger\cron.sh'
$text = [System.IO.File]::ReadAllText($cron) -replace "`r`n", "`n"
Write-Utf8NoBom $cron $text

# --- 7. release id ---------------------------------------------------------
$releaseId = '{0}-{1}' -f $sha, [DateTime]::UtcNow.ToString('yyyyMMdd\THHmmss\Z')
Write-Utf8NoBom (Join-Path $Stage 'deploy\hostinger\release.txt') ($releaseId + "`n")

# --- 7b. manifest: lets cron.sh tell a complete upload from a half-finished one ---
# "release <id>" then the relative path (forward slashes, LF) of EVERY staged file.
# Left out: the manifest and release.txt themselves, and what the uploader is known to
# skip or what is not needed (.env*, .git, node_modules, the storage skeleton, which
# cron.sh recreates under ../shared).
$manifestLines = New-Object System.Collections.ArrayList
[void]$manifestLines.Add("release $releaseId")
$stagePrefix = $Stage.TrimEnd('\') + '\'
$paths = Get-ChildItem -LiteralPath $Stage -Recurse -Force -File | ForEach-Object {
    $_.FullName.Substring($stagePrefix.Length).Replace('\', '/')
}
foreach ($rel in ($paths | Sort-Object)) {
    if ($rel -eq 'deploy/hostinger/release.txt' -or $rel -eq 'deploy/hostinger/release.manifest') { continue }
    if ($rel -like 'storage/*') { continue }
    if ($rel -match '(^|/)(node_modules|\.git)/') { continue }
    if ($rel -match '(^|/)\.env[^/]*$') { continue }
    [void]$manifestLines.Add($rel)
}
Write-Utf8NoBom (Join-Path $Stage 'deploy\hostinger\release.manifest') (($manifestLines -join "`n") + "`n")
# --- 8. sanity checks ------------------------------------------------------
foreach ($forbidden in @('.env', 'package.json', 'node_modules', 'vendor\bin\pest')) {
    if (Test-Path (Join-Path $Stage $forbidden)) { throw "Staging contains $forbidden." }
}
foreach ($required in @('.htaccess', 'artisan', 'vendor\autoload.php', 'public\index.php', 'public\build\manifest.json', 'deploy\hostinger\cron.sh', 'deploy\hostinger\release.txt', 'deploy\hostinger\release.manifest')) {
    if (-not (Test-Path (Join-Path $Stage $required))) { throw "Staging is missing $required." }
}

$count = (Get-ChildItem -LiteralPath $Stage -Recurse -Force -File | Measure-Object).Count
Write-Host ''
Write-Host "Release id : $releaseId" -ForegroundColor Green
Write-Host "Staging dir: $Stage" -ForegroundColor Green
Write-Host "Files      : $count" -ForegroundColor Green
Write-Host 'Next: upload this directory to the site document root (see docs/deploy.md).'
