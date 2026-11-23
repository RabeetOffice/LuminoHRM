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
shift
echo "$*" >> "$STUB_CALLS"
if [ "$1" = "key:generate" ]; then
    sed 's/^APP_KEY=.*/APP_KEY=base64:stubstubstub/' "$STUB_ENV" > "$STUB_ENV.new" && mv "$STUB_ENV.new" "$STUB_ENV"
fi
if [ "$1" = "$FAIL_ON" ]; then
    echo "boom from $1"
    exit 1
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
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "abc123-20261123T100000Z\n");

    expect(runCron($this->appDir, $this->stub, $this->sharedDir))->toBe(0);

    expect(cronCalls($this->sharedDir))->toBe([
        'key:generate --force',
        'config:clear',
        'migrate --force',
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
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r1\n");

    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->not->toContain('key:generate --force');
});

it('runs the post-deploy commands again for a new release id', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r1\n");
    runCron($this->appDir, $this->stub, $this->sharedDir);
    unlink($this->sharedDir.'/calls.txt');

    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r2\n");
    runCron($this->appDir, $this->stub, $this->sharedDir);

    expect(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'config:cache', 'route:cache', 'view:cache', 'schedule:run'])
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r2');
});

it('leaves release.done unwritten when a step fails, logs the output and retries', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r1\n");

    runCron($this->appDir, $this->stub, $this->sharedDir, ['FAIL_ON' => 'route:cache']);

    expect(is_file($this->sharedDir.'/release.done'))->toBeFalse()
        ->and(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'config:cache', 'route:cache'])
        ->and(file_get_contents($this->sharedDir.'/storage/logs/cron.log'))->toContain('boom from route:cache');

    // next minute, the fault is gone: the whole sequence runs again and succeeds
    unlink($this->sharedDir.'/calls.txt');
    runCron($this->appDir, $this->stub, $this->sharedDir);
    expect(cronCalls($this->sharedDir))->toBe(['config:clear', 'migrate --force', 'config:cache', 'route:cache', 'view:cache', 'schedule:run'])
        ->and(trim(file_get_contents($this->sharedDir.'/release.done')))->toBe('r1');
});

it('does not run while another run holds a live lock, and recovers from a stale one', function () {
    runCron($this->appDir, $this->stub, $this->sharedDir);
    file_put_contents($this->sharedDir.'/.env', "APP_KEY=base64:x\n");
    file_put_contents($this->appDir.'/deploy/hostinger/release.txt', "r1\n");

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
