<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Support\Attendance\Leavers;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Who is in the payroll run of a month (design 5.2):
 *
 *   $roster = PayrollRoster::forMonth('2026-11');
 *   $roster->included;             // paid by the run: joined on or before the month's last day, no last working day
 *                                  // on or before it, and not a leaver without a last working day (Leavers)
 *   $roster->settledSeparately;    // last working day inside the month: final settlement (Phase 6), listed only
 *   $roster->leftWithoutLastDay;   // leaver status, no last working day, joined by the month end: listed, never paid
 *
 * Someone whose last working day is before the month is in no list. A leaver status WITH a later last working day
 * (e.g. inactive, last day 5 Dec) is an ordinary employee up to that day and is included. Three queries for the lists
 * plus one per relation of the included people, whatever the headcount.
 */
final class PayrollRoster
{
    private const RELATIONS = ['department', 'designation', 'shift', 'bankAccount', 'user'];

    /** @throws InvalidArgumentException a month that is not "Y-m" */
    public static function forMonth(string $month): RosterResult
    {
        [$first, $last] = self::bounds($month);

        $included = Leavers::exceptWithoutLastDay(Employee::query())
            ->where('joining_date', '<=', $last)
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>', $last))
            ->with(self::RELATIONS)
            ->orderBy('employee_code')
            ->orderBy('id')
            ->get();

        $settled = array_values(Employee::query()
            ->where('joining_date', '<=', $last)
            ->whereBetween('last_working_day', [$first, $last])
            ->orderBy('employee_code')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'last_working_day'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->full_name,
                'code' => $e->employee_code,
                'last_working_day' => $e->last_working_day?->format('Y-m-d') ?? '',
            ])
            ->all());

        $left = array_values(Leavers::onlyWithoutLastDay(Employee::query())
            ->where('joining_date', '<=', $last)
            ->orderBy('employee_code')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'status'])
            ->map(fn (Employee $e): array => [
                'id' => $e->id,
                'name' => $e->full_name,
                'code' => $e->employee_code,
                'status' => $e->status,
            ])
            ->all());

        return new RosterResult($included, $settled, $left);
    }

    /**
     * First and last day ("Y-m-d") of a "Y-m" month.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException a month that is not "Y-m"
     */
    public static function bounds(string $month): array
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("Invalid month [{$month}]; expected Y-m.");
        }

        $first = new DateTimeImmutable($month.'-01', new DateTimeZone('UTC'));

        return [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }
}
