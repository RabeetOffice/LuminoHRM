<?php

namespace App\Http\Controllers\System;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use App\Http\Controllers\Controller;
use App\Models\Deployment;
use App\Models\User;
use App\Support\System\Changelog;
use App\Support\System\SystemUpdater;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Throwable;

/**
 * System > Updates (Admins: settings.manage). What changed in each version, whether new code is waiting, and the
 * "Update system" button that applies it (App\Support\System\SystemUpdater).
 */
class UpdatesController extends Controller
{
    /** Session key holding a failed attempt that could not be stored (the deployments table did not exist yet). */
    private const UNSTORED_FAILURE = 'system_update_failure';

    private const HISTORY = 8;

    public function index(Request $request, SystemUpdater $updater): Response
    {
        $installed = $updater->installedVersion();
        $code = $updater->codeVersion();
        $pending = $updater->pendingMigrations();
        $available = $pending !== [] || $installed !== $code;
        $lastUpdate = $updater->lastUpdate()?->load('triggeredBy');

        $waiting = match (true) {
            ! $available => [],
            $installed === null => [$code],
            default => array_column(Changelog::since($installed), 'version'),
        };

        if ($available && $waiting === []) {
            // Same version, more database changes: the newest version is the one still being applied.
            $waiting = [$code];
        }

        $failure = $updater->lastFailure();
        $unstored = $request->session()->get(self::UNSTORED_FAILURE);

        return Inertia::render('system/updates', [
            'available' => $available,
            'codeVersion' => $code,
            'installedVersion' => $installed,
            'lastUpdate' => $lastUpdate === null ? null : [
                'finishedAt' => $lastUpdate->finished_at?->toIso8601String(),
                'by' => $lastUpdate->triggeredBy?->name,
            ],
            'pending' => $pending,
            'failure' => match (true) {
                $failure !== null => [
                    'id' => $failure->id,
                    'backupFailed' => (bool) ($failure->meta['backup_failed'] ?? false),
                    'log' => (string) $failure->log,
                ],
                is_array($unstored) => [
                    'id' => null,
                    'backupFailed' => (bool) ($unstored['backupFailed'] ?? false),
                    'log' => (string) ($unstored['log'] ?? ''),
                ],
                default => null,
            },
            'releases' => array_map(fn (array $release) => [
                'version' => $release['version'],
                'date' => self::dateLabel($release['date']),
                'title' => $release['title'],
                'changes' => $release['changes'],
                'state' => match (true) {
                    in_array($release['version'], $waiting, true) => 'waiting',
                    $installed !== null && version_compare($release['version'], $installed, '<=') => 'installed',
                    default => null,
                },
            ], Changelog::releases()),
            'types' => Changelog::TYPES,
            'history' => $this->history(),
        ]);
    }

    public function run(Request $request, SystemUpdater $updater): RedirectResponse
    {
        $data = $request->validate([
            'without_backup' => ['sometimes', 'boolean'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $withoutBackup = (bool) ($data['without_backup'] ?? false);

        if (! $updater->available()) {
            return back()->with('info', 'Lumino HRM is already up to date (version '.$updater->codeVersion().').');
        }

        // Changing the database (or doing it without a backup) asks for the Admin's password again.
        if (($withoutBackup || $updater->pendingMigrations() !== []) && ! Hash::check((string) ($data['password'] ?? ''), $user->password)) {
            throw ValidationException::withMessages(['password' => 'The password is incorrect.']);
        }

        try {
            $deployment = $updater->run($user, $withoutBackup);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($deployment->status === DeploymentStatus::Success) {
            $applied = count($deployment->migrations_run ?? []);

            return back()->with('success', 'Lumino HRM is on version '.$deployment->release.'. '.match ($applied) {
                0 => 'No database changes were needed.',
                1 => '1 database change applied.',
                default => $applied.' database changes applied.',
            });
        }

        $response = back()->with('error', 'The update did not finish. Nothing is lost: the reason is shown on this page.');

        if (! $deployment->exists) {
            $response->with(self::UNSTORED_FAILURE, [
                'backupFailed' => (bool) ($deployment->meta['backup_failed'] ?? false),
                'log' => (string) $deployment->log,
            ]);
        }

        return $response;
    }

    /** @return array<int, array<string, mixed>> */
    private function history(): array
    {
        if (! SystemUpdater::tableExists()) {
            return [];
        }

        return Deployment::query()
            ->where('type', DeploymentType::Update->value)
            ->with('triggeredBy:id,name')
            ->latest('id')
            ->limit(self::HISTORY)
            ->get()
            ->map(fn (Deployment $entry) => [
                'id' => $entry->id,
                'version' => $entry->release,
                'status' => $entry->status->value,
                'statusLabel' => $entry->status->label(),
                'at' => ($entry->finished_at ?? $entry->started_at ?? $entry->created_at)?->toIso8601String(),
                'by' => $entry->triggeredBy?->name,
                'applied' => count($entry->migrations_run ?? []),
                'log' => (string) $entry->log,
            ])
            ->values()
            ->all();
    }

    private static function dateLabel(string $date): string
    {
        try {
            return $date === '' ? '' : CarbonImmutable::parse($date)->format('j F Y');
        } catch (Throwable) {
            return $date;
        }
    }
}
