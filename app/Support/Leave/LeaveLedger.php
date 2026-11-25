<?php

namespace App\Support\Leave;

use DateTimeImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Read-only questions about the leave ledger (leave_days: one row per counted day of an approved request).
 *
 *   LeaveLedger::unpaidDays($employeeId, '2026-11-01', '2026-11-30')   2.5: unpaid leave days in a range (payroll)
 *   LeaveLedger::daysOn([3, 7], '2026-12-01')                          who is on leave that date (calendar, dashboard)
 */
final class LeaveLedger
{
    /**
     * Sum of leave_days.amount of unpaid types for the employee from $from to $to inclusive ("Y-m-d"). Phase 5 payroll
     * reads it next to attendance. Exact to the half day (summed and normalised as whole half days).
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function unpaidDays(int $employeeId, string $from, string $to): float
    {
        self::assertDate($from);
        self::assertDate($to);

        $total = DB::table('leave_days')
            ->join('leave_types', 'leave_types.id', '=', 'leave_days.leave_type_id')
            ->where('leave_types.is_paid', false)
            ->where('leave_days.employee_id', $employeeId)
            ->whereBetween('leave_days.date', [$from, $to])
            ->where(fn ($q) => self::wholeDaysOnly($q, 'leave_days'))
            ->sum('leave_days.amount');

        // MariaDB returns a DECIMAL sum as a string ("3.5"), SQLite as a number: halves() takes both.
        return LeaveDays::fromHalves(LeaveDays::halves($total));
    }

    /**
     * Narrows a leave_days query so a date never counts more than one whole day for a person: the unique key
     * (employee, date, half) allows a "full" row next to an "am"/"pm" row, so a half row is left out whenever the same
     * employee has a "full" row on that date. Used by every leave-day sum (balances' used, unpaidDays).
     *
     * @param  Builder  $query  a query on leave_days
     * @param  string  $table  the table name or alias of leave_days in that query
     */
    public static function wholeDaysOnly(Builder $query, string $table): void
    {
        $query->where("{$table}.half", 'full')
            ->orWhereNotExists(fn ($sub) => $sub->selectRaw('1')
                ->from('leave_days as ld_full')
                ->whereColumn('ld_full.employee_id', "{$table}.employee_id")
                ->whereColumn('ld_full.date', "{$table}.date")
                ->where('ld_full.half', 'full'));
    }

    /**
     * Who of $employeeIds is on approved leave on $date ("Y-m-d"): employee id => [type name, type colour, half].
     * half is "full", "am" or "pm"; an "am" and a "pm" day on the same date together are a full day ("full", with the
     * "am" day's type). People not on leave are left out.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, array{type: string, colour: string, half: string}>
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function daysOn(array $employeeIds, string $date): array
    {
        self::assertDate($date);

        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('leave_days')
            ->join('leave_types', 'leave_types.id', '=', 'leave_days.leave_type_id')
            ->whereIn('leave_days.employee_id', $employeeIds)
            ->where('leave_days.date', $date)
            ->orderBy('leave_days.employee_id')
            ->orderByRaw("case leave_days.half when 'full' then 0 when 'am' then 1 else 2 end")
            ->get(['leave_days.employee_id', 'leave_days.half', 'leave_types.name', 'leave_types.colour']);

        $result = [];

        foreach ($rows as $row) {
            $id = (int) $row->employee_id;

            if (isset($result[$id])) {
                $result[$id]['half'] = 'full'; // a second half on the same date: the whole day is leave

                continue;
            }

            $result[$id] = ['type' => (string) $row->name, 'colour' => (string) $row->colour, 'half' => (string) $row->half];
        }

        return $result;
    }

    private static function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date [{$date}]; expected Y-m-d.");
        }
    }
}
