<?php

namespace App\Actions\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\DayResult;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use App\Support\PayrollLock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The one code path that corrects an employee-day by hand. The Daily Attendance screen calls it, and so does the
 * approval of a correction request (Task 8, with the note "Correction request #id"), so both give the same result.
 *
 *   app(ApplyManualCorrection::class)->handle($employee, '2026-11-24', '09:05', '18:10', null, 'Forgot to punch', $user);
 *
 * Refused (ValidationException) before anything is written:
 *   - a future date, or a day the person cannot be corrected on (employmentRefusal: before max(joining date,
 *     attendance start date), after the last working day, or inactive / resigned / terminated with no last working day);
 *   - a day in a locked payroll month (an approved or paid run: PayrollLock::message, key date), checked again as the
 *     first statement of the transaction (shared lock on payroll_runs before the row lock);
 *   - no times and no status; times with the status absent; on the open day a clock-in or clock-out later than now;
 *   - a clock-in before the day cutoff (it belongs to the day before); a clock-out at or before the clock-in unless it
 *     is an after-midnight clock-out before the cutoff (stored on the next calendar day).
 *
 * How the day is decided (AttendanceRebuilder::decideEntered, with the row's rule snapshot or the shift in force):
 *   - A day with a clock-out is decided as a complete day (closed-day rules: half day, early minutes, single-time flag)
 *     even when it is today. A day with a clock-in only on the open day is provisional (open-day rules); the nightly
 *     close finalises it from the stored times (AttendanceRebuilder::closeDay).
 *   - Without a status override the calculator decides everything, and the day's approved leave is kept (read from
 *     leave_days by the rebuild's rules, AttendanceRebuilder::leaveOn: full or am+pm = full day, leave before the
 *     attendance start date ignored): on a half-leave day only the worked half is judged (never late, never early) and
 *     leave_half is kept; a full leave day stays on_leave with the entered times and worked minutes recorded (no late,
 *     early or overtime minutes, never flagged). A correction never sets on_leave by itself.
 *   - With an override only the STATUS is forced (status_overridden = true, leave ignored, leave_half null); minutes
 *     still come from the entered times:
 *       present   late 0; worked, early and overtime as calculated
 *       late      late = the real minutes after the shift start (beyond the grace); worked, early, overtime as calculated
 *       half_day  late 0 (as the calculator does for a half day); worked, early, overtime as calculated
 *       holiday / weekend  worked = clock-in to clock-out, overtime = worked when at least the overtime minimum (the
 *                 off-day rule); late and early 0
 *       absent    no times (entered times are refused), all minutes 0
 *
 * One transaction, the row locked first (retried once if another request created the row at the same moment): the row
 * is written with is_manual = true, the full rule snapshot in shift_rules, closed = the date is before
 * AttendanceClock::attendanceToday(), needs_rebuild = false, needs_attention from the calculation (false for an
 * override), and the note (an earlier note is kept when none is given). One audit entry (area attendance, subject the
 * employee) holds the date, the old and new values, the reason and the note.
 *
 * The caller checks permission and scope (and, on the screens, mayCorrect); this class checks the date and the values.
 */
class ApplyManualCorrection
{
    /** The statuses a person may set by hand: every status except on_leave (only the Leave phase produces that). */
    public const OVERRIDE_STATUSES = ['present', 'late', 'half_day', 'absent', 'holiday', 'weekend'];

    /** The columns an audit entry compares. */
    private const AUDITED = [
        'clock_in', 'clock_out', 'status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'is_manual',
        'status_overridden',
    ];

    public function __construct(
        private readonly AttendanceRebuilder $rebuilder,
        private readonly AttendanceClock $clock,
    ) {}

