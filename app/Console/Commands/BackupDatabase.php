<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Nightly database backup using PDO only (the host disables exec, so no mysqldump).
 *
 * Writes a restorable SQL dump, gzip-compressed incrementally, to backups/lumino-hrm-YYYY-MM-DD.sql.gz on the
 * private "local" disk. Memory stays flat: tables are read one at a time as a row cursor and written in batches.
 * On MySQL/MariaDB the dump runs inside a consistent-snapshot transaction so all tables agree with each other, with
 * the session time zone set to UTC so TIMESTAMP values are dumped (and restored) in UTC.
 *
 * Before the file gets its final name it is read back to the end and must finish with the completion marker line
 * with the expected table count; only then is it renamed, the status written and old backups pruned.
 * `--if-missing` is the hourly catch-up: it does nothing unless it is 02:00 or later (app time) and there is no
 * good backup for today yet. See docs/backup-restore.md.
 */
class BackupDatabase extends Command
{
    protected $signature = 'hrm:backup-database {--if-missing : Only run when it is 02:00 or later and today has no good backup yet}';

    protected $description = 'Write a gzip SQL dump of the database to storage and delete backups older than the retention period';

    private const FILE_PATTERN = '/^lumino-hrm-(\d{4}-\d{2}-\d{2})\.sql\.gz$/';

    private const STATUS_FILE = 'backup-status/last-backup.json';

    private const MARKER = '/^-- Lumino HRM backup complete: (\d+) tables$/';

    /** @var resource|null */
    protected $gz = null;

    protected ?string $tmpPath = null;

    private string $driver = 'mysql';

    private ?PDOStatement $cursor = null;

