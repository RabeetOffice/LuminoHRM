<?php

/**
 * Runs deploy/hostinger/cron.sh against a throw-away layout:
 *   <tmp>/domains/site/public_html/{artisan stub, deploy/hostinger/cron.sh}
 *   <tmp>/domains/site/shared   (created by the script)
 * PHP_BIN points at a stub that records the artisan commands it is given.
 */
function shAvailable(): bool
{
    $out = [];
    $code = 1;
    @exec('sh -c "exit 0" 2>&1', $out, $code);

    return $code === 0;
}

function cronLayout(): array
{
    $root = str_replace('\\', '/', sys_get_temp_dir()).'/lumino-cron-'.bin2hex(random_bytes(4));
    $app = $root.'/domains/site/public_html';
    mkdir($app.'/deploy/hostinger', 0777, true);
    copy(base_path('deploy/hostinger/cron.sh'), $app.'/deploy/hostinger/cron.sh');
    file_put_contents($app.'/artisan', "<?php // stub\n");

    // Stub "php": logs the artisan command per call; fails when FAIL_ON equals it.
    $stub = $root.'/fakephp.sh';
    $script = <<<'SH'
#!/bin/sh
if [ "$1" = "-r" ]; then echo "8.4.0-stub yes"; exit 0; fi
shift
echo "$*" >> "$STUB_CALLS"
if [ "$1" = "key:generate" ]; then
    sed 's/^APP_KEY=.*/APP_KEY=base64:stubstubstub/' "$STUB_ENV" > "$STUB_ENV.new" && mv "$STUB_ENV.new" "$STUB_ENV"
fi
if [ "$1" = "$FAIL_ON" ]; then
    echo "boom from $1"
    exit 1
fi
if [ "$1" = "hrm:sync-access" ]; then
    echo "access_sync roles=4 permissions=25 admin=${STUB_ADMIN:-created}"
    exit 0
fi
echo "stub ok: $1"

SH;
    file_put_contents($stub, $script);
    chmod($stub, 0755);

    return [$root, $app, $root.'/domains/site/shared', $stub];
}

function runCron(string $app, string $stub, string $shared, array $env = []): int
{
    $vars = array_merge(getenv(), [
        'PHP_BIN' => $stub,
        'STUB_CALLS' => $shared.'/calls.txt',
        'STUB_ENV' => $shared.'/.env',
        'CONFIRM_RUNS' => '1',
    ], $env);

    $proc = proc_open(
        ['sh', $app.'/deploy/hostinger/cron.sh'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        $vars,
    );
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return proc_close($proc);
}

/** The files a staged release consists of (relative to the app root). */
const RELEASE_FILES = [
    'artisan',
    'deploy/hostinger/cron.sh',
    'database/migrations/m.php',
    'resources/views/my view.blade.php',
    'vendor/symfony/deep/pkg/late.php',
];

/** Writes release.txt plus a manifest listing every file; $late=false leaves one file out of the tree. */
function writeRelease(string $app, string $id, bool $late = true, bool $crlf = false): void
{
    foreach (RELEASE_FILES as $rel) {
        if ($rel === 'vendor/symfony/deep/pkg/late.php' && ! $late) {
            continue;
        }
        if (! is_file($app.'/'.$rel)) {
            @mkdir(dirname($app.'/'.$rel), 0777, true);
            file_put_contents($app.'/'.$rel, "x\n");
        }
    }
    if ($late) {
        @mkdir($app.'/vendor/symfony/deep/pkg', 0777, true);
        file_put_contents($app.'/vendor/symfony/deep/pkg/late.php', "x\n");
    } else {
        @unlink($app.'/vendor/symfony/deep/pkg/late.php');
    }
    $nl = $crlf ? "\r\n" : "\n";
    file_put_contents($app.'/deploy/hostinger/release.txt', $id."\n");
    file_put_contents(
        $app.'/deploy/hostinger/release.manifest',
        "release $id$nl".implode($nl, array_slice(RELEASE_FILES, 0, 3)).$nl.$nl.implode($nl, array_slice(RELEASE_FILES, 3)).$nl,
    );
}
function cronLog(string $shared): string
{
    return file_get_contents($shared.'/storage/logs/cron.log');
}

function cronCalls(string $shared): array
{
    $f = $shared.'/calls.txt';

    return is_file($f) ? array_values(array_filter(explode("\n", file_get_contents($f)))) : [];
}

function wipeDir(string $dir): void
{
    foreach (scandir($dir) as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        is_dir("$dir/$e") ? wipeDir("$dir/$e") : unlink("$dir/$e");
    }
    rmdir($dir);
}

beforeEach(function () {
    if (! shAvailable()) {
        $this->markTestSkipped('sh is not available');
    }
    [$this->tmpRoot, $this->appDir, $this->sharedDir, $this->stub] = cronLayout();
});

afterEach(function () {
    if (isset($this->tmpRoot) && is_dir($this->tmpRoot)) {
        wipeDir($this->tmpRoot);
    }
});

it('creates the shared layout and waits for shared/.env', function () {
    expect(runCron($this->appDir, $this->stub, $this->sharedDir))->toBe(0);

    foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $d) {
        expect(is_dir($this->sharedDir.'/storage/'.$d))->toBeTrue("missing $d");
    }
    expect(cronCalls($this->sharedDir))->toBe([])
        ->and(file_get_contents($this->sharedDir.'/storage/logs/cron.log'))->toContain('waiting for shared/.env');
});

