#!/usr/bin/env bash
# Linux twin of scripts/deploy.ps1 for GitHub Actions (.github/workflows/deploy.yml).
#
# Builds the same staging directory (build/deploy) that deploy.ps1 builds: only what the server
# needs, vendor/ without dev packages, release.txt and release.manifest for cron.sh.
# It does NOT run the tests (the workflow runs them first), does NOT build the front end
# (the workflow runs `npm run build` first; public/build must exist), never stages an env seed
# (secrets never go through CI) and uploads nothing.
#
#   scripts/deploy-stage.sh            (run from anywhere; uses the repository it lives in)
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
STAGE="$REPO_ROOT/build/deploy"
cd "$REPO_ROOT"

step() { printf '\n==> %s\n' "$1"; }
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }

# --- 1. clean working tree (the build may only add git-ignored files) -------
step 'Checking git state'
if [ -n "$(git status --porcelain)" ]; then
    git status --porcelain
    fail 'Uncommitted changes in the checkout. release.txt must match a commit.'
fi
SHA="$(git rev-parse --short HEAD)"

[ -f public/build/manifest.json ] || fail 'public/build/manifest.json missing: run npm run build first.'

# --- 2. staging directory ---------------------------------------------------
step "Preparing $STAGE"
rm -rf "$STAGE"
mkdir -p "$STAGE"

for item in app bootstrap config database public routes deploy artisan composer.json composer.lock .htaccess; do
    [ -e "$item" ] || fail "Expected $item in the repository root."
    cp -R "$item" "$STAGE/"
done
[ -e lang ] && cp -R lang "$STAGE/"

# resources: only the Blade views (js/css are compiled into public/build)
mkdir -p "$STAGE/resources"
cp -R resources/views "$STAGE/resources/views"
[ -e resources/lang ] && cp -R resources/lang "$STAGE/resources/lang"

# storage skeleton (only used when ../shared is absent; cron.sh creates the shared one)
for dir in app/private app/public framework/cache/data framework/sessions framework/views logs; do
    mkdir -p "$STAGE/storage/$dir"
    printf '*\n!.gitignore\n' > "$STAGE/storage/$dir/.gitignore"
done

# --- 3. composer ------------------------------------------------------------
step 'composer install --no-dev --optimize-autoloader'
(cd "$STAGE" && composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist)

# --- 4. clean up local-only files ------------------------------------------
step 'Removing local-only files'
rm -f "$STAGE"/bootstrap/cache/*.php
find "$STAGE" -type f -name '.env*' -delete
find "$STAGE/database" -type f -name '*.sqlite*' -delete
rm -rf "$STAGE/public/hot" "$STAGE/public/storage"
# composer sometimes installs a package from source, leaving a .git directory in vendor
find "$STAGE/vendor" -depth -type d -name .git -exec rm -rf {} +
# the uploader treats a package.json as a Node app: it must never be in the staging directory
find "$STAGE" -type f -name package.json -not -path "$STAGE/vendor/*" -delete

# The uploader leaves out whatever a .gitignore matches, and vendor packages ship their own
# .gitignore files. A .hostingerignore replaces those rules; this one ignores nothing that the
# release needs (the manifest check in the workflow and in cron.sh proves it).
printf '# Upload everything in this staging directory.\nnode_modules/\n' > "$STAGE/.hostingerignore"

# cron.sh runs under /bin/sh: force LF line endings
sed -i 's/\r$//' "$STAGE/deploy/hostinger/cron.sh"

# --- 5. release id ----------------------------------------------------------
RELEASE_ID="$SHA-$(date -u +%Y%m%dT%H%M%SZ)"
printf '%s\n' "$RELEASE_ID" > "$STAGE/deploy/hostinger/release.txt"

# --- 6. manifest (same rules as deploy.ps1) ----------------------------------
{
    printf 'release %s\n' "$RELEASE_ID"
    (cd "$STAGE" && find . -type f | sed 's|^\./||' | LC_ALL=C sort) | grep -v -E \
        -e '^deploy/hostinger/(release\.txt|release\.manifest|env\.seed)$' \
        -e '^deploy/hostinger/status\.' \
        -e '^storage/' \
        -e '(^|/)(node_modules|\.git)/' \
        -e '(^|/)\.env[^/]*$' \
        -e '^\.hostingerignore$' || true
} > "$STAGE/deploy/hostinger/release.manifest"

# --- 7. sanity checks -------------------------------------------------------
for forbidden in .env package.json node_modules vendor/bin/pest; do
    [ ! -e "$STAGE/$forbidden" ] || fail "Staging contains $forbidden."
done
for required in .htaccess artisan vendor/autoload.php public/index.php public/build/manifest.json \
    deploy/hostinger/cron.sh deploy/hostinger/release.txt deploy/hostinger/release.manifest; do
    [ -e "$STAGE/$required" ] || fail "Staging is missing $required."
done

COUNT="$(find "$STAGE" -type f | wc -l)"
printf '\nRelease id : %s\nStaging dir: %s\nFiles      : %s\n' "$RELEASE_ID" "$STAGE" "$COUNT"
if [ -n "${GITHUB_OUTPUT:-}" ]; then
    printf 'release_id=%s\n' "$RELEASE_ID" >> "$GITHUB_OUTPUT"
fi
