<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flags stored attendance days as needing a rebuild after a change to the rules they were worked out with (a shift, a
 * holiday, a person's employment window, or their approved leave). Only the needs_rebuild column is written (forLeaveDays
 * may also create bare pending rows); the scheduled rebuild does the work. A manual day is never flagged (a rebuild
 * would skip it anyway).
 */
class RebuildMarks
{
    /**
     * A shift's rules changed: the days from the attendance day of now (AttendanceClock::attendanceToday(), so at 00:30
     * yesterday is still included while its punch window runs) of the people who work it that are not closed yet.
     * Closed days are not flagged here. People with no shift of their own work the default shift.
     *
     * @return int the number of days flagged
     */
    public static function forShift(Shift $shift): int
    {
        $employees = Employee::query()->select('id')
            ->where(fn ($q) => $shift->is_default
                ? $q->where('shift_id', $shift->id)->orWhereNull('shift_id')
                : $q->where('shift_id', $shift->id));

        return self::forEmployeesFromToday($employees);
    }

    /**
     * The default moved to another shift: the pending days of people with no shift of their own.
     *
     * @return int the number of days flagged
     */
    public static function forEmployeesWithoutShift(): int
    {
        return self::forEmployeesFromToday(Employee::query()->select('id')->whereNull('shift_id'));
    }

    /**
     * One employee's shift or attendance-exempt flag changed: their days from today on that are not closed yet.
     *
     * @return int the number of days flagged
     */
    public static function forEmployee(int $employeeId): int
    {
        return self::forEmployeesFromToday(Employee::query()->select('id')->whereKey($employeeId));
    }

    /**
     * A holiday was added, moved or removed: every non-manual day on that date, closed or not.
     *
     * @return int the number of days flagged
     */
    public static function forDate(string $date): int
    {
        return Attendance::query()
            ->where('date', $date)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true]);
    }

    /**
     * One employee's joining date, last working day or status changed: their non-manual days that are now outside the
     * window attendance keeps for them (before the joining date, after the last working day, or, for a leaver without a
     * last working day, from the current attendance day on) are flagged, closed or not, so the rebuild removes them. One
     * bounded UPDATE on that employee's rows. Days the window gained are not flagged here: the hourly close backfills
     * missing days (CloseAttendanceDay).
     *
     * @return int the number of days flagged
     */
    public static function outsideEmployment(Employee $employee): int
    {
        $joined = $employee->joining_date->format('Y-m-d');
        $last = $employee->last_working_day?->format('Y-m-d');
        $leftWithoutDate = Leavers::withoutLastDay($employee);
        $today = app(AttendanceClock::class)->attendanceToday();

        return Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->where(function ($q) use ($joined, $last, $leftWithoutDate, $today): void {
                $q->where('date', '<', $joined);

                if ($last !== null) {
                    $q->orWhere('date', '>', $last);
                }

                if ($leftWithoutDate) {
                    $q->orWhere('date', '>=', $today);
                }
            })
            ->update(['needs_rebuild' => true]);
    }

    /**
     * Leave days were added to or removed from these dates of one employee (approval, cancellation, holiday refund,
     * exit trimming): each date up to AttendanceClock::attendanceToday() is marked so the rebuild decides it again with
     * the leave ledger. An existing non-manual row gets needs_rebuild = true, closed or not; a date with no row gets a
     * bare pending row (status null, needs_rebuild = true, is_manual = false) via insert-or-ignore, exactly as punch
     * ingestion does. Skipped: future dates (no row exists for a day not yet reached), manual rows (manual attendance
     * wins; the caller lists them as needing attention), and, for the bare rows only, dates before the attendance start
     * date or any date while attendance has not started (the rebuild would only remove a punch-less row there; an
     * existing row there, which has punches, is still flagged).
     *
     * @param  list<string>  $dates  "Y-m-d", any order, duplicates allowed
     * @return int the rows flagged plus the rows created
     *
     * @throws \InvalidArgumentException a malformed date
     */
    public static function forLeaveDays(int $employeeId, array $dates): int
    {
        $today = app(AttendanceClock::class)->attendanceToday();
        $start = AttendanceClock::startDate();
        $wanted = [];

        foreach ($dates as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException("Not a date [{$date}].");
            }

            if ($date <= $today) {
                $wanted[$date] = true;
            }
        }

        if ($wanted === []) {
            return 0;
        }

        $wanted = array_keys($wanted);
        sort($wanted);

        $existing = Attendance::query()->toBase()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $wanted)
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();

        $missing = array_values(array_filter(
            array_diff($wanted, $existing),
            fn (string $date) => $start !== null && $date >= $start,
        ));

        $now = (new Attendance)->freshTimestampString();

        $flagged = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $wanted)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true, 'updated_at' => $now]);

        $created = $missing === [] ? 0 : Attendance::query()->insertOrIgnore(array_map(fn (string $date) => [
            'employee_id' => $employeeId,
            'date' => $date,
            'status' => null, // pending calculation
            'is_manual' => false,
            'needs_rebuild' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing));

        return $flagged + $created;
    }

    /** @param  Builder<Employee>  $employees  a query selecting employee ids */
    private static function forEmployeesFromToday(Builder $employees): int
    {
        return Attendance::query()
            ->whereIn('employee_id', $employees)
            ->where('date', '>=', app(AttendanceClock::class)->attendanceToday())
            ->where('closed', false)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true]);
    }
}
