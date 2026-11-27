<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use App\Enums\DeploymentType;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One system update (Updates screen): the version it installed, its step-by-step log, and the database changes that were
 * waiting and that it applied. Written by App\Support\System\SystemUpdater only.
 *
 * The log helpers change the attributes only; SystemUpdater saves the row (it may not be able to yet: on the update that
 * creates this table the row is first saved once the database changes have run).
 *
 * @property int $id
 * @property DeploymentType $type
 * @property DeploymentStatus $status
 * @property string|null $release
 * @property string|null $log
 * @property list<string>|null $pending_migrations
 * @property list<string>|null $migrations_run
 * @property array<string, mixed>|null $meta
 * @property int|null $triggered_by
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Deployment extends Model
{
    protected $fillable = [
        'type', 'status', 'release', 'log', 'pending_migrations', 'migrations_run', 'meta', 'triggered_by', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => DeploymentType::class,
            'status' => DeploymentStatus::class,
            'pending_migrations' => 'array',
            'migrations_run' => 'array',
            'meta' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /** Adds one timestamped line to the log (not saved). */
    public function appendLog(string $line): void
    {
        $line = rtrim($line);

        if ($line === '') {
            return;
        }

        $current = rtrim((string) $this->log);
        $this->log = ($current === '' ? '' : $current."\n").'['.now()->format('H:i:s').'] '.$line;
    }

    /** @param  array<string, mixed>  $values */
    public function mergeMeta(array $values): void
    {
        $this->meta = [...($this->meta ?? []), ...$values];
    }

    public function markSuccess(): void
    {
        $this->status = DeploymentStatus::Success;
        $this->finished_at = now();
    }

    public function markFailed(string $reason): void
    {
        $this->appendLog('FAILED: '.$reason);
        $this->status = DeploymentStatus::Failed;
        $this->finished_at = now();
    }
}
