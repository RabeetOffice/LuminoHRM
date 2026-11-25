<?php

namespace App\Support\Leave;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The one place that decides which dates of a leave request count, and how much each is worth.
 *
 *   LeaveDays::count(...)   pure and static: the rule itself, from plain arrays (no I/O)
 *   LeaveDays::plan(...)    the loader: reads the employee's weekly offs per date, the holidays and the employment
 *                           window, then calls count()
 *   LeaveDays::planMany()   the same for several requests in a fixed number of queries (balances' pending recount)
 *
 * Counted days = the employee's own working days minus holidays, inside the employment window. A full day is worth
 * 1 (half "full"); a half-day request (one date, "am" = first half, "pm" = second half) is worth 0.5.
 *
 * Weekly offs per date (default, owner may change): a past day whose attendance row is closed and holds a usable
 * shift_rules snapshot uses the weekly offs saved there (the rules the day was decided with), so changing a shift
 * later never rewrites what a past date counted as; every other date uses the employee's shift in force
 * (Shift::forEmployee: their own shift, else the default shift).
 *
 * Day amounts are multiples of 0.5. All sums go through halves() / fromHalves(): each amount becomes a whole number of
 * half days (an int), the ints are added, and the total is divided by two once. Integers add exactly, and n / 2 is
 * exact in binary floating point, so adding any number of 0.5s never drifts.
 */
final class LeaveDays
{
    public const REFUSAL_END_BEFORE_START = 'The end date is before the start date.';

    public const REFUSAL_HALF_DAY_RANGE = 'A half day must be a single date.';

    public const REFUSAL_NOTHING_TO_COUNT = 'These dates are all weekly offs or holidays.';

    /**
     * Counts a request's days. Pure: everything comes in as arguments.
     *
     * Rules, in this order: $to before $from → refusal REFUSAL_END_BEFORE_START; a half day ($half "am"/"pm") needs
     * $from = $to, else REFUSAL_HALF_DAY_RANGE; dates that are weekly offs or holidays are skipped; a COUNTED date
     * outside the window → refusal LeavePlan::OUTSIDE_EMPLOYMENT (a key: the caller words it with the person's name;
     * weekly offs and holidays outside the window do not refuse, they are simply not counted); nothing left →
     * REFUSAL_NOTHING_TO_COUNT. Full days are worth 1 (half "full"), a half day 0.5 (half "am"/"pm").
     *
     * @param  string  $from  first date, "Y-m-d"
     * @param  string  $to  last date, "Y-m-d" (inclusive)
     * @param  string|null  $half  null = whole days; "am" or "pm" = a half day on $from
     * @param  array<string, list<string>>  $weeklyOffsByDate  "Y-m-d" → lowercase day names that are weekly offs on that
     *                                                         date; a date without an entry has no weekly off
     * @param  list<string>  $holidays  "Y-m-d" holiday dates (any range; others are ignored)
     * @param  string  $windowFrom  first date the person is employed, "Y-m-d" (inclusive)
     * @param  string|null  $windowTo  last date the person is employed, "Y-m-d" (inclusive); null = open-ended
     *
     * @throws InvalidArgumentException a malformed date or an unknown $half
     */
    public static function count(string $from, string $to, ?string $half, array $weeklyOffsByDate, array $holidays, string $windowFrom, ?string $windowTo): LeavePlan
    {
        $start = self::parse($from);
        self::parse($to);
        self::parse($windowFrom);

        if ($windowTo !== null) {
            self::parse($windowTo);
        }

        if ($half !== null && ! in_array($half, ['am', 'pm'], true)) {
            throw new InvalidArgumentException("Unknown half [{$half}].");
        }

        if ($to < $from) {
            return LeavePlan::refused(self::REFUSAL_END_BEFORE_START);
        }

        if ($half !== null && $from !== $to) {
            return LeavePlan::refused(self::REFUSAL_HALF_DAY_RANGE);
        }

        $holidaySet = array_fill_keys($holidays, true);
        $days = [];

        for ($d = $start; ($date = $d->format('Y-m-d')) <= $to; $d = $d->modify('+1 day')) {
            if (isset($holidaySet[$date])) {
                continue;
            }

            if (in_array(strtolower($d->format('l')), $weeklyOffsByDate[$date] ?? [], true)) {
                continue;
            }

            // Only a COUNTED day outside the window refuses: offs and holidays outside it are simply not counted.
            if ($date < $windowFrom || ($windowTo !== null && $date > $windowTo)) {
                return LeavePlan::refused(LeavePlan::OUTSIDE_EMPLOYMENT);
            }

            $days[] = $half === null
                ? ['date' => $date, 'amount' => 1.0, 'half' => 'full']
                : ['date' => $date, 'amount' => 0.5, 'half' => $half];
        }

        if ($days === []) {
            return LeavePlan::refused(self::REFUSAL_NOTHING_TO_COUNT);
        }

        return new LeavePlan($days);
    }

    /**
     * Counts $employee's request as it stands now (the loader around count()).
     *
     * Weekly offs per date: the closed attendance row's shift_rules['weekly_offs'] when it has a usable snapshot, else
     * the employee's shift in force (Shift::forEmployee). Holidays in the range. Window: joining date to last working
     * day; a leaver without a last working day (Leavers::withoutLastDay) ends the day before
     * AttendanceClock::attendanceToday(). A counted date (not a weekly off or holiday) outside it is refused with "These dates are outside {name}'s
     * employment.". A fixed number of queries whatever the range (closed attendance rows in range, holidays in range,
     * the shift).
     *
     * @param  string|null  $half  null = whole days; "am" / "pm" = a half day (then $from = $to)
     */
    public static function plan(Employee $employee, string $from, string $to, ?string $half): LeavePlan
    {
        return self::planMany([[$employee, $from, $to, $half]])[0];
    }

    /**
     * plan() for several requests at once (any employees), in a fixed number of queries whatever the number of
     * requests or the length of their ranges. Results in the order given.
     *
     * @param  list<array{0: Employee, 1: string, 2: string, 3: string|null}>  $asks  [employee, from, to, half]
     * @return list<LeavePlan>
     */
    public static function planMany(array $asks): array
    {
        if ($asks === []) {
            return [];
        }

        // One span covering every request (only well-formed, non-inverted ranges widen it; count() refuses the rest).
        $min = null;
        $max = null;

        foreach ($asks as [, $from, $to]) {
            self::parse($from);
            self::parse($to);

            if ($to >= $from) {
                $min = $min === null ? $from : min($min, $from);
                $max = $max === null ? $to : max($max, $to);
            }
        }

        $holidays = [];
        $snapshots = []; // employee id => date => weekly offs

        if ($min !== null && $max !== null) {
            $holidays = array_values(array_unique(array_map(
                fn (mixed $date): string => substr((string) $date, 0, 10),
                Holiday::query()->betweenDates($min, $max)->toBase()->pluck('date')->all(),
            )));

            $employeeIds = array_values(array_unique(array_map(fn (array $ask): int => $ask[0]->id, $asks)));

            $rows = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->betweenDates($min, $max)
                ->where('closed', true)
                ->toBase()
                ->get(['employee_id', 'date', 'shift_rules']);

            foreach ($rows as $row) {
                $offs = self::snapshotOffs($row->shift_rules);

                if ($offs !== null) {
                    $snapshots[(int) $row->employee_id][substr((string) $row->date, 0, 10)] = $offs;
                }
            }
        }

        $today = null;
        $shiftOffs = []; // employee id => weekly offs of the shift in force
        $plans = [];

        // The shift in force (Shift::forEmployee: own shift, else the default), loaded once for everyone on first need.
        $shifts = null;
        $default = null;
        $shiftFor = function (Employee $employee) use ($asks, &$shifts, &$default): array {
            if (count($asks) === 1) {
                return self::normaliseOffs(Shift::forEmployee($employee)->weekly_offs);
            }

            $shifts ??= Shift::query()
                ->whereIn('id', array_values(array_unique(array_filter(array_map(fn (array $a): ?int => $a[0]->shift_id, $asks)))))
                ->get()->keyBy('id');
            $shift = $employee->shift_id !== null ? $shifts->get($employee->shift_id) : null;

            return self::normaliseOffs(($shift ?? ($default ??= Shift::default()))->weekly_offs);
        };

        foreach ($asks as [$employee, $from, $to, $half]) {
            $windowFrom = $employee->joining_date->format('Y-m-d');
            $windowTo = $employee->last_working_day?->format('Y-m-d');

            if ($windowTo === null && Leavers::withoutLastDay($employee)) {
                $today ??= app(AttendanceClock::class)->attendanceToday();
                $windowTo = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
            }

            $offsByDate = [];

            if ($to >= $from) {
                $own = $snapshots[$employee->id] ?? [];

                for ($d = self::parse($from); ($date = $d->format('Y-m-d')) <= $to; $d = $d->modify('+1 day')) {
                    $offsByDate[$date] = $own[$date] ?? ($shiftOffs[$employee->id] ??= $shiftFor($employee));
                }
            }

            $plan = self::count($from, $to, $half, $offsByDate, $holidays, $windowFrom, $windowTo);

            if ($plan->refusal === LeavePlan::OUTSIDE_EMPLOYMENT) {
                $plan = LeavePlan::refused("These dates are outside {$employee->full_name}'s employment.");
            }

            $plans[] = $plan;
        }

        return $plans;
    }

    /**
     * A day amount as a whole number of half days (1.5 → 3). Rounds to the nearest half, so a value read back from the
     * database as "1.5", 1.5 or 1.4999999 gives the same int.
     */
    public static function halves(float|int|string $days): int
    {
        return (int) round(((float) $days) * 2);
    }

    /** A whole number of half days as a day amount (3 → 1.5). Exact: n / 2 is representable for any int n. */
    public static function fromHalves(int $halves): float
    {
        return $halves / 2.0;
    }

    /**
     * The weekly offs of a closed row's shift_rules snapshot, or null when it has none or it is unusable (the same
     * checks as AttendanceRebuilder::snapshotOf: times, grace, half-day hours and a weekly-offs list present).
     *
     * @return list<string>|null
     */
    private static function snapshotOffs(mixed $raw): ?array
    {
        $rules = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        if (! is_array($rules)
            || ! is_string($rules['start_time'] ?? null) || ! is_string($rules['end_time'] ?? null)
            || ! is_int($rules['grace_minutes'] ?? null) || ! is_numeric($rules['half_day_hours'] ?? null)
            || ! is_array($rules['weekly_offs'] ?? null)) {
            return null;
        }

        return self::normaliseOffs($rules['weekly_offs']);
    }

    /**
     * Lowercase known day names (unknown entries dropped).
     *
     * @param  array<mixed>  $days
     * @return list<string>
     */
    private static function normaliseOffs(array $days): array
    {
        $week = (array) config('hrm.attendance.weekdays');
        $names = array_map(fn (mixed $d): string => is_string($d) ? strtolower(trim($d)) : '', $days);

        return array_values(array_intersect($week, $names));
    }

    private static function parse(string $date): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Invalid date [{$date}]; expected Y-m-d.");
        }

        return $parsed;
    }
}
