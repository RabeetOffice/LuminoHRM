<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flags stored attendance days as needing a rebuild after a change to the rules they were worked out with (a shift, a
 * holiday, or a person's employment window). Only the needs_rebuild column is written; the scheduled rebuild does the work. A manual day is never
 * flagged (a rebuild would skip it anyway).
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
