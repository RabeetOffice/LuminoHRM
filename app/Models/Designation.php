<?php

namespace App\Models;

use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property string $name
 * @property int $department_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Designation extends Model
{
    /** @use HasFactory<DesignationFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['name', 'department_id'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')
            ->logOnly(['name', 'department_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Designation {$event}");
    }

    /** Last known name, stored with each entry so it survives deletion. */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Why this designation cannot be deleted right now, or null when it can.
     * Nothing references designations yet. When employees arrive (Phase 2,
     * with a restrict foreign key), return a message here when any employee
     * holds this designation and the controller will refuse the delete.
     */
    public function deleteBlocker(): ?string
    {
        return null;
    }
}