    /**
     * @param  string  $date  "Y-m-d"
     * @param  string|null  $clockIn  "H:i" on $date, or null
     * @param  string|null  $clockOut  "H:i" on $date (or after midnight before the cutoff), or null
     * @param  string|null  $status  an override from OVERRIDE_STATUSES, or null to calculate
     * @param  string  $reason  why (5-255 characters, checked by the caller's request)
     * @param  string|null  $note  stored on the row and in the audit entry, e.g. "Correction request #12"; null keeps
     *                             the row's existing note
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

        $refusal = $this->employmentRefusal($employee, $date);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['date' => $refusal]);
        }

        // Checked here for a clear first message, and again as the first statement of the write's transaction.
        self::refuseLocked($date);

        if ($status !== null && ! in_array($status, self::OVERRIDE_STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose a valid status.']);
        }

        if ($clockIn === null && $clockOut === null && $status === null) {
            throw ValidationException::withMessages(['clock_in' => 'Enter a clock-in or clock-out time, or choose a status.']);
        }

        if ($status === 'absent' && ($clockIn !== null || $clockOut !== null)) {
            throw ValidationException::withMessages([
                'clock_in' => 'An absent day has no times. Clear the clock-in and clock-out, or choose another status.',
            ]);
        }

        [$in, $out] = $this->times($date, $clockIn, $clockOut, $this->clock->dayCutoff());

        if ($date === $today) {
            $now = $this->clock->now()->format('Y-m-d H:i:s');

            if ($in !== null && $in > $now) {
                throw ValidationException::withMessages(['clock_in' => 'The clock-in cannot be later than now.']);
            }

            if ($out !== null && $out > $now) {
                throw ValidationException::withMessages(['clock_out' => 'The clock-out cannot be later than now.']);
            }
        }

        $write = fn (): Attendance => DB::transaction(
            fn (): Attendance => $this->write($employee, $date, $today, $in, $out, $status, $reason, $by, $note),
            3,
        );

        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            // The row did not exist when looked for, and someone (punch ingestion, another editor) created it before
            // this insert: once more, now finding and locking that row.
            return $write();
        }
    }

    /**
     * Why $employee's attendance cannot be corrected on $date, or null when it can: the day must be on or after the
     * joining date and the attendance start date (when set), not after the last working day, and a person who is
     * inactive, resigned or terminated must have a last working day.
     */
    public function employmentRefusal(Employee $employee, string $date): ?string
    {
        $from = $employee->joining_date->format('Y-m-d');
        $last = $employee->last_working_day?->format('Y-m-d');
        $start = AttendanceClock::startDate();

        if (Leavers::withoutLastDay($employee)) {
            return "{$employee->full_name} has left and has no last working day, so their attendance cannot be corrected.";
        }

        if ($date < $from || ($last !== null && $date > $last)) {
            return "{$employee->full_name} was not employed on this day.";
        }

        if ($start !== null && $date < $start) {
            return 'Attendance starts on '.$start.': an earlier day cannot be corrected.';
        }

        return null;
    }

    /**
     * Refuses a day in a locked payroll month (approved or paid run): ValidationException [date] with
     * PayrollLock::message(). One query; inside a transaction it takes the shared lock on payroll_runs, so call it
     * before any row lock. Also used by the Daily screen's reset.
     *
     * @param  string  $date  "Y-m-d"
     *
     * @throws ValidationException
     */
    public static function refuseLocked(string $date): void
    {
        $month = substr($date, 0, 7);

        if (PayrollLock::isLocked($month)) {
            throw ValidationException::withMessages(['date' => PayrollLock::message($month)]);
        }
    }

