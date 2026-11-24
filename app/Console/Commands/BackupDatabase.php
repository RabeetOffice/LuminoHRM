<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Nightly database backup using PDO only (the host disables exec, so no mysqldump).
 *
 * Writes a restorable SQL dump, gzip-compressed incrementally, to backups/lumino-hrm-YYYY-MM-DD.sql.gz on the
 * private "local" disk. Memory stays flat: tables are read one at a time as a row cursor and written in batches.
 * On MySQL/MariaDB the dump runs inside a consistent-snapshot transaction so all tables agree with each other.
 * Works on SQLite too (the test database). See docs/backup-restore.md.
 */
class BackupDatabase extends Command
{
    protected $signature = 'hrm:backup-database';

    protected $description = 'Write a gzip SQL dump of the database to storage and delete backups older than the retention period';

    private const FILE_PATTERN = '/^lumino-hrm-(\d{4}-\d{2}-\d{2})\.sql\.gz$/';

    private const STATUS_FILE = 'backup-status/last-backup.json';

    /** @var resource|null */
    private $gz = null;

    private string $driver = 'mysql';

    private ?string $tmpPath = null;

    public function handle(): int
    {
        $started = microtime(true);
        $disk = Storage::disk('local');
        $today = Carbon::now(config('app.timezone'))->toDateString();
        $name = "lumino-hrm-{$today}.sql.gz";

        try {
            $dir = $this->prepareDirectory($disk);
            $final = $dir.DIRECTORY_SEPARATOR.$name;
            $this->tmpPath = $dir.DIRECTORY_SEPARATOR.'.'.$name.'.tmp'.getmypid();

            $this->dump(DB::connection()->getPdo(), $this->tmpPath);

            if (! @rename($this->tmpPath, $final)) {
                throw new RuntimeException('could not move the finished backup into place');
            }
            $this->tmpPath = null;

            // Only after a fully successful run: never prune on the back of a failed backup.
            $this->prune($disk, $today);

            $bytes = (int) filesize($final);
            $this->writeStatus($disk, ['file' => $name, 'bytes' => $bytes, 'finished_at' => Carbon::now(config('app.timezone'))->toIso8601String(), 'ok' => true]);
            $this->line(sprintf('Backup written: %s, %s, %.1f s', $name, $this->humanSize($bytes), microtime(true) - $started));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->cleanUpTemp();
            report($e);
            $this->writeStatus($disk, ['file' => '', 'bytes' => 0, 'finished_at' => Carbon::now(config('app.timezone'))->toIso8601String(), 'ok' => false]);
            $this->error('Backup failed: '.$this->safeMessage($e));

            return self::FAILURE;
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

    private function dump(PDO $pdo, string $path): void
    {
        $this->driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'sqlite' : 'mysql';

        // Create the file owner-only before anything is written into it.
        if (@file_put_contents($path, '') === false) {
            throw new RuntimeException('could not create the backup file');
        }
        @chmod($path, 0600);

        $this->gz = @gzopen($path, 'wb6');
        if ($this->gz === false) {
            throw new RuntimeException('could not open the backup file for writing');
        }

        $startedTransaction = false;
        $buffered = null;

        try {
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
                ? "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n"
                : "PRAGMA foreign_keys=OFF;\n\n");

            $exclude = array_map('strval', (array) config('hrm.backup.exclude_data', []));

            foreach ($this->tables($pdo) as $table) {
                $this->dumpStructure($pdo, $table);
                if (! in_array($table, $exclude, true)) {
                    $this->dumpRows($pdo, $table);
                }
            }

            foreach ($this->views($pdo) as $view => $createSql) {
                $q = $this->ident($view);
                $this->write("DROP VIEW IF EXISTS {$q};\n{$createSql};\n\n");
            }

            if ($this->driver === 'sqlite') {
                foreach ($pdo->query("SELECT sql FROM sqlite_master WHERE type IN ('index','trigger') AND sql IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $sql) {
                    $this->write($sql.";\n");
                }
            }

            $this->write($this->driver === 'mysql' ? "\nSET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n" : "\nPRAGMA foreign_keys=ON;\n");
        } finally {
            if ($buffered !== null) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
            }
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->exec('ROLLBACK'); // read-only: nothing to keep
            }
            if (is_resource($this->gz)) {
                gzclose($this->gz);
            }
            $this->gz = null;
        }
    }