it('generates the key when empty and runs the post-deploy commands once per release', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir); // creates layout
    file_put_contents($this->sharedDir.'/.env', "APP_NAME=x\nAPP_KEY=\nDB_PASSWORD=supersecret\n");
    writeRelease($this->appDir, 'abc123-20261123T100000Z');

    expect(runCron($this->appDir, $this->stub, $this->sharedDir))->toBe(0);

    expect(cronCalls($this->sharedDir))->toBe([
        'key:generate --force',
        'config:clear',
        'migrate --force',
        'hrm:sync-access',
        'config:cache',
        'route:cache',
        'view:cache',
        'schedule:run',
    ])->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('abc123-20261123T100000Z');

    // same release again: only the scheduler runs
    unlink($this->sharedDir.'/calls.txt');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->toBe(['schedule:run']);

    // never leaks env values into the log
    expect(file_get_contents($this->sharedDir.'/storage/logs/cron.log'))->not->toContain('supersecret');
});

it('does not regenerate an existing key', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:alreadyset\n");
    writeRelease($this->appDir, 'r1');

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->not->toContain('key:generate --force');
});

it('runs the post-deploy commands again for a new release id', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    unlink($this->sharedDir.'/calls.txt');

    writeRelease($this->appDir, 'r2');
    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'hrm:sync-access', 'config:cache', 'route:cache', 'view:cache', 'schedule:run'])
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r2');
});

it('leaves release.done unwritten when a step fails, logs the output and retries', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    writeRelease($this->appDir, 'r1');

    runCron($this->appDir, $this->stub, $this->sharedDir, ['FAIL_ON' => 'route:cache']);

    expect(is_file($this->sharedDir.'/release.done'))->toBeFalse()
        ->and(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'hrm:sync-access', 'config:cache', 'route:cache'])
        ->and(file_get_contents($this->sharedDir.'/storage/logs/cron.log'))->toContain('boom from route:cache');

    // next minute, the fault is gone: the whole sequence runs again and succeeds
    unlink($this->sharedDir.'/calls.txt');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'hrm:sync-access', 'config:cache', 'route:cache', 'view:cache', 'schedule:run'])
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r1');
});

it('does not run while another run holds a live lock, and recovers from a stale one', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    writeRelease($this->appDir, 'r1');

    // live lock: the PID of a process that is really running (as seen by sh)
    $sleeper = proc_open(['sh', '-c', 'echo $$; exec sleep 30'], [1 => ['pipe', 'w']], $pipes);
    $livePid = trim(fgets($pipes[1]));
    mkdir($this->sharedDir.'/cron.lock');
    file_put_contents($this->sharedDir.'/cron.lock/pid', $livePid);
    runCron($this->appDir, $this->stub, $this->sharedDir);
    proc_terminate($sleeper);
    expect(cronCalls($this->sharedDir))->toBe([]);

    // stale lock: PID that does not exist
    file_put_contents($this->sharedDir.'/cron.lock/pid', '999999');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->not->toBe([])
        ->and(is_dir($this->sharedDir.'/cron.lock'))->toBeFalse();
});

