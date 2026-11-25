<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\DayCalculator;
use App\Support\Attendance\DayInput;
use App\Support\Audit\Audit;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one code path that corrects an employee-day by hand. The Daily Attendance screen calls it, and so does the
 * approval of a correction request (Task 8, with the note "Correction request #id"), so both give the same result.
 *
 *   app(ApplyManualCorrection::class)->handle($employee, '2026-11-24', '09:05', '18:10', null, 'Forgot to punch', $user);
 *
 * One transaction, the row locked first:
 *   - The entered times are wall-clock times on $date ("H:i"). A clock-out earlier than the clock-in, or earlier than
 *     the day cutoff with no clock-in, is the next morning's after-midnight clock-out, allowed only before the day
 *     cutoff; otherwise it is refused (ValidationException on clock_out). A clock-in before the cutoff is refused
 *     (it would belong to the previous day).
 *   - Without a status override the day is decided by DayCalculator from the entered times, with the shift rules of
 *     that day: the row's shift_rules snapshot when it has a usable one, otherwise the employee's shift in force now.
 *     With an override the status is stored as given, the times are stored, worked minutes come from the times and the
 *     late, early and overtime minutes are 0.
 *   - The row is written with is_manual = true, shift_rules (the rules used), closed = the date is before
 *     AttendanceClock::attendanceToday(), needs_rebuild = false and needs_attention = false (a person has looked at it).
 *   - One audit entry (area attendance, subject the employee) holds the date, the old and new values, the reason and
 *     the note.
 *
 * The caller checks permission and scope; this class checks the date (not in the future, inside the employment window)
 * and the values.
 */
class ApplyManualCorrection
{
    /** The statuses a person may set by hand: every status except on_leave (only the Leave phase produces that). */
    public const OVERRIDE_STATUSES = ['present', 'late', 'half_day', 'absent', 'holiday', 'weekend'];

    /** The columns an audit entry compares. */
    private const AUDITED = [
        'clock_in', 'clock_out', 'status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'is_manual',
    ];

    public function __construct(
        private readonly DayCalculator $calculator,
        private readonly AttendanceClock $clock,
    ) {}

