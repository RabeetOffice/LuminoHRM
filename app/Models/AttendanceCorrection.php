<?php

namespace App\Models;

use App\Casts\ClockTime;
use App\Casts\NaiveDate;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceCorrectionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An employee's request to correct one day's clock-in and/or clock-out. Decided by the manager, then HR.
 * The actions that create and decide it write the audit entries (area attendance).
 *
 * date: "Y-m-d" (App\Casts\NaiveDate). requested_in / requested_out: "H:i" (App\Casts\ClockTime).
 * manager_id / hr_id: the login accounts (users) that decided.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property string|null $requested_in
 * @property string|null $requested_out
 * @property string $reason
 * @property string $status config('hrm.attendance.correction_statuses')
 * @property int|null $manager_id
 * @property Carbon|null $manager_decided_at
 * @property int|null $hr_id
 * @property Carbon|null $hr_decided_at
 * @property string|null $decision_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AttendanceCorrection extends Model
{
    /** @use HasFactory<AttendanceCorrectionFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id', 'date', 'requested_in', 'requested_out', 'reason', 'status',
        'manager_id', 'manager_decided_at', 'hr_id', 'hr_decided_at', 'decision_note',
    ];

    protected $attributes = ['status' => 'pending'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => NaiveDate::class,
            'requested_in' => ClockTime::class,
            'requested_out' => ClockTime::class,
            'manager_decided_at' => 'datetime',
            'hr_decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AttendanceCorrection $correction): void {
            if (! in_array($correction->status, (array) config('hrm.attendance.correction_statuses'), true)) {
                throw new \InvalidArgumentException("Unknown correction status [{$correction->status}].");
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<User, $this> */
    public function managerDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /** @return BelongsTo<User, $this> */
    public function hrDecider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hr_id');
    }
}
