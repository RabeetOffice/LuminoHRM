<?php

use App\Console\Commands\BackupDatabase;
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
    expect($status)->toHaveKeys(['ok', 'attempted_at', 'last_success_at', 'last_success_file', 'last_success_bytes', 'error'])
        ->and($status['ok'])->toBeTrue()
        ->and($status['error'])->toBeNull()
        ->and($status['last_success_file'])->toBe('lumino-hrm-'.now()->toDateString().'.sql.gz')
        ->and($status['last_success_bytes'])->toBe(Storage::disk('local')->size(backupFiles()[0]))
        ->and($status['last_success_at'])->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d$/')
        ->and($status['attempted_at'])->toMatch('/^\d{4}-\d\d-\d\dT/');
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

// ---------------------------------------------------------------- reliability

/** Every write reports zero bytes written, like a full disk. */
class FullDiskBackup extends BackupDatabase
{
    protected function gzWrite(string $sql): int|false
    {
        return 0;
    }
}

/** The final flush works but the file ends up truncated, like a disk filling up during gzclose. */
class TruncatedBackup extends BackupDatabase
{
    protected function gzFinish($handle): bool
    {
        $ok = parent::gzFinish($handle);
        $bytes = (string) file_get_contents($this->tmpPath);
        file_put_contents($this->tmpPath, substr($bytes, 0, max(1, strlen($bytes) - 25)));

        return $ok;
    }
}

class FailingCloseBackup extends BackupDatabase
{
    protected function gzFinish($handle): bool
    {
        parent::gzFinish($handle);

        return false;
    }
}

function assertNoBackupWasPublished(string $oldName): void
{
    $d = Storage::disk('local');
    expect(collect($d->allFiles('backups'))->filter(fn ($f) => str_contains($f, '.tmp')))->toBeEmpty();
    expect($d->exists("backups/$oldName"))->toBeTrue(); // not pruned
    expect($d->exists('backups/lumino-hrm-'.now()->toDateString().'.sql.gz'))->toBeFalse();
}

it('treats a failed or short gzwrite as a failure: no final file, no pruning, status ok=false', function () {
    Storage::fake('local');
    $old = 'lumino-hrm-'.now()->subDays(30)->toDateString().'.sql.gz';
    Storage::disk('local')->put("backups/$old", 'old');
    $this->app->bind(BackupDatabase::class, fn () => new FullDiskBackup);

    $this->artisan('hrm:backup-database')->assertFailed();

    assertNoBackupWasPublished($old);
    $status = json_decode(Storage::disk('local')->get('backup-status/last-backup.json'), true);
    expect($status['ok'])->toBeFalse()->and($status['error'])->toContain('writing the backup failed');
});

it('treats a failing gzclose as a failure', function () {
    Storage::fake('local');
    $old = 'lumino-hrm-'.now()->subDays(30)->toDateString().'.sql.gz';
    Storage::disk('local')->put("backups/$old", 'old');
    $this->app->bind(BackupDatabase::class, fn () => new FailingCloseBackup);

    $this->artisan('hrm:backup-database')->assertFailed();

    assertNoBackupWasPublished($old);
});

it('rejects a truncated temp file in the read-back pass before renaming', function () {
    Storage::fake('local');
    $old = 'lumino-hrm-'.now()->subDays(30)->toDateString().'.sql.gz';
    Storage::disk('local')->put("backups/$old", 'old');
    $this->app->bind(BackupDatabase::class, fn () => new TruncatedBackup);

    $this->artisan('hrm:backup-database')->assertFailed();

    assertNoBackupWasPublished($old);
    expect(json_decode(Storage::disk('local')->get('backup-status/last-backup.json'), true)['ok'])->toBeFalse();
});

it('ends the dump with a completion marker carrying the table count', function () {
    Storage::fake('local');
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $sql = backupSql(backupFiles()[0]);
    $tables = DB::select("select count(*) c from sqlite_master where type='table' and name not like 'sqlite\\_%' escape '\\'")[0]->c;

    expect(rtrim($sql))->toEndWith("-- Lumino HRM backup complete: {$tables} tables");
});

it('keeps the last good backup details in the status file after a failed run', function () {
    Storage::fake('local');
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $good = json_decode(Storage::disk('local')->get('backup-status/last-backup.json'), true);

    // The next run fails at the last step: the final name is occupied by a directory.
    Storage::disk('local')->delete(backupFiles()[0]);
    Storage::disk('local')->makeDirectory('backups/lumino-hrm-'.now()->toDateString().'.sql.gz');
    $this->artisan('hrm:backup-database')->assertFailed();

    $after = json_decode(Storage::disk('local')->get('backup-status/last-backup.json'), true);
    expect($after['ok'])->toBeFalse()
        ->and($after['error'])->not->toBeEmpty()
        ->and($after['last_success_at'])->toBe($good['last_success_at'])
        ->and($after['last_success_file'])->toBe($good['last_success_file'])
        ->and($after['last_success_bytes'])->toBe($good['last_success_bytes']);
});

