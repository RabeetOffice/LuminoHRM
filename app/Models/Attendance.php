<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Casts\NaiveDateTime;
use App\Models\Concerns\HasNaiveDateScopes;
use Carbon\CarbonImmutable;
use Database\Factories\AttendanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One employee's attendance for one day (unique employee_id + date). Written by the rebuild service from the punches,
 * or by hand (is_manual = true; a rebuild never overwrites a manual day). Screens read this table only.
 *
 * date is stored as "Y-m-d" (App\Casts\NaiveDate). clock_in / clock_out are naive machine times stored exactly as the
 * punches were (App\Casts\NaiveDateTime). Not audited by the model: manual corrections write their own entry.
 *
 * shift_rules: the rules the day was decided with (AttendanceRebuilder, ApplyManualCorrection): the shift's times and
 * rules, the settings in force (overtime_min_minutes, duplicate_punch_minutes) and whether the person was
 * attendance_exempt. Rows written before those last three keys existed lack them; AttendanceRebuilder::rulesFor fills
 * them from the current values.
 *
 * status_overridden: a manual row whose STATUS was chosen by hand (an ApplyManualCorrection override). The status is
 * kept as given (minutes still come from the entered times), the nightly close only marks the row closed, and the
 * correction dialogs start on that status. False for automatic rows and calculated corrections; a reset clears it.
 *
 * @property int $id
 * @property int $employee_id
 * @property CarbonImmutable $date
 * @property int|null $shift_id
 * @property array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, overtime_min_minutes?: int, duplicate_punch_minutes?: int, attendance_exempt?: bool}|null $shift_rules see above
 * @property CarbonImmutable|null $clock_in
 * @property CarbonImmutable|null $clock_out
 * @property int $worked_minutes
 * @property int $late_minutes
 * @property int $early_minutes
 * @property int $overtime_minutes
 * @property string|null $status config('hrm.attendance.statuses'); null = pending calculation (not yet rebuilt)
 * @property string|null $leave_half am | pm: the half of the day on approved half-day leave (status then describes the
 *                                   worked half); null = no half-day leave
 * @property bool $is_manual
 * @property bool $status_overridden see above
 * @property bool $needs_attention
 * @property bool $needs_rebuild
 * @property bool $closed
 * @property string|null $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Attendance extends Model
{
    /** @use HasFactory<AttendanceFactory> */
    use HasFactory;

    use HasNaiveDateScopes;

    protected $fillable = [
        'employee_id', 'date', 'shift_id', 'shift_rules', 'clock_in', 'clock_out', 'worked_minutes', 'late_minutes', 'early_minutes',
        'overtime_minutes', 'status', 'leave_half', 'is_manual', 'status_overridden', 'needs_attention', 'needs_rebuild', 'closed', 'note',
    ];

    protected $attributes = [
        'worked_minutes' => 0,
        'late_minutes' => 0,
        'early_minutes' => 0,
        'overtime_minutes' => 0,
        'is_manual' => false,
        'status_overridden' => false,
        'needs_attention' => false,
        'needs_rebuild' => false,
        'closed' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => NaiveDate::class,
            'clock_in' => NaiveDateTime::class,
            'clock_out' => NaiveDateTime::class,
            'shift_rules' => 'array',
            'worked_minutes' => 'integer',
            'late_minutes' => 'integer',
            'early_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'is_manual' => 'boolean',
            'status_overridden' => 'boolean',
            'needs_attention' => 'boolean',
            'needs_rebuild' => 'boolean',
            'closed' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Attendance $attendance): void {
            // Null is allowed: the day is pending calculation (AttendanceRebuilder sets the status).
            if ($attendance->status !== null && ! in_array($attendance->status, (array) config('hrm.attendance.statuses'), true)) {
                throw new \InvalidArgumentException("Unknown attendance status [{$attendance->status}].");
            }

            if ($attendance->leave_half !== null && ! in_array($attendance->leave_half, (array) config('hrm.leave.halves'), true)) {
                throw new \InvalidArgumentException("Unknown leave half [{$attendance->leave_half}].");
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }
}