    /** @return list<string> base tables only */
    private function tables(PDO $pdo): array
    {
        if ($this->driver === 'sqlite') {
            return $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite\\_%' ESCAPE '\\' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        }

        $rows = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        return array_map(fn (array $r) => (string) $r[0], $rows);
    }

    /** @return array<string, string> view name => CREATE VIEW statement */
    private function views(PDO $pdo): array
    {
        $out = [];

        if ($this->driver === 'sqlite') {
            foreach ($pdo->query("SELECT name, sql FROM sqlite_master WHERE type = 'view' ORDER BY name")->fetchAll(PDO::FETCH_NUM) as [$name, $sql]) {
                $out[$name] = $sql;
            }

            return $out;
        }

        foreach ($pdo->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $row = $pdo->query('SHOW CREATE VIEW '.$this->ident((string) $r[0]))->fetch(PDO::FETCH_NUM);
            $out[(string) $r[0]] = (string) $row[1];
        }

        return $out;
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
        $binary = $this->binaryColumns($pdo, $table);
        $perInsert = max(1, (int) config('hrm.backup.rows_per_insert', 200));

        if ($this->driver === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        $stmt = $pdo->query("SELECT * FROM {$q}");
        $stmt->setFetchMode(PDO::FETCH_ASSOC);

        $head = null;
        $values = [];
        $size = 0;

        // No other statement may run on this connection while an unbuffered cursor is open: only quoting happens here.
        foreach ($stmt as $row) {
            $head ??= "INSERT INTO {$q} (".implode(', ', array_map(fn ($c) => $this->ident((string) $c), array_keys($row))).') VALUES';

            $parts = [];
            foreach ($row as $column => $value) {
                $parts[] = $this->literal($pdo, $value, isset($binary[$column]));
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

        if ($this->driver === 'mysql') {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }

        $this->write("\n");
    }

    /** @return array<string, true> columns stored as binary/blob, written as hex literals */
    private function binaryColumns(PDO $pdo, string $table): array
    {
        $out = [];
        $q = $this->ident($table);

        if ($this->driver === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info({$q})")->fetchAll(PDO::FETCH_ASSOC) as $c) {
                if (stripos((string) $c['type'], 'blob') !== false) {
                    $out[$c['name']] = true;
                }
            }

            return $out;
        }

        foreach ($pdo->query("SHOW COLUMNS FROM {$q}")->fetchAll(PDO::FETCH_ASSOC) as $c) {
            if (preg_match('/\b(tiny|medium|long)?blob\b|\b(var)?binary\b/i', (string) $c['Type'])) {
                $out[$c['Field']] = true;
            }
        }

        return $out;
    }

    private function literal(PDO $pdo, mixed $value, bool $isBinary): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_resource($value)) {
            $value = (string) stream_get_contents($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // var_export uses the shortest text that reads back as the same double (10.0 stays 10.0, never 1).
            return is_finite($value) ? var_export($value, true) : 'NULL';
        }

        $value = (string) $value;

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

    private function write(string $sql): void
    {
        if (@gzwrite($this->gz, $sql) === false) {
            throw new RuntimeException('writing the backup failed (disk full?)');
        }
    }

    /** Deletes dated backups older than the retention period. Only names matching the backup pattern are touched. */
    private function prune(Filesystem $disk, string $today): void
    {
        $limit = Carbon::parse($today, config('app.timezone'))->subDays((int) config('hrm.backup.keep_days', 14))->toDateString();

        foreach (glob($disk->path('backups').DIRECTORY_SEPARATOR.'lumino-hrm-*.sql.gz') ?: [] as $file) {
            if (! is_file($file) || ! preg_match(self::FILE_PATTERN, basename($file), $m)) {
                continue;
            }

            $valid = \DateTime::createFromFormat('!Y-m-d', $m[1]);
            if ($valid === false || $valid->format('Y-m-d') !== $m[1]) {
                continue;
            }

            if ($m[1] < $limit) {
                @unlink($file);
            }
        }
    }

    /** @param array{file: string, bytes: int, finished_at: string, ok: bool} $status */
    private function writeStatus(Filesystem $disk, array $status): void
    {
        try {
            $path = $disk->path(self::STATUS_FILE);
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0700, true);
            }
            $tmp = $path.'.tmp'.getmypid();
            if (file_put_contents($tmp, json_encode($status, JSON_PRETTY_PRINT)."\n") !== false) {
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
            $this->gz = null;
        }

        if ($this->tmpPath !== null) {
            @unlink($this->tmpPath);
            $this->tmpPath = null;
        }
    }

    /** One line, no credentials: a database error shows only its code and a scrubbed message. */
    private function safeMessage(Throwable $e): string
    {
        $message = preg_replace("/'[^']*'@'[^']*'/", "'***'@'***'", $e->getMessage()) ?? '';
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        if ($e instanceof PDOException) {
            $message = 'database error ['.($e->getCode() ?: 'unknown').'] '.$message;
        }

        return mb_substr($message, 0, 200);
    }

    private function humanSize(int $bytes): string
    {
        return $bytes >= 1048576 ? sprintf('%.1f MB', $bytes / 1048576) : sprintf('%.1f KB', $bytes / 1024);
    }
}