it('rotates cron.log when it grows past the limit', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/storage/logs/cron.log', str_repeat("x\n", 600000)); // ~1.2MB

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(is_file($this->sharedDir.'/storage/logs/cron.log.1'))->toBeTrue()
        ->and(filesize($this->sharedDir.'/storage/logs/cron.log'))->toBeLessThan(10000);
});

function readyEnv(object $t): void
{
    runCron($t->appDir, $t->stub, $t->sharedDir);
    file_put_contents($t->sharedDir.'/.env', "APP_KEY=base64:x\n");
}

const FULL_RUN = ['config:clear', 'migrate --force', 'hrm:sync-access', 'config:cache', 'route:cache', 'view:cache', 'schedule:run'];

it('does not trigger while one late file is missing and reports the missing count', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r9', late: false);

    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);
    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);

    expect(cronCalls($this->sharedDir))->toBe([])
        ->and(is_file($this->sharedDir.'/release.done'))->toBeFalse()
        ->and(cronLog($this->sharedDir))->toContain('upload still in progress: 1 of 5 files missing, first: vendor/symfony/deep/pkg/late.php');
});

it('needs two consecutive complete runs, triggers exactly once, then only schedules', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r9');

    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);
    expect(cronCalls($this->sharedDir))->toBe([])
        ->and(cronLog($this->sharedDir))->toContain('all 5 files present, confirming (1 of 2 runs)');

    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);
    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN)
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r9')
        ->and(is_file($this->sharedDir.'/release.pending'))->toBeFalse();

    unlink($this->sharedDir.'/calls.txt');
    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);
    expect(cronCalls($this->sharedDir))->toBe(['schedule:run']);
});

it('treats a CRLF manifest like an LF one, including paths with spaces', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r9', crlf: true);

    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);
    runCron($this->appDir, $this->stub, $this->sharedDir, ['CONFIRM_RUNS' => '2']);

    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN);
});

it('resets the confirmation count when the release id changes or the tree is seen incomplete', function () {
    readyEnv($this);
    $two = ['CONFIRM_RUNS' => '2'];

    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir, $two);
    expect(trim(file_get_contents($this->sharedDir.'/release.pending')))->toBe('r1 1');

    // release id changes mid-wait: count restarts for r2
    writeRelease($this->appDir, 'r2');
    runCron($this->appDir, $this->stub, $this->sharedDir, $two);
    expect(trim(file_get_contents($this->sharedDir.'/release.pending')))->toBe('r2 1')
        ->and(cronCalls($this->sharedDir))->toBe([]);

    // an incomplete observation clears the count
    writeRelease($this->appDir, 'r2', late: false);
    runCron($this->appDir, $this->stub, $this->sharedDir, $two);
    expect(is_file($this->sharedDir.'/release.pending'))->toBeFalse();

    // complete again: only the first of two confirmations
    writeRelease($this->appDir, 'r2');
    runCron($this->appDir, $this->stub, $this->sharedDir, $two);
    expect(cronCalls($this->sharedDir))->toBe([]);

    runCron($this->appDir, $this->stub, $this->sharedDir, $two);
    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN)
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r2');
});

it('never triggers a new release without a manifest or with a manifest for another release', function () {
    readyEnv($this);

    writeRelease($this->appDir, 'r1');
    unlink($this->appDir.'/deploy/hostinger/release.manifest');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronLog($this->sharedDir))->toContain('release.manifest is missing');

    writeRelease($this->appDir, 'r1');
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r2\n");
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronLog($this->sharedDir))->toContain("release.manifest names 'r1', release.txt names 'r2'");

    expect(cronCalls($this->sharedDir))->toBe([])
        ->and(is_file($this->sharedDir.'/release.done'))->toBeFalse();
});

