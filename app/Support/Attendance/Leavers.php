<?php

namespace App\Support\Attendance;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one rule for "someone who has left": the leaver statuses, and the "leaver without a last working day" predicate
 * that attendance uses everywhere (ingestion, relink, the rebuild, Daily, Monthly, the dashboard and the manual
 * correction's employmentRefusal).
 *
 *   Leavers::STATUSES                          inactive, resigned, terminated
 *   Leavers::withoutLastDay($employee)         status is one of them and last_working_day is null
 *   Leavers::exceptWithoutLastDay($query)      narrows an employee query to everyone else
 *   Leavers::onlyWithoutLastDay($query)        narrows an employee query to exactly those people
 *
 * A leaver WITH a last working day is an ordinary employee up to that day (their window ends there). A leaver WITHOUT
 * one has no date their attendance stops, so attendance treats them as gone from now on: punches from their machine
 * user ID are not linked to them (they stay under "Unknown IDs"), no automatic day is created or kept for them from the
 * current attendance day on, and their attendance cannot be corrected. Their closed history is left alone.
 */
final class Leavers
{
    /** Employee statuses of someone who has left (or is not working). ChangeEmployeeStatus::DISABLING is the same list. */
    public const STATUSES = ['inactive', 'resigned', 'terminated'];

    public static function isLeaverStatus(?string $status): bool
    {
        return $status !== null && in_array($status, self::STATUSES, true);
    }

    /** A leaver status with no last working day recorded. */
    public static function withoutLastDay(Employee $employee): bool
    {
        return self::isLeaverStatus($employee->status) && $employee->last_working_day === null;
    }

    /**
     * Everyone except leavers without a last working day.
     *
     * @template TQuery of Builder<Employee>|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query  an employee query
     * @param  string  $table  the employees table name or alias as used in the query (for joins)
     * @return TQuery
     */
    public static function exceptWithoutLastDay(Builder|\Illuminate\Database\Query\Builder $query, string $table = 'employees'): Builder|\Illuminate\Database\Query\Builder
    {
        $query->where(fn ($q) => $q->whereNotIn("{$table}.status", self::STATUSES)->orWhereNotNull("{$table}.last_working_day"));

        return $query;
    }

    /**
     * Only leavers without a last working day.
     *
     * @template TQuery of Builder<Employee>|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query  an employee query
     * @return TQuery
     */
    public static function onlyWithoutLastDay(Builder|\Illuminate\Database\Query\Builder $query, string $table = 'employees'): Builder|\Illuminate\Database\Query\Builder
    {
        $query->whereIn("{$table}.status", self::STATUSES)->whereNull("{$table}.last_working_day");

        return $query;
    }
}
