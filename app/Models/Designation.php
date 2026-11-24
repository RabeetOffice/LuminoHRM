<?php

namespace App\Models;

use Database\Factories\DesignationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

    /**
     * Called by the audit log before an entry is saved: shows the department by name next to its id,
     * for both the old and the new value.
     */
    public function beforeActivityLogged(Model $activity, string $event): void
    {
        $properties = $activity->properties?->toArray() ?? [];

        foreach (['old', 'attributes'] as $side) {
            if (isset($properties[$side]) && array_key_exists('department_id', $properties[$side])) {
                $properties[$side]['department'] = Department::query()->whereKey($properties[$side]['department_id'])->value('name');
            }
        }

        $activity->properties = collect($properties);
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

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Why this designation cannot be deleted right now, or null when it can. The restrict foreign key
     * (employees) is the backstop for a row that appears after this check.
     */
    public function deleteBlocker(): ?string
    {
        if ($this->employees()->exists()) {
            return "Move this designation's employees first.";
        }

        return null;
    }
}