it('never consults the manifest for an already-completed release (scheduler and .env rebuild keep working)', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r1');
    unlink($this->sharedDir.'/calls.txt');

    // manifest gone and a listed file deleted: irrelevant for the done release
    unlink($this->appDir.'/deploy/hostinger/release.manifest');
    unlink($this->appDir.'/vendor/symfony/deep/pkg/late.php');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->toBe(['schedule:run']);

    // .env edited afterwards: config rebuilt without any manifest
    unlink($this->sharedDir.'/calls.txt');
    touch($this->sharedDir.'/.env', time() + 30);
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN);
});

it('normalises APP_KEY="" before generating a key and removes stale output files', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_NAME=x\nAPP_KEY=\"\"\n");
    file_put_contents($this->sharedDir.'/cron.out.4242', 'stale');

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(file_get_contents($this->sharedDir.'/.env'))->toContain("APP_KEY=base64:stubstubstub\n")->not->toContain('""')
        ->and(is_file($this->sharedDir.'/cron.out.4242'))->toBeFalse();
});

it('parses the whole script up front by running everything from main()', function () {
    $lines = file(base_path('deploy/hostinger/cron.sh'), FILE_IGNORE_NEW_LINES);
    $nonEmpty = array_values(array_filter($lines, fn ($l) => trim($l) !== '' && ! str_starts_with(ltrim($l), '#')));

    expect(array_slice($nonEmpty, -2))->toBe(['main "$@"', 'exit $?'])
        ->and(implode("\n", $lines))->toContain('main() {');
});

/** Stages a seed the way deploy.ps1 does: content + "# seed-end <release id>" (id of release.txt). */
function writeSeed(string $app, string $content, ?string $markerId = null, bool $marker = true): void
{
    $txt = $app.'/deploy/hostinger/release.txt';
    if (! is_file($txt)) {
        file_put_contents($txt, "rseed\n");
    }
    $id = $markerId ?? trim(file_get_contents($txt));
    file_put_contents($app.'/deploy/hostinger/env.seed', $content.($marker ? "# seed-end $id\n" : ''));
}

function seedTemps(string $shared): array
{
    return array_merge(glob($shared.'/.env.seed-tmp.*') ?: [], glob($shared.'/.env.tmp.*') ?: []);
}

function readStatus(string $app): array
{
    $out = [];
    foreach (explode("\n", trim(file_get_contents($app.'/deploy/hostinger/status.txt'))) as $line) {
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $out[$k] = $v;
    }

    return $out;
}

it('installs the env seed as shared/.env, removes the seed and logs without values', function () {
    writeSeed($this->appDir, "APP_NAME=Seeded\nAPP_KEY=base64:seedkey\nDB_PASSWORD=FAKEPASS123\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(file_get_contents($this->sharedDir.'/.env'))->toBe("APP_NAME=Seeded\nAPP_KEY=base64:seedkey\nDB_PASSWORD=FAKEPASS123\n")
        ->and(is_file($this->appDir.'/deploy/hostinger/env.seed'))->toBeFalse()
        ->and(cronLog($this->sharedDir))->toContain('installed shared/.env from seed')
        ->not->toContain('FAKEPASS123')->not->toContain('seedkey');

    if (PHP_OS_FAMILY !== 'Windows') {
        expect(substr(sprintf('%o', fileperms($this->sharedDir.'/.env')), -3))->toBe('600');
    }
});

it('strips carriage returns from a CRLF seed', function () {
    writeSeed($this->appDir, "APP_NAME=Seeded\r\nAPP_KEY=base64:k\r\nDB_PASSWORD=p\r\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(file_get_contents($this->sharedDir.'/.env'))->toBe("APP_NAME=Seeded\nAPP_KEY=base64:k\nDB_PASSWORD=p\n");
});

it('keeps the existing APP_KEY when the seed has an empty one', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:oldkey/+=\nDB_PASSWORD=old\n");
    writeSeed($this->appDir, "APP_NAME=New\nAPP_KEY=\nDB_PASSWORD=newpass\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    $env = file_get_contents($this->sharedDir.'/.env');
    expect($env)->toContain("APP_KEY=base64:oldkey/+=\n")->toContain('DB_PASSWORD=newpass')->toContain('APP_NAME=New')
        ->not->toContain('DB_PASSWORD=old')
        ->and(substr_count($env, 'APP_KEY='))->toBe(1)
        ->and(cronCalls($this->sharedDir))->not->toContain('key:generate --force');
});

it('lets a non-empty seed APP_KEY replace the old key', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:oldkey\n");
    writeSeed($this->appDir, "APP_KEY=base64:newkey\nDB_PASSWORD=p\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(file_get_contents($this->sharedDir.'/.env'))->toBe("APP_KEY=base64:newkey\nDB_PASSWORD=p\n");
});

it('rebuilds the config cache in the same run that installs a new seed', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r1');
    unlink($this->sharedDir.'/calls.txt');

    writeSeed($this->appDir, "APP_KEY=base64:k2\nHRM_DEVICE_SERIALS=SN1\nDB_PASSWORD=p\n");
    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN)
        ->and(file_get_contents($this->sharedDir.'/.env'))->toContain('HRM_DEVICE_SERIALS=SN1');
});

