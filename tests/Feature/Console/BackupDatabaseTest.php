<?php

use App\Models\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function backupFiles(): array
{
    return collect(Storage::disk('local')->files('backups'))->values()->all();
}

function backupSql(string $file): string
{
    return gzdecode(Storage::disk('local')->get($file));
}

it('writes a gzip backup containing table structure and rows', function () {
    Storage::fake('local');
    Department::create(['name' => 'Backup Check']);
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $file = collect(Storage::disk('local')->files('backups'))->first();
    expect($file)->toEndWith('.sql.gz');
    expect(gzdecode(Storage::disk('local')->get($file)))->toContain('Backup Check')->toContain('CREATE TABLE');
});

it('keeps 14 days of backups and removes older ones', function () {
    Storage::fake('local');
    Storage::disk('local')->put('backups/lumino-hrm-'.now()->subDays(20)->toDateString().'.sql.gz', 'old');
    Storage::disk('local')->put('backups/lumino-hrm-'.now()->subDays(5)->toDateString().'.sql.gz', 'recent');
    $this->artisan('hrm:backup-database');
    expect(Storage::disk('local')->files('backups'))->toHaveCount(2);
});

it('names the file by the date in the app timezone and replaces the same day file on a second run', function () {
    Storage::fake('local');
    config(['app.timezone' => 'Asia/Karachi']);
    $this->travelTo(now()->utc()->setDate(2026, 11, 23)->setTime(21, 30)); // 02:30 on the 24th in Karachi

    $this->artisan('hrm:backup-database')->assertSuccessful();
    expect(backupFiles())->toBe(['backups/lumino-hrm-2026-11-24.sql.gz']);

    Department::create(['name' => 'Added Later']);
    $this->artisan('hrm:backup-database')->assertSuccessful();
    expect(backupFiles())->toBe(['backups/lumino-hrm-2026-11-24.sql.gz']);
    expect(backupSql('backups/lumino-hrm-2026-11-24.sql.gz'))->toContain('Added Later');
});

it('never deletes files that do not fit the backup naming pattern', function () {
    Storage::fake('local');
    $d = Storage::disk('local');
    $d->put('backups/notes.txt', 'keep');
    $d->put('backups/lumino-hrm-2020-01-01.sql.gz.bak', 'keep');
    $d->put('backups/other-2020-01-01.sql.gz', 'keep');
    $d->put('backups/lumino-hrm-2020-13-45.sql.gz', 'keep');
    $d->put('backups/lumino-hrm-2020-01-01.sql.gz', 'delete me');

    $this->artisan('hrm:backup-database')->assertSuccessful();

    expect($d->exists('backups/notes.txt'))->toBeTrue()
        ->and($d->exists('backups/lumino-hrm-2020-01-01.sql.gz.bak'))->toBeTrue()
        ->and($d->exists('backups/other-2020-01-01.sql.gz'))->toBeTrue()
        ->and($d->exists('backups/lumino-hrm-2020-13-45.sql.gz'))->toBeTrue()
        ->and($d->exists('backups/lumino-hrm-2020-01-01.sql.gz'))->toBeFalse();
});

it('dumps the structure but not the data of transient tables', function () {
    Storage::fake('local');
    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'SECRET-TOKEN-PAYLOAD', 'attempts' => 0, 'available_at' => 1, 'created_at' => 1]);
    DB::table('sessions')->insert(['id' => 'sess1', 'payload' => 'SESSION-DATA', 'last_activity' => 1]);
    DB::table('failed_jobs')->insert(['uuid' => 'u1', 'connection' => 'c', 'queue' => 'q', 'payload' => 'FAILED-PAYLOAD', 'exception' => 'e', 'failed_at' => now()]);

    $this->artisan('hrm:backup-database')->assertSuccessful();
    $sql = backupSql(backupFiles()[0]);

    expect($sql)->toContain('CREATE TABLE "jobs"')->toContain('CREATE TABLE "sessions"')->toContain('CREATE TABLE "failed_jobs"')
        ->not->toContain('SECRET-TOKEN-PAYLOAD')->not->toContain('SESSION-DATA')->not->toContain('FAILED-PAYLOAD');
});

it('takes the list of tables without data from config', function () {
    Storage::fake('local');
    expect(config('hrm.backup.exclude_data'))->toBe(['cache', 'cache_locks', 'sessions', 'jobs', 'job_batches', 'failed_jobs']);

    config(['hrm.backup.exclude_data' => ['departments']]);
    Department::create(['name' => 'Hidden Dept']);
    $this->artisan('hrm:backup-database')->assertSuccessful();
    // (the audit log legitimately mentions the name, so look at the table itself)
    expect(backupSql(backupFiles()[0]))->toContain('CREATE TABLE "departments"')->not->toContain('INSERT INTO "departments"');
});