it('refuses to back up a database with triggers or routines', function () {
    $stmt = new class extends PDOStatement
    {
        public function fetchColumn(int $column = 0): mixed
        {
            return 1;
        }
    };
    $pdo = new class($stmt) extends PDO
    {
        public function __construct(private PDOStatement $stmt) {}

        public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
        {
            return $this->stmt;
        }
    };
    $m = new class extends BackupDatabase
    {
        public function guard(PDO $pdo): void
        {
            $this->refuseUnsupportedObjects($pdo);
        }
    };

    expect(fn () => $m->guard($pdo))->toThrow(RuntimeException::class, 'trigger(s) or routine(s)');
});

// ---------------------------------------------------------------- --if-missing (hourly catch-up)

it('--if-missing does nothing before 02:00 app time', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Karachi')->setTime(1, 20));

    $this->artisan('hrm:backup-database --if-missing')->assertSuccessful();

    expect(backupFiles())->toBe([]);
    expect(Storage::disk('local')->exists('backup-status/last-backup.json'))->toBeFalse();
});

it('--if-missing does nothing after 02:00 when today already has a good backup', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Karachi')->setTime(3, 20));
    $this->artisan('hrm:backup-database')->assertSuccessful();
    $before = Storage::disk('local')->get(backupFiles()[0]);
    Department::create(['name' => 'After First Backup']);

    $this->artisan('hrm:backup-database --if-missing')->assertSuccessful();

    expect(Storage::disk('local')->get(backupFiles()[0]))->toBe($before);
});

it('--if-missing runs a normal backup after 02:00 when today has none, or only a damaged one', function () {
    Storage::fake('local');
    $this->travelTo(now('Asia/Karachi')->setTime(4, 20));
    $this->artisan('hrm:backup-database --if-missing')->assertSuccessful();
    expect(backupFiles())->toBe(['backups/lumino-hrm-'.now('Asia/Karachi')->toDateString().'.sql.gz']);
    expect(backupSql(backupFiles()[0]))->toContain('CREATE TABLE');

    Storage::disk('local')->put(backupFiles()[0], 'not a real backup');
    $this->artisan('hrm:backup-database --if-missing')->assertSuccessful();
    expect(backupSql(backupFiles()[0]))->toContain('CREATE TABLE');
});

it('prunes with an exact file name match', function () {
    Storage::fake('local');
    $d = Storage::disk('local');
    $d->put('backups/lumino-hrm-2020-01-01.sql.gz', 'x');
    $d->put('backups/lumino-hrm-2020-01-01.sql.gz.old', 'x');
    $d->put('backups/lumino-hrm-2020-01-01x.sql.gz', 'x');
    $this->artisan('hrm:backup-database')->assertSuccessful();
    expect($d->exists('backups/lumino-hrm-2020-01-01.sql.gz'))->toBeFalse()
        ->and($d->exists('backups/lumino-hrm-2020-01-01.sql.gz.old'))->toBeTrue()
        ->and($d->exists('backups/lumino-hrm-2020-01-01x.sql.gz'))->toBeTrue();
});

// ---------------------------------------------------------------- SQL building

it('strips DEFINER from a view definition', function () {
    $sql = 'CREATE ALGORITHM=UNDEFINED DEFINER=`app`@`localhost` SQL SECURITY DEFINER VIEW `v` AS select 1';
    expect(BackupDatabase::stripDefiner($sql))->toBe('CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS select 1');
    expect(BackupDatabase::stripDefiner('CREATE DEFINER=`a b`@`%` VIEW v AS select 1'))->toBe('CREATE VIEW v AS select 1');
});

it('recognises generated columns and writes BIT values as b literals', function () {
    $c = BackupDatabase::class;
    expect($c::isGeneratedColumn('VIRTUAL GENERATED'))->toBeTrue()
        ->and($c::isGeneratedColumn('STORED GENERATED'))->toBeTrue()
        ->and($c::isGeneratedColumn('auto_increment'))->toBeFalse()
        ->and($c::isGeneratedColumn('DEFAULT_GENERATED'))->toBeFalse()
        ->and($c::isGeneratedColumn(''))->toBeFalse();
    expect($c::bitLiteral("\x05"))->toBe("b'101'")
        ->and($c::bitLiteral("\x00"))->toBe("b'0'")
        ->and($c::bitLiteral("\x01\x00"))->toBe("b'100000000'");
});

it('does not insert generated columns (sqlite)', function () {
    Storage::fake('local');
    DB::statement('create table gen_check (a integer, b integer generated always as (a * 2) virtual)');
    DB::table('gen_check')->insert(['a' => 4]);
    $this->artisan('hrm:backup-database')->assertSuccessful();
    expect(backupSql(backupFiles()[0]))->toContain('INSERT INTO "gen_check" ("a") VALUES');
});