    /**
     * Whether $by may correct $employee's attendance at all (on top of attendance.edit and the scope): never their own
     * record unless they are an Admin, and a person whose login is Admin or HR only when EmployeeRoles::mayTouchAccount
     * allows it (an Admin). $privilegedLogin may be passed when already known (a list), to avoid a query per person.
     */
    public static function mayCorrect(User $by, Employee $employee, ?bool $privilegedLogin = null): bool
    {
        if ($by->hasRole('admin')) {
            return true;
        }

        if ($by->employee_id !== null && (int) $by->employee_id === (int) $employee->id) {
            return false;
        }

        return $privilegedLogin === null
            ? EmployeeRoles::mayTouchAccount($by, $employee->user)
            : ! $privilegedLogin;
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
            'status_overridden' => (bool) $row->status_overridden,
        ];
    }

    /**
     * The audited values of a day with no row.
     *
     * @return array<string, mixed>
     */
    public function auditedMissing(): array
    {
        return [...array_fill_keys(self::AUDITED, null), 'is_manual' => false];
    }

    private function write(
        Employee $employee,
        string $date,
        string $today,
        ?string $in,
        ?string $out,
        ?string $status,
        string $reason,
        User $by,
        ?string $note,
    ): Attendance {
        // Lock order: the payroll run (shared, PayrollLock) before the attendance row, so a racing approval either
        // commits first (and this is refused) or waits for this save.
        self::refuseLocked($date);

        $row = Attendance::query()->where('employee_id', $employee->id)->onDate($date)->lockForUpdate()->first();

        $old = $row === null ? null : $this->audited($row);

        $rules = $this->rebuilder->rulesFor($employee, $row);
        $closed = $date < $today;

        // The day's approved leave (same rules as the rebuild) is kept unless the status is overridden: half-day leave
        // judges only the worked half; a full leave day stays on_leave with the entered times.
        $leave = $status === null ? $this->rebuilder->leaveOn((int) $employee->id, $date) : null;

        // A clock-out means the day is complete as entered: decided with the closed-day rules even when it is today.
        $result = $this->rebuilder->decideEntered(
            $rules, $date, $in, $out, $closed || $out !== null, null, $leave === 'full' ? null : $leave,
        );

        if ($result !== null && $leave === 'full') {
            $result = $this->rebuilder->withFullLeave($result);
        }

        $values = $status === null ? $this->calculated($result) : $this->overridden($status, $rules, $date, $in, $out, $result);

        $row ??= new Attendance(['employee_id' => $employee->id, 'date' => $date]);

        $row->fill([
            ...$values,
            // The entered times are stored as entered, and a single one keeps the role it was entered as (also in the
            // calculation: decideEntered passes it as DayInput::singleRole).
            'clock_in' => $status === 'absent' ? null : $in,
            'clock_out' => $status === 'absent' ? null : $out,
            'shift_id' => $rules['shift_id'] ?? $row->shift_id,
            'shift_rules' => $rules,
            // Always written, never left stale: the half from the day's approved leave, null with an override.
            'leave_half' => $status === null ? $result?->leaveHalf : null,
            'is_manual' => true,
            'status_overridden' => $status !== null,
            'needs_rebuild' => false,
            'closed' => $closed,
            'note' => $note !== null ? mb_substr($note, 0, 255) : $row->note,
        ])->save();

        Audit::record('attendance', 'Attendance corrected', $employee, array_filter([
            'date' => $date,
            'old' => $old ?? $this->auditedMissing(),
            'attributes' => $this->audited($row),
            'reason' => Audit::clean($reason),
            'note' => $note !== null ? Audit::clean($note) : null,
        ], fn ($v) => $v !== null), $by);

        return $row;
    }

    /** @return array<string, mixed> */
    private function calculated(?DayResult $result): array
    {
        if ($result === null) {
            // Only an open working day with no times gives no result, and that was refused before.
            throw ValidationException::withMessages(['clock_in' => 'Enter a clock-in or clock-out time, or choose a status.']);
        }

        return [
            'status' => $result->status,
            'worked_minutes' => $result->workedMinutes,
            'late_minutes' => $result->lateMinutes,
            'early_minutes' => $result->earlyMinutes,
            'overtime_minutes' => $result->overtimeMinutes,
            'needs_attention' => $result->needsAttention,
        ];
    }

    /**
     * The values of a status override: the status as given, the minutes from the entered times (see the class doc).
     *
     * @param  array{overtime_min_minutes: int, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, attendance_exempt: bool}  $rules
     * @return array<string, mixed>
     */
    private function overridden(string $status, array $rules, string $date, ?string $in, ?string $out, ?DayResult $result): array
    {
        $values = [
            'status' => $status,
            'worked_minutes' => $result->workedMinutes ?? 0,
            'late_minutes' => 0,
            'early_minutes' => $result->earlyMinutes ?? 0,
            'overtime_minutes' => $result->overtimeMinutes ?? 0,
            'needs_attention' => false,
        ];

        if ($status === 'absent') {
            return [...$values, 'worked_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0];
        }

        if ($status === 'holiday' || $status === 'weekend') {
            $worked = $in !== null && $out !== null ? $this->minutesBetween($in, $out) : 0;

            return [
                ...$values,
                'worked_minutes' => $worked,
                'early_minutes' => 0,
                'overtime_minutes' => $worked > 0 && $worked >= $rules['overtime_min_minutes'] ? $worked : 0,
            ];
        }

        if ($status === 'late') {
            // The open-day rules never make a half day, so their late minutes are the real ones (0 on an off day,
            // for an exempt person, without a clock-in, or within the grace).
            $values['late_minutes'] = $this->rebuilder->decideEntered($rules, $date, $in, $out, false)->lateMinutes ?? 0;
        }

        return $values;
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