it('writes status.txt for waiting_for_env, upload_in_progress and ok', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    $s = readStatus($this->appDir);
    expect($s['state'])->toBe('waiting_for_env')
        ->and($s['detail'])->toBe('shared/.env not created yet')
        ->and($s['env_present'])->toBe('no')->and($s['app_key_set'])->toBe('no')
        ->and($s['php'])->toBe('8.4.0-stub')->and($s['proc_open'])->toBe('yes')
        ->and($s['last_migrate'])->toBe('')
        ->and($s['time'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/');

    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    writeRelease($this->appDir, 'r5', late: false);
    runCron($this->appDir, $this->stub, $this->sharedDir);
    $s = readStatus($this->appDir);
    expect($s['state'])->toBe('upload_in_progress')
        ->and($s['detail'])->toBe('1 of 5 files missing, first: vendor/symfony/deep/pkg/late.php')
        ->and($s['release'])->toBe('r5')->and($s['release_done'])->toBe('')
        ->and($s['env_present'])->toBe('yes')->and($s['app_key_set'])->toBe('yes');

    writeRelease($this->appDir, 'r5');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    $s = readStatus($this->appDir);
    expect($s['state'])->toBe('ok')
        ->and($s['release_done'])->toBe('r5')
        ->and($s['detail'])->toBe('release r5 done')
        ->and($s['last_migrate'])->toMatch('/^\d{4}-/');
});

it('reports post_deploy_failed with the failing command name only', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r6');

    runCron($this->appDir, $this->stub, $this->sharedDir, ['FAIL_ON' => 'migrate']);

    $s = readStatus($this->appDir);
    expect($s['state'])->toBe('post_deploy_failed')
        ->and($s['detail'])->toBe('artisan migrate --force failed (release r6)')
        ->and(file_get_contents($this->appDir.'/deploy/hostinger/status.txt'))->not->toContain('boom');
});

it('never leaks env values into status.txt, status.log or cron.log, and status.log has only own lines', function () {
    writeRelease($this->appDir, 'r7');
    writeSeed($this->appDir, "APP_KEY=base64:seedkey\nDB_PASSWORD=FAKEPASS123\n");

    runCron($this->appDir, $this->stub, $this->sharedDir, ['FAIL_ON' => 'config:cache']);   // artisan output ("boom ...") lands in cron.log
    runCron($this->appDir, $this->stub, $this->sharedDir);

    foreach (['status.txt', 'status.log'] as $f) {
        $text = file_get_contents($this->appDir.'/deploy/hostinger/'.$f);
        expect($text)->not->toContain('FAKEPASS123')->not->toContain('seedkey');
    }
    expect(cronLog($this->sharedDir))->not->toContain('FAKEPASS123')->not->toContain('seedkey');

    $log = file_get_contents($this->appDir.'/deploy/hostinger/status.log');
    expect($log)->toContain('installed shared/.env from seed')->not->toContain('boom')->not->toContain('    |');
    foreach (array_filter(explode("\n", $log)) as $line) {
        expect($line)->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ /');
    }
    expect(count(array_filter(explode("\n", $log))))->toBeLessThanOrEqual(40);
});

