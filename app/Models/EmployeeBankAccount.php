<?php

namespace App\Models;

use Database\Factories\EmployeeBankAccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Where salary is paid: a restricted field group ("banking" in EmployeeAccess).
 *
 * @property int $id
 * @property int $employee_id
 * @property string|null $bank_name
 * @property string|null $account_title
 * @property string|null $account_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EmployeeBankAccount extends Model
{
    /** @use HasFactory<EmployeeBankAccountFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = ['employee_id', 'bank_name', 'account_title', 'account_number'];

    /** `account_number` is listed so a change is noticed; config('hrm.audit.masked_attributes') keeps its value out of the log. */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('employees')
            ->logOnly(['bank_name', 'account_title', 'account_number'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->setDescriptionForEvent(fn (string $event) => "Employee bank account {$event}");
    }

    public function auditLabel(): string
    {
        return $this->employee->full_name.' (bank account)';
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
