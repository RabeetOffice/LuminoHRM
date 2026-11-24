<?php

namespace App\Models;

use Database\Factories\DepartmentFactory;
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
 * @property int|null $head_employee_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Department extends Model
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization')
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Department {$event}");
    }

    /** Last known name, stored with each entry so it survives deletion. */
    public function auditLabel(): string
    {
        return $this->name;
    }

    /** @return HasMany<Designation, $this> */
    public function designations(): HasMany
    {
        return $this->hasMany(Designation::class);
    }

    /** @return HasMany<Employee, $this> */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * The employee who heads this department, if one is set.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function head(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'head_employee_id');
    }

    /**
     * Why this department cannot be deleted right now, or null when it can. The restrict foreign keys
     * (designations, employees) are the backstop for a row that appears after this check.
     */
    public function deleteBlocker(): ?string
    {
        if ($this->designations()->exists()) {
            return "Move or delete this department's designations first.";
        }

        if ($this->employees()->exists()) {
            return "Move this department's employees first.";
        }

        return null;
    }
}