    /**
     * @param  string  $date  "Y-m-d"
     * @param  string|null  $clockIn  "H:i" on $date, or null
     * @param  string|null  $clockOut  "H:i" on $date (or after midnight before the cutoff), or null
     * @param  string|null  $status  an override from OVERRIDE_STATUSES, or null to calculate
     * @param  string  $reason  why (5-255 characters, checked by the caller's request)
     * @param  string|null  $note  stored on the row and in the audit entry, e.g. "Correction request #12"
     *
     * @throws ValidationException a value cannot be used (keys: date, clock_in, clock_out, status)
     */
    public function handle(
        Employee $employee,
        string $date,
        ?string $clockIn,
        ?string $clockOut,
        ?string $status,
        string $reason,
        User $by,
        ?string $note = null,
    ): Attendance {
        $date = $this->date($date);
        $today = $this->clock->attendanceToday();

        if ($date > $today) {
            throw ValidationException::withMessages(['date' => 'A future day cannot be corrected.']);
        }

        if (! $this->employedOn($employee, $date)) {
            throw ValidationException::withMessages(['date' => "{$employee->full_name} was not employed on this day."]);
        }

        if ($status !== null && ! in_array($status, self::OVERRIDE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid status.']);
        }

        if ($clockIn === null && $clockOut === null && $status === null) {
            throw ValidationException::withMessages(['clock_in' => 'Enter a clock-in or clock-out time, or choose a status.']);
        }

        $cutoff = $this->clock->dayCutoff();
        [$in, $out] = $this->times($date, $clockIn, $clockOut, $cutoff);

        return DB::transaction(function () use ($employee, $date, $today, $in, $out, $status, $reason, $by, $note, $cutoff): Attendance {
            $row = Attendance::query()->where('employee_id', $employee->id)->onDate($date)->lockForUpdate()->first();

            $old = $row === null ? null : $this->audited($row);

            $snapshot = $row !== null ? $this->snapshotOf($row) : null;
            $rules = $snapshot ?? $this->rulesOf(Shift::forEmployee($employee));
            $shiftId = $snapshot !== null ? ($row->shift_id ?? $snapshot['shift_id']) : $rules['shift_id'];
            $closed = $date < $today;

            $overtimeMin = settings('attendance.overtime_min_minutes');
            $overtimeMin = is_int($overtimeMin) && $overtimeMin >= 0 ? $overtimeMin : 0;

            $result = $this->calculator->calculate(new DayInput(
                date: $date,
                shiftStart: $rules['start_time'],
                shiftEnd: $rules['end_time'],
                graceMinutes: $rules['grace_minutes'],
                halfDayHours: $rules['half_day_hours'],
                weeklyOffs: $rules['weekly_offs'],
                isHoliday: Holiday::query()->onDate($date)->exists(),
                isExempt: (bool) $employee->attendance_exempt,
                punches: array_values(array_filter([$in, $out])),
                closed: $closed,
                overtimeMinMinutes: $overtimeMin,
                onLeave: false,
                duplicatePunchMinutes: 0, // entered times are deliberate, never a double tap
                dayCutoff: $cutoff,
            ));

            if ($status !== null) {
                $values = [
                    'status' => $status,
                    'clock_in' => $in,
                    'clock_out' => $out,
                    'worked_minutes' => $in !== null && $out !== null ? $this->minutesBetween($in, $out) : 0,
                    'late_minutes' => 0,
                    'early_minutes' => 0,
                    'overtime_minutes' => 0,
                ];
            } elseif ($result === null) {
                // Only an open day with no times gives no result, and that was refused above.
                throw ValidationException::withMessages(['clock_in' => 'Enter a clock-in or clock-out time, or choose a status.']);
            } else {
                // The entered times are stored as entered: the calculator decides which one is the clock-in when only
                // one is given, but a person said which it was.
                $values = [
                    'status' => $result->status,
                    'clock_in' => $in,
                    'clock_out' => $out,
                    'worked_minutes' => $result->workedMinutes,
                    'late_minutes' => $result->lateMinutes,
                    'early_minutes' => $result->earlyMinutes,
                    'overtime_minutes' => $result->overtimeMinutes,
                ];
            }

            $row ??= new Attendance(['employee_id' => $employee->id, 'date' => $date]);

            $row->fill([
                ...$values,
                'shift_id' => $shiftId,
                'shift_rules' => $rules,
                'is_manual' => true,
                'needs_rebuild' => false,
                'needs_attention' => false,
                'closed' => $closed,
                'note' => $note !== null ? mb_substr($note, 0, 255) : null,
            ])->save();

            Audit::record('attendance', 'Attendance corrected', $employee, array_filter([
                'date' => $date,
                'old' => $old ?? array_fill_keys(self::AUDITED, null),
                'attributes' => $this->audited($row),
                'reason' => Audit::clean($reason),
                'note' => $note !== null ? Audit::clean($note) : null,
            ], fn ($v) => $v !== null), $by);

            return $row;
        });
    }

    /**
     * The audited values of a row, times as "Y-m-d H:i" (naive, never shifted).
     *
     * @return array<string, mixed>
     */
    public function audited(Attendance $row): array
    {
        $attributes = $row->getAttributes();

        return [
            'clock_in' => $this->naive($attributes['clock_in'] ?? null),
            'clock_out' => $this->naive($attributes['clock_out'] ?? null),
            'status' => $row->status,
            'worked_minutes' => (int) $row->worked_minutes,
            'late_minutes' => (int) $row->late_minutes,
            'early_minutes' => (int) $row->early_minutes,
            'overtime_minutes' => (int) $row->overtime_minutes,
            'is_manual' => (bool) $row->is_manual,
        ];
    }

    /**
     * The entered "H:i" times as naive "Y-m-d H:i:s" values.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function times(string $date, ?string $clockIn, ?string $clockOut, string $cutoff): array
    {
        $nextDay = (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

        if ($clockIn !== null && $clockIn < $cutoff) {
            throw ValidationException::withMessages([
                'clock_in' => "The clock-in must be at or after the day cutoff ({$cutoff}). An earlier time belongs to the day before.",
            ]);
        }

        $in = $clockIn !== null ? "{$date} {$clockIn}:00" : null;
        $out = null;

        if ($clockOut !== null) {
            $afterMidnight = $clockOut < $cutoff;

            if ($clockIn !== null && $clockOut <= $clockIn && ! $afterMidnight) {
                throw ValidationException::withMessages([
                    'clock_out' => "The clock-out must be after the clock-in. A clock-out after midnight must be before the day cutoff ({$cutoff}).",
                ]);
            }

            $out = ($afterMidnight ? $nextDay : $date)." {$clockOut}:00";
        }

        return [$in, $out];
    }

    /** Inside the employment window: from the joining date to the last working day (if any). */
    private function employedOn(Employee $employee, string $date): bool
    {
        $from = $employee->joining_date->format('Y-m-d');
        $last = $employee->last_working_day?->format('Y-m-d');

        return $date >= $from && ($last === null || $date <= $last);
    }

    /**
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>}
     */
    private function rulesOf(Shift $shift): array
    {
        return [
            'shift_id' => (int) $shift->id,
            'name' => (string) $shift->name,
            'start_time' => (string) $shift->start_time,
            'end_time' => (string) $shift->end_time,
            'grace_minutes' => (int) $shift->grace_minutes,
            'half_day_hours' => (float) $shift->half_day_hours,
            'weekly_offs' => $shift->weekly_offs, // a list: the Shift model normalises it on every save
        ];
    }

    /**
     * The row's stored shift-rule snapshot, or null when it has none or it is unusable.
     *
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>}|null
     */
    private function snapshotOf(Attendance $row): ?array
    {
        $raw = $row->getAttributes()['shift_rules'] ?? null;
        $rules = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        if (! is_array($rules)
            || ! is_string($rules['start_time'] ?? null) || ! is_string($rules['end_time'] ?? null)
            || ! is_int($rules['grace_minutes'] ?? null) || ! is_numeric($rules['half_day_hours'] ?? null)
            || ! is_array($rules['weekly_offs'] ?? null)) {
            return null;
        }

        return [
            'shift_id' => is_int($rules['shift_id'] ?? null) ? $rules['shift_id'] : null,
            'name' => is_string($rules['name'] ?? null) ? $rules['name'] : '',
            'start_time' => $rules['start_time'],
            'end_time' => $rules['end_time'],
            'grace_minutes' => $rules['grace_minutes'],
            'half_day_hours' => (float) $rules['half_day_hours'],
            'weekly_offs' => array_values(array_map(fn (mixed $d): string => is_scalar($d) ? (string) $d : '', $rules['weekly_offs'])),
        ];
    }

    private function minutesBetween(string $from, string $to): int
    {
        $zone = new DateTimeZone('UTC'); // a label only: both values are naive wall-clock times
        $seconds = (new DateTimeImmutable($to, $zone))->getTimestamp() - (new DateTimeImmutable($from, $zone))->getTimestamp();

        return max(0, intdiv($seconds, 60));
    }

    private function naive(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 16);
    }

    private function date(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
            throw new InvalidArgumentException("Not a date [{$date}].");
        }

        return $parsed->format('Y-m-d');
    }
}