it('produces SQL that restores NULLs, numbers, binary, quotes, newlines and unicode exactly', function () {
    Storage::fake('local');
    Schema::create('dump_check', function ($t) {
        $t->increments('id');
        $t->string('s')->nullable();
        $t->integer('n')->nullable();
        $t->double('f')->nullable();
        $t->binary('b')->nullable();
    });
    $blob = "\x00\x01\xFF'\"\\binary";
    $rows = [
        ['s' => "O'Brien \"q\" \\ back\nline\ttab", 'n' => -7, 'f' => 3.25, 'b' => $blob],
        ['s' => null, 'n' => null, 'f' => null, 'b' => null],
        ['s' => 'Zoë 😀 اردو', 'n' => 0, 'f' => 0.0, 'b' => ''],
        ['s' => 'round floats', 'n' => 100, 'f' => 10.0, 'b' => null],
        ['s' => 'tiny and huge', 'n' => 1, 'f' => 1.0E-10, 'b' => null],
        ['s' => 'decimals', 'n' => 2, 'f' => 1234.5678, 'b' => null],
    ];
    DB::table('dump_check')->insert($rows);

    $this->artisan('hrm:backup-database')->assertSuccessful();
    $sql = backupSql(backupFiles()[0]);

    $restored = new PDO('sqlite::memory:');
    $restored->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $restored->exec($sql);

    $got = $restored->query('select s, n, f, b from dump_check order by id')->fetchAll(PDO::FETCH_ASSOC);
    expect($got)->toHaveCount(6);
    expect($got[0]['s'])->toBe($rows[0]['s'])->and($got[0]['n'])->toBe(-7)->and((float) $got[0]['f'])->toBe(3.25)->and($got[0]['b'])->toBe($blob);
    expect($got[1])->toBe(['s' => null, 'n' => null, 'f' => null, 'b' => null]);
    expect($got[2]['s'])->toBe('Zoë 😀 اردو')->and($got[2]['b'])->toBe('');
    // Floats come back bit-for-bit (a naive trailing-zero trim once turned 10.0 into 1).
    expect(array_map(fn ($r) => (float) $r['f'], array_slice($got, 2)))->toBe([0.0, 10.0, 1.0E-10, 1234.5678]);
});

it('writes in batches of rows without loading a table into memory', function () {
    Storage::fake('local');
    config(['hrm.backup.rows_per_insert' => 50]);
    DB::table('departments')->insert(array_map(fn ($i) => ['name' => "Dept $i", 'created_at' => now(), 'updated_at' => now()], range(1, 120)));

    $this->artisan('hrm:backup-database')->assertSuccessful();
    $sql = backupSql(backupFiles()[0]);

    expect(substr_count($sql, 'INSERT INTO "departments"'))->toBe(3);
    expect($sql)->toContain('Dept 120');
});

it('records the outcome in last-backup.json and prints one line with file, size and duration', function () {
    Storage::fake('local');
    $this->artisan('hrm:backup-database')->expectsOutputToContain('lumino-hrm-'.now()->toDateString().'.sql.gz')->assertSuccessful();

    $status = json_decode(Storage::disk('local')->get('backup-status/last-backup.json'), true);
    expect($status)->toHaveKeys(['file', 'bytes', 'finished_at', 'ok'])
        ->and($status['ok'])->toBeTrue()
        ->and($status['file'])->toBe('lumino-hrm-'.now()->toDateString().'.sql.gz')
        ->and($status['bytes'])->toBe(Storage::disk('local')->size(backupFiles()[0]))
        ->and($status['finished_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/');
});

it('fails cleanly: non-zero exit, no partial file, old backups kept, failure recorded', function () {
    Storage::fake('local');
    $d = Storage::disk('local');
    $d->put('backups/lumino-hrm-'.now()->subDays(30)->toDateString().'.sql.gz', 'old');
    // The final name is a directory, so the last step (rename) fails after the dump was written.
    $d->makeDirectory('backups/lumino-hrm-'.now()->toDateString().'.sql.gz');

    $this->artisan('hrm:backup-database')->assertFailed();

    expect(collect($d->allFiles('backups'))->filter(fn ($f) => str_contains($f, '.tmp')))->toBeEmpty();
    expect($d->exists('backups/lumino-hrm-'.now()->subDays(30)->toDateString().'.sql.gz'))->toBeTrue();
    $status = json_decode($d->get('backup-status/last-backup.json'), true);
    expect($status['ok'])->toBeFalse();
});

it('keeps the backup out of the web root and creates the folder owner-only', function () {
    $public = str_replace('\\', '/', public_path());
    expect(str_starts_with(str_replace('\\', '/', (string) config('filesystems.disks.local.root')), $public))->toBeFalse();

    Storage::fake('local');
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $dir = Storage::disk('local')->path('backups');
    expect(str_starts_with(str_replace('\\', '/', $dir), $public))->toBeFalse();

    if (DIRECTORY_SEPARATOR === '/') {
        expect(substr(sprintf('%o', fileperms($dir)), -3))->toBe('700');
        expect(substr(sprintf('%o', fileperms(Storage::disk('local')->path(backupFiles()[0]))), -3))->toBe('600');
    }
});
