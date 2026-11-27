<?php

namespace App\Support\System;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Models\Deployment;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * "Update system" (Updates screen, Admins only). New code reaches the server by itself on every push; its database
 * changes wait for an Admin, who applies them here: back up the database, run the waiting changes, bring roles and
 * permissions in line (hrm:sync-access), refresh the caches and record the installed version. It runs inside the web
 * request, so it needs no shell, queue or scheduler. Every step is written to a deployments row.
 *
 * An update is waiting when the version at the top of resources/changelog.php differs from the installed one, or when
 * database changes are waiting.
 */
class SystemUpdater
{
    private const FLAG = 'hrm.update-flag.';

    private const LOCK = 'hrm.system-update';

    /** Whether the deployments table exists (it does not until the update that creates it has run). */
    private ?bool $tableReady = null;

    public function __construct(private readonly PendingMigrations $migrations, private readonly DatabaseBackup $backup) {}

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable('deployments');
        } catch (Throwable) {
            return false;
        }
    }

    /** The version of the code on the server. */
    public function codeVersion(): string
    {
        return Changelog::latestVersion();
    }

    /** The version recorded by the last successful update; null before the first one. */
    public function installedVersion(): ?string
    {
        $version = $this->lastUpdate()?->release;

        return is_string($version) && $version !== '' ? $version : null;
    }

    public function lastUpdate(): ?Deployment
    {
        if (! self::tableExists()) {
            return null;
        }

        return Deployment::query()
            ->where('type', DeploymentType::Update->value)
            ->where('status', DeploymentStatus::Success->value)
            ->latest('id')
            ->first();
    }

    /** The most recent attempt, when it failed and nothing succeeded after it. */
    public function lastFailure(): ?Deployment
    {
        if (! self::tableExists()) {
            return null;
        }

        $latest = Deployment::query()->where('type', DeploymentType::Update->value)->latest('id')->first();

        return $latest !== null && $latest->status === DeploymentStatus::Failed ? $latest : null;
    }

    /** @return list<string> */
    public function pendingMigrations(): array
    {
        return $this->migrations->names();
    }

    public function available(): bool
    {
        return $this->pendingMigrations() !== [] || $this->installedVersion() !== $this->codeVersion();
    }

    /**
     * Applies the update now. With database changes waiting, a backup is taken first; when it cannot be made nothing is
     * changed, unless the Admin chose to go on without one. Returns the deployment row (saved once the table exists).
     *
     * @throws RuntimeException when another update is already running
     */
    public function run(User $by, bool $withoutBackup = false): Deployment
    {
        $lock = Cache::lock(self::LOCK, 900);

        if (! $lock->get()) {
            throw new RuntimeException('Another update is already running. Wait a minute and reload the page.');
        }

        @set_time_limit(600);

        try {
            return $this->apply($by, $withoutBackup);
        } finally {
            $lock->release();
            self::forgetFlag();
        }
    }

    private function apply(User $by, bool $withoutBackup): Deployment
    {
        $this->tableReady = null;
        $from = $this->installedVersion();
        $to = $this->codeVersion();
        $pending = $this->pendingMigrations();

        $deployment = new Deployment([
            'type' => DeploymentType::Update,
            'status' => DeploymentStatus::Running,
            'release' => $to,
            'started_at' => now(),
            'triggered_by' => $by->getKey(),
            'pending_migrations' => $pending,
            'migrations_run' => [],
            'meta' => ['from' => $from, 'to' => $to, 'without_backup' => $withoutBackup && $pending !== []],
        ]);
        $deployment->appendLog('System update to version '.$to.' started by '.$by->name.'.');
        $this->persist($deployment);

        try {
            if ($pending === []) {
                $deployment->appendLog('No database changes in this update.');
            } else {
                $count = count($pending);
                $deployment->appendLog($count.' database '.($count === 1 ? 'change' : 'changes').' to apply: '.implode(', ', $pending));

                if ($withoutBackup) {
                    $deployment->appendLog('Step 1/4  Backup skipped: the Admin chose to update without one.');
                } else {
                    $deployment->appendLog('Step 1/4  Backing up the database');
                    $backup = $this->backup->run();
                    $deployment->appendLog($backup['output'] !== '' ? $backup['output'] : ($backup['ok'] ? 'Backup finished.' : 'The backup gave no output.'));

                    if (! $backup['ok']) {
                        $deployment->mergeMeta(['backup_failed' => true]);

                        return $this->fail($deployment, $by, 'The database backup could not be made, so nothing was changed.');
                    }
                }
                $this->persist($deployment);

                $deployment->appendLog('Step 2/4  Applying the database changes');
                $exit = Artisan::call('migrate', ['--force' => true]);
                $deployment->appendLog(trim(Artisan::output()));

                if ($exit !== 0) {
                    return $this->fail($deployment, $by, 'The database changes stopped with an error.');
                }
                $this->persist($deployment);
            }

            // What each role may do is written in config/hrm.php; the stored copy follows it on every update.
            $deployment->appendLog('Step 3/4  Bringing roles and permissions in line with this version');
            $exit = Artisan::call('hrm:sync-access');
            $deployment->appendLog(trim(Artisan::output()));

            if ($exit !== 0) {
                return $this->fail($deployment, $by, 'Roles and permissions could not be brought in line.');
            }

            $deployment->appendLog('Step 4/4  Refreshing the caches');
            $this->refreshCaches($deployment);

            $after = $this->pendingMigrations();
            $ran = array_values(array_diff($pending, $after));

            $deployment->pending_migrations = $after;
            $deployment->migrations_run = $ran;
            $deployment->appendLog('Done. Lumino HRM is on version '.$to.'.');
            $deployment->markSuccess();
            $this->persist($deployment);

            // After the fact on purpose: the database changes cannot be rolled back, so a logging failure must not
            // report a finished update as failed.
            Audit::tryRecord('system', 'System updated to version '.$to, $this->subject($deployment), [
                'attributes' => [
                    'from' => $from ?? 'none',
                    'to' => $to,
                    'database changes' => count($ran),
                    'backup' => $pending === [] ? 'not needed' : ($withoutBackup ? 'skipped' : 'taken'),
                ],
            ], $by);
        } catch (Throwable $e) {
            report($e);

            return $this->fail($deployment, $by, $e->getMessage());
        }

        return $deployment;
    }

    private function fail(Deployment $deployment, User $by, string $reason): Deployment
    {
        $deployment->markFailed($reason);

        try {
            $this->persist($deployment);
        } catch (Throwable $e) {
            report($e);
        }

        Audit::tryRecord('system', 'System update to version '.$deployment->release.' failed', $this->subject($deployment), [
            'attributes' => ['reason' => $reason],
        ], $by);

        return $deployment;
    }

    /** Saves the row once the deployments table exists (on the update that creates it, from step 2 on). */
    private function persist(Deployment $deployment): void
    {
        if ($this->tableReady !== true) {
            $this->tableReady = self::tableExists();
        }

        if ($this->tableReady) {
            $deployment->save();
        }
    }

    private function subject(Deployment $deployment): ?Deployment
    {
        return $deployment->exists ? $deployment : null;
    }

    /**
     * Clears the config, route, view and event caches, then rebuilds the config and route caches if they were built
     * before (on the server they are). A rebuild that fails leaves that cache cleared: the app keeps working, a little
     * slower, until the next deploy builds it again.
     */
    private function refreshCaches(Deployment $deployment): void
    {
        $rebuild = array_keys(array_filter([
            'config:cache' => app()->configurationIsCached(),
            'route:cache' => app()->routesAreCached(),
        ]));

        foreach (['config:clear', 'route:clear', 'view:clear', 'event:clear'] as $command) {
            try {
                Artisan::call($command);
            } catch (Throwable $e) {
                // For example view:clear on a server whose compiled-view folder does not exist yet: nothing to clear.
                $deployment->appendLog('Skipped '.$command.': '.$e->getMessage());
            }
        }
        $deployment->appendLog('Caches cleared.');

        foreach ($rebuild as $command) {
            try {
                $ok = Artisan::call($command) === 0;
            } catch (Throwable $e) {
                report($e);
                $ok = false;
            }
            $deployment->appendLog($ok ? 'Rebuilt: '.$command : 'Could not rebuild '.$command.'; it stays cleared until the next deploy.');
        }
    }

    /**
     * Whether an update is waiting, for the sidebar badge. Kept for a minute so it costs no look at the migrations on
     * every page; safe on a server whose tables do not exist yet.
     *
     * @return array{available: bool, version: string, pending: int}
     */
    public static function flag(): array
    {
        $version = Changelog::latestVersion();

        try {
            return Cache::remember(self::FLAG.$version, 60, function () use ($version): array {
                $updater = app(self::class);

                return ['available' => $updater->available(), 'version' => $version, 'pending' => count($updater->pendingMigrations())];
            });
        } catch (Throwable) {
            return ['available' => false, 'version' => $version, 'pending' => 0];
        }
    }

    public static function forgetFlag(): void
    {
        try {
            Cache::forget(self::FLAG.Changelog::latestVersion());
        } catch (Throwable) {
            // No cache table yet: nothing was stored.
        }
    }
}