    public function handle(): int
    {
        $started = microtime(true);
        $disk = Storage::disk('local');
        $tz = (string) config('app.timezone');
        $now = Carbon::now($tz);
        $today = $now->toDateString();
        $name = "lumino-hrm-{$today}.sql.gz";
        $lock = null;

        if ($this->option('if-missing')) {
            if ($now->hour < 2) {
                $this->line('Backup not due yet (before 02:00).');

                return self::SUCCESS;
            }
            if ($this->isGoodBackup($disk->path('backups').DIRECTORY_SEPARATOR.$name)) {
                $this->line("Backup for {$today} already exists.");

                return self::SUCCESS;
            }
        }

        try {
            $dir = $this->prepareDirectory($disk);
            $lock = $this->acquireLock($disk);
            if ($lock === null) {
                if ($this->option('if-missing')) {
                    $this->line('Another backup is running.');

                    return self::SUCCESS;
                }
                throw new RuntimeException('another backup is already running');
            }

            $final = $dir.DIRECTORY_SEPARATOR.$name;
            $this->tmpPath = $dir.DIRECTORY_SEPARATOR.'.'.$name.'.tmp'.getmypid();

            $tables = $this->dump(DB::connection()->getPdo(), $this->tmpPath);
            $this->verify($this->tmpPath, $tables);

            if (! @rename($this->tmpPath, $final)) {
                throw new RuntimeException('could not move the finished backup into place');
            }
            $this->tmpPath = null;

            // Only after a fully verified run: never prune on the back of a failed backup.
            $this->prune($disk, $today);

            $bytes = (int) filesize($final);
            $this->writeStatus($disk, [
                'ok' => true,
                'attempted_at' => Carbon::now($tz)->toIso8601String(),
                'last_success_at' => Carbon::now($tz)->toIso8601String(),
                'last_success_file' => $name,
                'last_success_bytes' => $bytes,
                'error' => null,
            ]);
            $this->line(sprintf('Backup written: %s, %s, %.1f s', $name, $this->humanSize($bytes), microtime(true) - $started));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->cleanUpTemp();
            $short = $this->safeMessage($e);
            // The log gets the same scrubbed text, never the raw driver message (it can contain 'user'@'host').
            report(new RuntimeException('Database backup failed: '.$short));
            $this->writeStatus($disk, [
                'ok' => false,
                'attempted_at' => Carbon::now($tz)->toIso8601String(),
                'error' => $short,
            ]);
            $this->error('Backup failed: '.$short);

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Absolute path of backups/, created owner-only where the platform supports it. */
    private function prepareDirectory(Filesystem $disk): string
    {
        $dir = $disk->path('backups');

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('could not create the backups folder');
        }

        return $dir;
    }

    /** @return resource|null null when another backup holds the lock */
    private function acquireLock(Filesystem $disk)
    {
        $path = $disk->path('backup-status/.lock');
        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }

        $handle = @fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('could not create the backup lock');
        }
        @chmod($path, 0600);

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** Writes the whole dump to $path and returns the number of tables dumped. */
    private function dump(PDO $pdo, string $path): int
    {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'sqlite' : 'mysql';

        // Create the file owner-only before anything is written into it.
        if (@file_put_contents($path, '') === false) {
            throw new RuntimeException('could not create the backup file');
        }
        @chmod($path, 0600);

        $this->gz = @gzopen($path, 'wb6');
        if ($this->gz === false) {
            $this->gz = null;
            throw new RuntimeException('could not open the backup file for writing');
        }

        $startedTransaction = false;
        $buffered = null;
        $previousZone = null;
        $count = 0;

        try {
            if ($this->driver === 'mysql') {
                $this->refuseUnsupportedObjects($pdo);
                $previousZone = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
                $pdo->exec("SET time_zone = '+00:00'");
            }

            // A consistent snapshot, unless the caller already holds a transaction (then it is already consistent).
            if (! $pdo->inTransaction()) {
                if ($this->driver === 'mysql') {
                    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
                } else {
                    $pdo->exec('BEGIN');
                }
                $startedTransaction = true;
            }

            if ($this->driver === 'mysql') {
                // Stream rows from the server instead of buffering a whole table in PHP.
                $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
            }

            $this->write("-- Lumino HRM database backup\n-- Created: ".Carbon::now(config('app.timezone'))->toIso8601String()."\n-- Restore: see docs/backup-restore.md\n\n");
            $this->write($this->driver === 'mysql'
                ? "SET NAMES utf8mb4;\nSET @OLD_TIME_ZONE=@@TIME_ZONE;\nSET time_zone='+00:00';\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n"
                : "PRAGMA foreign_keys=OFF;\n\n");

            $exclude = array_map('strval', (array) config('hrm.backup.exclude_data', []));

            foreach ($this->tables($pdo) as $table) {
                $this->dumpStructure($pdo, $table);
                if (! in_array($table, $exclude, true)) {
                    $this->dumpRows($pdo, $table);
                }
                $count++;
            }

            // Views come after every table. Name order: a view built on another view must sort after it
            // (documented limitation in docs/backup-restore.md).
            $views = $this->views($pdo);
            if ($views !== []) {
                $this->write("-- Views, in name order\n");
            }
            foreach ($views as $view => $createSql) {
                $q = $this->ident($view);
                $this->write("DROP VIEW IF EXISTS {$q};\n{$createSql};\n\n");
            }

            if ($this->driver === 'sqlite') {
                foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $sql) {
                    $this->write($sql.";\n");
                }
            }

            $this->write($this->driver === 'mysql'
                ? "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\nSET time_zone=@OLD_TIME_ZONE;\n"
                : "\nPRAGMA foreign_keys=ON;\n");
            $this->write("\n-- Lumino HRM backup complete: {$count} tables\n");
        } finally {
            // Whatever went wrong, the original exception must be the one that reaches the caller: every cleanup
            // step is allowed to fail silently (an unbuffered cursor left open makes MySQL refuse further queries).
            try {
                $this->cursor?->closeCursor();
            } catch (Throwable) {
            }
            $this->cursor = null;
            try {
                if ($buffered !== null) {
                    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
                }
                if ($startedTransaction && $pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK'); // read-only: nothing to keep
                }
                if ($previousZone !== null && $previousZone !== false) {
                    $pdo->exec('SET time_zone = '.$pdo->quote((string) $previousZone));
                }
            } catch (Throwable) {
            }
        }

        $this->closeGzip();

