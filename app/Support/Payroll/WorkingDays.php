<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * The working days of a month for the "working_days" day-pay basis (design 5.3): days of the WHOLE month that are
 * neither a weekly off of the person's shift nor a holiday. A month with none left gives 0 here; the loader then falls
 * back to the calendar days.
 *
 *   WorkingDays::inMonth('2026-11', ['saturday', 'sunday'], ['2026-11-09']);   // 20 (pure)
 *   WorkingDays::forEmployees('2026-11', $employees);                         // [employee id => 20, ...]
 */
final class WorkingDays
{
    /**
     * @param  list<string>  $weeklyOffs  lower-case day names ("saturday")
     * @param  list<string>  $holidayDates  "Y-m-d" (dates outside the month are ignored)
     */
    public static function inMonth(string $month, array $weeklyOffs, array $holidayDates): int
    {
        [$first, $last] = PayrollRoster::bounds($month);
        $offs = array_map(fn (string $d): string => strtolower(trim($d)), $weeklyOffs);
        $holidays = array_flip($holidayDates);

        $count = 0;
        $end = new DateTimeImmutable($last, new DateTimeZone('UTC'));
        for ($d = new DateTimeImmutable($first, new DateTimeZone('UTC')); $d <= $end; $d = $d->modify('+1 day')) {
            if (! in_array(strtolower($d->format('l')), $offs, true) && ! isset($holidays[$d->format('Y-m-d')])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Working days of the month per employee (weekly offs of the shift Shift::forEmployee() gives them). One query for
     * the month's holidays, at most one for the default shift and one for shifts not loaded yet, whatever the headcount.
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, int> employee id => working days
     */
    public static function forEmployees(string $month, Collection $employees): array
    {
        [$first, $last] = PayrollRoster::bounds($month);

        if ($employees->isEmpty()) {
            return [];
        }

        $employees = new EloquentCollection($employees->values()->all());
        $employees->loadMissing('shift');

        $holidays = array_values(Holiday::query()
            ->betweenDates($first, $last)
            ->toBase()
            ->pluck('date')
            ->map(fn ($date): string => substr((string) $date, 0, 10))
            ->all());

        $default = null;
        $byOffs = []; // the same weekly offs give the same count: computed once
        $result = [];

        foreach ($employees as $employee) {
            if ($employee->shift_id !== null && $employee->shift !== null) {
                $shift = Shift::forEmployee($employee); // loaded: no query
            } else {
                $shift = $default ??= Shift::default();
            }

            $offs = $shift->weekly_offs;
            $key = implode(',', $offs);

            $result[(int) $employee->id] = $byOffs[$key] ??= self::inMonth($month, $offs, $holidays);
        }

        return $result;
    }
}