it('does not touch lookalike keys: OLD_APP_KEY stays, a commented APP_KEY is ignored', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "# APP_KEY=base64:commented\nOLD_APP_KEY=base64:previous\nAPP_KEY=base64:realold\n");
    writeSeed($this->appDir, "APP_KEY=\nDB_PASSWORD=p\nOLD_APP_KEY=base64:fromseed\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    $env = file_get_contents($this->sharedDir.'/.env');
    expect($env)->toContain("OLD_APP_KEY=base64:fromseed\n")->toContain("APP_KEY=base64:realold\n")
        ->not->toContain('commented')->not->toContain('previous')
        ->and(preg_match_all('/^APP_KEY=/m', $env))->toBe(1);

    // a commented APP_KEY in the *current* env must not count as "has a key"
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "# APP_KEY=base64:onlycomment\nDB_PASSWORD=old\n");
    writeSeed($this->appDir, "APP_KEY=\nDB_PASSWORD=p2\n");
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(file_get_contents($this->sharedDir.'/.env'))->not->toContain('onlycomment')->toContain('DB_PASSWORD=p2');
});

it('rejects invalid seeds and keeps the previous good .env', function (string $name, string $seed, ?string $markerId, bool $marker) {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    $good = "APP_KEY=base64:good\nDB_PASSWORD=goodpass\n";
    file_put_contents($this->sharedDir.'/.env', $good);
    writeRelease($this->appDir, 'r1');
    writeSeed($this->appDir, $seed, $markerId, $marker);

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(file_get_contents($this->sharedDir.'/.env'))->toBe($good, $name)
        ->and(is_file($this->appDir.'/deploy/hostinger/env.seed'))->toBeTrue('seed must stay in place')
        ->and(cronLog($this->sharedDir))->toContain('seed incomplete or invalid, waiting')
        ->and(readStatus($this->appDir)['detail'])->toContain('seed incomplete or invalid, waiting')
        ->and(seedTemps($this->sharedDir))->toBe([]);
})->with([
    'empty seed' => ['empty', '', null, false],
    'empty seed with marker' => ['empty+marker', '', null, true],
    'no end marker (truncated upload)' => ['no marker', "APP_KEY=base64:x\nDB_PASSWORD=p\n", null, false],
    'marker id mismatch' => ['id mismatch', "APP_KEY=base64:x\nDB_PASSWORD=p\n", 'some-other-release', true],
    'no DB_PASSWORD' => ['no password', "APP_KEY=base64:x\nAPP_NAME=y\n", null, true],
    'empty DB_PASSWORD' => ['empty password', "APP_KEY=base64:x\nDB_PASSWORD=\n", null, true],
    'placeholder DB_PASSWORD' => ['placeholder', "APP_KEY=base64:x\nDB_PASSWORD=__DB_PASSWORD__\n", null, true],
]);

it('installs a valid seed without the marker line, then picks up a corrected seed after an invalid one', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    writeRelease($this->appDir, 'r1');
    writeSeed($this->appDir, "APP_KEY=base64:x\nDB_PASSWORD=p\n", null, false);   // still uploading
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(is_file($this->sharedDir.'/.env'))->toBeFalse()
        ->and(readStatus($this->appDir)['state'])->toBe('waiting_for_env')
        ->and(readStatus($this->appDir)['detail'])->toBe('shared/.env not created yet; seed incomplete or invalid, waiting');

    writeSeed($this->appDir, "APP_KEY=base64:x\nDB_PASSWORD=p\n");                // upload finished
    runCron($this->appDir, $this->stub, $this->sharedDir);

    $env = file_get_contents($this->sharedDir.'/.env');
    expect($env)->toBe("APP_KEY=base64:x\nDB_PASSWORD=p\n")->not->toContain('seed-end')
        ->and(is_file($this->appDir.'/deploy/hostinger/env.seed'))->toBeFalse()
        ->and(seedTemps($this->sharedDir))->toBe([]);
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(substr(sprintf('%o', fileperms($this->sharedDir.'/.env')), -3))->toBe('600');
    }
});

it('removes leftover temp files of killed runs at startup', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env.seed-tmp.4242', 'secret');
    file_put_contents($this->sharedDir.'/.env.seed-tmp.4242.2', 'secret');
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->sharedDir.'/.env.tmp.4242', 'secret');

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(seedTemps($this->sharedDir))->toBe([]);
});