        return $count;
    }

    /** Triggers and routines are not dumped: fail loudly rather than produce an incomplete backup. */
    protected function refuseUnsupportedObjects(PDO $pdo): void
    {
        $n = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchColumn()
            + (int) $pdo->query('SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchColumn();

        if ($n > 0) {
            throw new RuntimeException("the database has {$n} trigger(s) or routine(s), which this backup does not dump");
        }
    }

    /** @return list<string> base tables only */
    private function tables(PDO $pdo): array
    {
        if ($this->driver === 'sqlite') {
            return array_values($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN));
        }

        $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return array_map(fn (array $r) => (string) $r[0], $rows);
    }

    /** @return array<string, string> view name => CREATE VIEW statement (without DEFINER) */
    private function views(PDO $pdo): array
    {
        $out = [];

        if ($this->driver === 'sqlite') {
            foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_NUM) as [$name, $sql]) {
                $out[$name] = $sql;
            }

            return $out;
        }

        $names = array_map(fn (array $r) => (string) $r[0], $pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM));
        sort($names);
        foreach ($names as $view) {
            $row = $pdo->query('SHOW CREATE VIEW '.$this->ident($view))->fetch(PDO::FETCH_NUM);
            $out[$view] = self::stripDefiner((string) $row[1]);
        }

        return $out;
    }

    /** `CREATE ALGORITHM=UNDEFINED DEFINER=`u`@`h` SQL SECURITY ...` without the DEFINER clause. */
    public static function stripDefiner(string $createView): string
    {
        return (string) preg_replace('/\sDEFINER\s*=\s*(`[^`]*`|[^\s@]+)@(`[^`]*`|\S+)(?=\s)/i', '', $createView, 1);
    }

    private function dumpStructure(PDO $pdo, string $table): void
    {
        $q = $this->ident($table);

        if ($this->driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
            $stmt->execute([$table]);
            $create = (string) $stmt->fetchColumn();
        } else {
            $create = (string) $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_NUM)[1];
        }

        $this->write("-- Table {$table}\nDROP TABLE IF EXISTS {$q};\n{$create};\n\n");
    }

    private function dumpRows(PDO $pdo, string $table): void
    {
        $q = $this->ident($table);
        ['select' => $columns, 'binary' => $binary, 'bit' => $bit] = $this->columnInfo($pdo, $table);
        if ($columns === []) {
            return;
        }
        $perInsert = max(1, (int) config('hrm.backup.rows_per_insert', 200));

        if ($this->driver === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $list = implode(', ', array_map(fn (string $c) => $this->ident($c), $columns));
        $this->cursor = $stmt = $pdo->query("SELECT {$list} FROM {$q}");
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $head = "INSERT INTO {$q} ({$list}) VALUES";
        $values = [];
        $size = 0;

        // No other statement may run on this connection while an unbuffered cursor is open: only quoting happens here.
        foreach ($stmt as $row) {
            $parts = [];
            foreach ($row as $column => $value) {
                $parts[] = $this->literal($pdo, $value, isset($binary[$column]), isset($bit[$column]));
            }
            $values[] = '('.implode(', ', $parts).')';
            $size += strlen(end($values));

            if (count($values) >= $perInsert || $size >= 1_000_000) {
                $this->write($head."\n".implode(",\n", $values).";\n");
                $values = [];
                $size = 0;
            }
        }

        if ($values !== []) {
            $this->write($head."\n".implode(",\n", $values).";\n");
        }
        $stmt->closeCursor();
        $this->cursor = null;

        if ($this->driver === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        $this->write("\n");
    }

    /**
     * Columns to read (generated columns are skipped: they cannot be inserted), plus which are binary/blob
     * (hex literals) and which are BIT (b'..' literals).
     *
     * @return array{select: list<string>, binary: array<string, true>, bit: array<string, true>}
     */
    private function columnInfo(PDO $pdo, string $table): array
    {
        $q = $this->ident($table);
        $info = ['select' => [], 'binary' => [], 'bit' => []];

        if ($this->driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_xinfo({$q})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if ((int) $c['hidden'] !== 0) {
                    continue; // generated or hidden column
                }
                $info['select'][] = $c['name'];
                if (stripos((string) $c['type'], 'blob') !== false) {
                    $info['binary'][$c['name']] = true;
                }
            }

            return $info;
        }

        foreach ($pdo->query("SHOW COLUMNS FROM {$q}")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (self::isGeneratedColumn((string) ($c['Extra'] ?? ''))) {
                continue;
            }
            $info['select'][] = $c['Field'];
            if (preg_match('/\b(tiny|medium|long)?blob\b|\b(var)?binary\b/i', (string) $c['Type'])) {
                $info['binary'][$c['Field']] = true;
            } elseif (preg_match('/^bit\b/i', (string) $c['Type'])) {
                $info['bit'][$c['Field']] = true;
            }
        }

        return $info;
    }

    /** SHOW COLUMNS "Extra" for a generated column: "VIRTUAL GENERATED", "STORED GENERATED" (MariaDB also "PERSISTENT"). */
    public static function isGeneratedColumn(string $extra): bool
    {
        return (bool) preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', $extra);
    }

    public static function bitLiteral(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        return "b'".(ltrim($bits, '0') === '' ? '0' : ltrim($bits, '0'))."'";
    }

    private function literal(PDO $pdo, mixed $value, bool $isBinary, bool $isBit = false): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        if (is_int($value)) {
            return $isBit ? "b'".decbin($value)."'" : (string) $value;
        }

        if (is_float($value)) {
            // var_export uses the shortest text that reads back as the same double (10.0 stays 10.0, never 1).
            return is_finite($value) ? var_export($value, true) : 'NULL';
        }

        $value = (string) $value;

        if ($isBit) {
            return self::bitLiteral($value);
        }

        if ($isBinary) {
            return "X'".bin2hex($value)."'";
        }

        return $pdo->quote($value);
    }

    private function ident(string $name): string
    {
        return $this->driver === 'mysql'
            ? '`'.str_replace('`', '``', $name).'`'
            : '"'.str_replace('"', '""', $name).'"';
    }

    /** Writes everything or fails: a short or failed write (disk full) must never go unnoticed. */
    private function write(string $sql): void
    {
        $written = @$this->gzWrite($sql);

        if ($written === false || $written < strlen($sql)) {
            throw new RuntimeException('writing the backup failed (disk full?)');
        }
    }

    protected function gzWrite(string $sql): int|false
    {
        return gzwrite($this->gz, $sql);
    }

    /** gzclose flushes the final block: a failure here means a truncated file. */
    private function closeGzip(): void
    {
        $handle = $this->gz;
        $this->gz = null;

        if (! $this->gzFinish($handle)) {
            throw new RuntimeException('could not finish writing the backup (disk full?)');
        }
    }

    /** @param resource $handle */
    protected function gzFinish($handle): bool
    {
        return @gzclose($handle);
    }

    /**
     * Reads the file back to the end (bounded memory): it must decompress cleanly and its last non-empty line must be
     * the completion marker with the expected table count (null = any count).
     */
    private function verify(string $path, ?int $expectedTables): void
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            throw new RuntimeException('the finished backup cannot be read back');
        }

        $tail = '';
        try {
            while (! gzeof($gz)) {
                $chunk = gzread($gz, 262144);
                if ($chunk === false) {
                    throw new RuntimeException('the finished backup is damaged');
                }
                if ($chunk === '') {
                    break;
                }
                $tail = substr($tail.$chunk, -512);
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('the finished backup is damaged or truncated');
        } finally {
            @gzclose($gz);
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $tail)), fn (string $l) => $l !== ''));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (! preg_match(self::MARKER, $last, $m) || ($expectedTables !== null && (int) $m[1] !== $expectedTables)) {
            throw new RuntimeException('the finished backup is incomplete (completion marker missing or wrong)');
        }
    }

    private function isGoodBackup(string $path): bool
    {
        if (! is_file($path) || filesize($path) === 0) {
            return false;
        }

        try {
            $this->verify($path, null);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** Deletes dated backups older than the retention period. Only names matching the backup pattern are touched. */
    private function prune(Filesystem $disk, string $today): void
    {
        $dir = $disk->path('backups');
        $limit = Carbon::parse($today, config('app.timezone'))->subDays((int) config('hrm.backup.keep_days', 14))->toDateString();

        foreach (scandir($dir) ?: [] as $entry) {
            if (! preg_match(self::FILE_PATTERN, $entry, $m) || ! is_file($dir.DIRECTORY_SEPARATOR.$entry)) {
                continue;
            }

            $valid = \DateTime::createFromFormat('!Y-m-d', $m[1]);
            if ($valid === false || $valid->format('Y-m-d') !== $m[1]) {
                continue;
            }

            if ($m[1] < $limit) {
                @unlink($dir.DIRECTORY_SEPARATOR.$entry);
            }
        }
    }

    /**
     * Records the latest attempt. The details of the last GOOD backup are kept across failed attempts.
     *
     * @param  array<string, mixed>  $status
     */
    private function writeStatus(Filesystem $disk, array $status): void
    {
        try {
            $path = $disk->path(self::STATUS_FILE);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }

            $previous = is_file($path) ? json_decode((string) @file_get_contents($path), true) : null;
            $keep = [];
            foreach (['last_success_at', 'last_success_file', 'last_success_bytes'] as $key) {
                $keep[$key] = is_array($previous) ? ($previous[$key] ?? null) : null;
            }

            $merged = array_merge(['ok' => false, 'attempted_at' => null], $keep, $status, ['error' => $status['error'] ?? null]);
            $ordered = array_merge(array_flip(['ok', 'attempted_at', 'last_success_at', 'last_success_file', 'last_success_bytes', 'error']), $merged);

            $tmp = $path.'.tmp'.getmypid();
            if (file_put_contents($tmp, json_encode($ordered, JSON_PRETTY_PRINT)."\n") !== false) {
                @chmod($tmp, 0600);
                rename($tmp, $path);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function cleanUpTemp(): void
    {
        if (is_resource($this->gz)) {
            @gzclose($this->gz);
        }
        $this->gz = null;

        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
            $this->tmpPath = null;
        }
    }

    /** One line, no credentials: a database error shows only its class and SQLSTATE/code, never the driver message. */
    private function safeMessage(Throwable $e): string
    {
        if ($e instanceof PDOException) {
            return 'database error '.class_basename($e).' ['.($e->getCode() ?: 'unknown').']';
        }

        $message = preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", $e->getMessage()) ?? '';

        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $message)), 0, 200);
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.1f KB', $bytes / 1024);
    }
}
