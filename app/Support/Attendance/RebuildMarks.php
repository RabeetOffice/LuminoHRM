<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Builder;

/**
 * Flags stored attendance days as needing a rebuild after a change to the rules they were worked out with (a shift or
 * a holiday). Only the needs_rebuild column is written; the scheduled rebuild does the work. A manual day is never
 * flagged (a rebuild would skip it anyway).
 */
class RebuildMarks
{
    /**
     * A shift's rules changed: today's and later days of the people who work it that are not closed yet. Closed days
     * keep the values they were closed with. People with no shift of their own work the default shift.
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

    /** @param  Builder<Employee>  $employees  a query selecting employee ids */
    private static function forEmployeesFromToday(Builder $employees): int
    {
        return Attendance::query()
            ->whereIn('employee_id', $employees)
            ->where('date', '>=', app(AttendanceClock::class)->today()->toDateString())
            ->where('closed', false)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true]);
    }
}