it('creates shared/ private (modes are only meaningful off Windows)', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('file modes are not meaningful on Windows');
    }
    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(substr(sprintf('%o', fileperms($this->sharedDir)), -3))->toBe('700');
});

it('runs hrm:sync-access right after migrate and publishes its sanitised line as access= in status.txt', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->toBe(FULL_RUN)
        ->and(readStatus($this->appDir)['access'])->toBe('access_sync roles=4 permissions=25 admin=created')
        ->and(cronLog($this->sharedDir))->toContain('access sync: access_sync roles=4 permissions=25 admin=created');

    // the value persists across runs that do not run the post-deploy step
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(readStatus($this->appDir)['access'])->toBe('access_sync roles=4 permissions=25 admin=created');
});

it('drops anything outside lowercase letters, digits, space, = and _ and truncates the access line to 120 chars', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir, ['STUB_ADMIN' => 'Boss@Example.com-'.str_repeat('a', 200)]);

    $line = readStatus($this->appDir)['access'];
    expect($line)->toMatch('/^[a-z0-9 =_]+$/')
        ->and(strlen($line))->toBe(120)
        ->and($line)->not->toContain('@');
});

it('treats a failing hrm:sync-access as a post-deploy failure and retries', function () {
    readyEnv($this);
    writeRelease($this->appDir, 'r1');
    runCron($this->appDir, $this->stub, $this->sharedDir, ['FAIL_ON' => 'hrm:sync-access']);

    expect(is_file($this->sharedDir.'/release.done'))->toBeFalse()
        ->and(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'hrm:sync-access'])
        ->and(readStatus($this->appDir)['state'])->toBe('post_deploy_failed');
});

function backupStatusFile(string $shared): string
{
    return $shared.'/storage/app/private/backup-status/last-backup.json';
}

function runCronOk(object $t): array
{
    @mkdir($t->sharedDir, 0777, true);
    file_put_contents($t->sharedDir.'/.env', "APP_KEY=base64:x\n");
    writeRelease($t->appDir, 'r9');
    runCron($t->appDir, $t->stub, $t->sharedDir);

    return readStatus($t->appDir);
}

it('reports last_backup as empty and last_backup_ok as unknown when no backup has run', function () {
    $s = runCronOk($this);
    expect($s['last_backup'])->toBe('')->and($s['last_backup_ok'])->toBe('unknown');
});

it('reports the last backup time and result from last-backup.json', function () {
    mkdir(dirname(backupStatusFile($this->sharedDir)), 0777, true);
    file_put_contents(backupStatusFile($this->sharedDir), '{"file":"lumino-hrm-2026-11-24.sql.gz","bytes":12345,"finished_at":"2026-11-24T02:00:07+05:00","ok":true}');
    $s = runCronOk($this);
    expect($s['last_backup'])->toBe('2026-11-24T02:00:07+05:00')->and($s['last_backup_ok'])->toBe('yes');

    file_put_contents(backupStatusFile($this->sharedDir), "{\n  \"file\": \"\",\n  \"bytes\": 0,\n  \"finished_at\": \"2026-11-25T02:00:03+05:00\",\n  \"ok\": false\n}\n");
    $s = runCronOk($this);
    expect($s['last_backup'])->toBe('2026-11-25T02:00:03+05:00')->and($s['last_backup_ok'])->toBe('no');
});

it('never fails or injects anything when last-backup.json is malformed', function () {
    mkdir(dirname(backupStatusFile($this->sharedDir)), 0777, true);
    foreach (['not json at all', '', '{"finished_at":"2026\nINJECT=1 $(rm -rf x) `x`;<b>","ok":maybe}', "\x00\xFF\xFE"] as $junk) {
        file_put_contents(backupStatusFile($this->sharedDir), $junk);
        $s = runCronOk($this);
        expect($s['state'])->toBe('ok');
        expect($s['last_backup'])->toMatch('/^[0-9A-Za-z:+.-]*$/')->and($s['last_backup_ok'])->toBeIn(['yes', 'no', 'unknown']);
        expect($s)->not->toHaveKey('INJECT');
    }
});
