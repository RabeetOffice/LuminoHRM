<?php

namespace App\Models;

use Database\Factories\EmployeeContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Home address and emergency contact: a restricted field group (the "contact" group in EmployeeAccess).
 *
 * @property int $id
 * @property int $employee_id
 * @property string|null $address
 * @property string|null $emergency_name
 * @property string|null $emergency_phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmployeeContact extends Model
{
    /** @use HasFactory<EmployeeContactFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['employee_id', 'address', 'emergency_name', 'emergency_phone'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly(['address', 'emergency_name', 'emergency_phone'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Employee contact details {$event}");
    }

    public function auditLabel(): string
    {
        return $this->employee->full_name.' (contact details)';
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
