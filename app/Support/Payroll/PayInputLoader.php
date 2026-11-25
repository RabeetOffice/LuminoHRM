<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanSkip;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\MonthGrid;
use App\Support\Leave\LeaveLedger;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a month of attendance, leave, salary profiles, loans and HR's line inputs into one PayInput per person, for
 * PayCalculator (design 5.3, decision 3).
 *
 *   $inputs = PayInputLoader::load('2026-11', $roster->included, [7 => ['adjustments' => [...], 'tax_override' => null,
 *       'tax_override_reason' => null]]);
 *   PayCalculator::calculate($inputs[7]);
 *
 * Per person:
 * - pay window: max(joining date, 1st) .. last day of the month;
 * - segments: the window split at each salary profile's effective date; the latest profile on or before the window
 *   start covers the start; days before the first profile are dropped (no segment: the calculator blocks the line);
 * - attendance (MonthGrid, so only days attendance counts: on or after attendance.start_date, inside the employment
 *   window, not after today): absent = 2 halves (1 with half-day leave), half day = 1; holiday / weekend dates; manual
 *   present / late dates; late days and overtime minutes from the totals; pending days ("-") deduct nothing (the run's
 *   checks block them);
 * - unpaid leave halves from the leave ledger on EVERY day of the window (also before attendance.start_date);
 * - day-pay basis: 30, the days of the month, or the person's working days (0 falls back to the days of the month);
 * - loans: active, first deduction month on or before the month, something remaining; skipped when a loan_skips row
 *   exists for the month; oldest first.
 *
 * Reads a fixed number of queries whatever the headcount or the days (attendance and leave: one per 500 people).
 */
final class PayInputLoader
{
    private const CHUNK = 500;

    /**
     * @param  Collection<int, Employee>  $employees  complete employee models
     * @param  array<int, array{adjustments?: list<PayAdjustment>, tax_override?: int|null, tax_override_reason?: string|null}>  $itemInputs  employee id => HR's inputs of the line
     * @return array<int, PayInput> employee id => input
     *
     * @throws InvalidArgumentException a month that is not "Y-m"
     */
    public static function load(string $month, Collection $employees, array $itemInputs = []): array
    {
        [$first, $last] = PayrollRoster::bounds($month);

        if ($employees->isEmpty()) {
            return [];
        }

        $employees = new EloquentCollection($employees->values()->all());
        $employees->loadMissing(['department', 'designation']);
        $ids = array_values($employees->map(fn (Employee $e): int => (int) $e->id)->all());
        $daysInMonth = (int) substr($last, 8, 2);

        $grid = MonthGrid::for($month, app(AttendanceClock::class));
        if ($grid === null) {
            throw new InvalidArgumentException("Invalid month [{$month}]; expected Y-m.");
        }
        $rows = $grid->rows($employees);
        $unpaid = LeaveLedger::unpaidByDate($ids, $first, $last);
        $profiles = self::profiles($ids, $last);
        $loans = self::loans($ids, $month);

        $basis = (string) settings('payroll.day_pay_basis', 'calendar_days');
        $workingDays = $basis === 'working_days' ? WorkingDays::forEmployees($month, $employees) : [];
        $lateRuleCount = max(0, (int) settings('attendance.late_rule_count', 0));
        $overtime = new OvertimeRule(
            (bool) settings('attendance.overtime_enabled', false),
            (string) settings('attendance.overtime_rate_mode', 'multiple'),
            (int) settings('attendance.overtime_multiplier_percent', 150),
            Money::rupees((int) settings('attendance.overtime_fixed_rate', 0)),
            (int) settings('attendance.overtime_hours_per_day', 8),
        );

        $inputs = [];

        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $windowFrom = max($employee->joining_date->format('Y-m-d'), $first);
            $windowTo = $last;
            $row = $rows[$id];

            $attendance = self::fromCells($month, $row['cells']);

            $basisDays = match ($basis) {
                'thirty' => 30,
                'working_days' => ($workingDays[$id] ?? 0) > 0 ? $workingDays[$id] : $daysInMonth,
                default => $daysInMonth,
            };

            $unpaidHalves = array_filter(
                $unpaid[$id] ?? [],
                fn (string $date): bool => $date >= $windowFrom && $date <= $windowTo,
                ARRAY_FILTER_USE_KEY,
            );

            $line = $itemInputs[$id] ?? [];

            $inputs[$id] = new PayInput(
                employeeId: $id,
                employee: [
                    'code' => $employee->employee_code,
                    'name' => $employee->full_name,
                    'department' => $employee->department?->name,
                    'designation' => $employee->designation?->name,
                    'joining_date' => $employee->joining_date->format('Y-m-d'),
                ],
                month: $month,
                daysInMonth: $daysInMonth,
                windowFrom: $windowFrom,
                windowTo: $windowTo,
                segments: self::segments($profiles[$id] ?? [], $windowFrom, $windowTo),
                basis: $basis,
                basisDays: $basisDays,
                attendanceHalves: $attendance['halves'],
                unpaidLeaveHalves: $unpaidHalves,
                offDates: $attendance['off'],
                manualWorkedDates: $attendance['manual'],
                lateDays: (int) $row['totals']['late'],
                lateRuleCount: $lateRuleCount,
                overtimeMinutes: (int) $row['totals']['overtime_minutes'],
                overtime: $overtime,
                attendanceTotals: $row['totals'],
                countedFrom: $attendance['counted_from'],
                loans: $loans[$id] ?? [],
                adjustments: $line['adjustments'] ?? [],
                taxOverride: $line['tax_override'] ?? null,
                taxOverrideReason: $line['tax_override_reason'] ?? null,
                exempt: (bool) $employee->attendance_exempt,
            );
        }

        return $inputs;
    }

    /**
     * What payroll reads from a MonthGrid row's cells.
     *
     * @param  list<list<int|string|null>|null>  $cells
     * @return array{halves: array<string, int>, off: list<string>, manual: list<string>, counted_from: string|null}
     */
    private static function fromCells(string $month, array $cells): array
    {
        $halves = [];
        $off = [];
        $manual = [];
        $countedFrom = null;

        foreach ($cells as $index => $cell) {
            if ($cell === null) {
                continue;
            }

            $date = sprintf('%s-%02d', $month, $index + 1);
            $countedFrom ??= $date;
            $code = $cell[0];
            $flags = (int) $cell[1];

            match ($code) {
                'A' => $halves[$date] = ($flags & MonthGrid::FLAG_HALF_LEAVE) !== 0 ? 1 : 2,
                'H' => $halves[$date] = 1,
                'O', 'W' => $off[] = $date,
                default => null,
            };

            if (($code === 'P' || $code === 'L') && ($flags & MonthGrid::FLAG_MANUAL) !== 0) {
                $manual[] = $date;
            }
        }

        return ['halves' => $halves, 'off' => $off, 'manual' => $manual, 'counted_from' => $countedFrom];
    }

    /**
     * The window split at each profile's effective date.
     *
     * @param  list<array{id: int, from: string, basic: int, tax_mode: string, tax_value: int, tax_base: string|null, allowances: list<PayComponent>, deductions: list<PayComponent>}>  $profiles  in effective date order
     * @return list<ProfileSegment>
     */
    private static function segments(array $profiles, string $windowFrom, string $windowTo): array
    {
        if ($windowFrom > $windowTo) {
            return [];
        }

        // [start date, profile] pairs: the latest profile on or before the window start, then each one inside it.
        $starts = [];
        foreach ($profiles as $profile) {
            if ($profile['from'] <= $windowFrom) {
                $starts = [[$windowFrom, $profile]];
            } elseif ($profile['from'] <= $windowTo) {
                $starts[] = [$profile['from'], $profile];
            }
        }

        $segments = [];
        foreach ($starts as $i => [$from, $profile]) {
            $to = isset($starts[$i + 1]) ? self::addDays($starts[$i + 1][0], -1) : $windowTo;

            $segments[] = new ProfileSegment(
                profileId: $profile['id'],
                from: $from,
                to: $to,
                days: self::daysBetween($from, $to),
                basic: $profile['basic'],
                allowances: $profile['allowances'],
                deductions: $profile['deductions'],
                taxMode: $profile['tax_mode'],
                taxValue: $profile['tax_value'],
                taxBase: $profile['tax_base'],
            );
        }

        return $segments;
    }

    /**
     * Every profile in force on or before the month's last day, per employee, in effective date order: one query for
     * the profiles and one for their components (per 500 people).
     *
     * @param  list<int>  $ids
     * @return array<int, list<array{id: int, from: string, basic: int, tax_mode: string, tax_value: int, tax_base: string|null, allowances: list<PayComponent>, deductions: list<PayComponent>}>>
     */
    private static function profiles(array $ids, string $last): array
    {
        $result = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $profiles = DB::table('salary_profiles')
                ->whereIn('employee_id', $chunk)
                ->where('effective_from', '<=', $last)
                ->orderBy('effective_from')
                ->orderBy('id')
                ->get(['id', 'employee_id', 'effective_from', 'basic', 'tax_mode', 'tax_value', 'tax_base']);

            $components = [];
            DB::table('salary_components')
                ->whereIn('salary_profile_id', DB::table('salary_profiles')->select('id')
                    ->whereIn('employee_id', $chunk)
                    ->where('effective_from', '<=', $last))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['salary_profile_id', 'name', 'type', 'amount', 'is_taxable'])
                ->each(function (object $c) use (&$components): void {
                    $isAllowance = $c->type === 'allowance';
                    $components[(int) $c->salary_profile_id][$isAllowance ? 'allowances' : 'deductions'][] = new PayComponent(
                        (string) $c->name,
                        (int) $c->amount,
                        $isAllowance && (bool) $c->is_taxable,
                    );
                });

            foreach ($profiles as $p) {
                $pid = (int) $p->id;
                $result[(int) $p->employee_id][] = [
                    'id' => $pid,
                    'from' => substr((string) $p->effective_from, 0, 10),
                    'basic' => (int) $p->basic,
                    'tax_mode' => (string) $p->tax_mode,
                    'tax_value' => (int) $p->tax_value,
                    'tax_base' => $p->tax_base === null ? null : (string) $p->tax_base,
                    'allowances' => $components[$pid]['allowances'] ?? [],
                    'deductions' => $components[$pid]['deductions'] ?? [],
                ];
            }
        }

        return $result;
    }

    /**
     * Loans to recover this month per employee, oldest first: active, first deduction month on or before $month,
     * something remaining. One query for the loans with their repaid sums and one for the month's skips (per 500).
     *
     * @param  list<int>  $ids
     * @return array<int, list<LoanDue>>
     */
    private static function loans(array $ids, string $month): array
    {
        $result = [];

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $active = fn () => Loan::query()
                ->whereIn('employee_id', $chunk)
                ->where('status', 'active')
                ->where('start_month', '<=', $month);

            $loans = $active()->withRemaining()->orderBy('start_month')->orderBy('id')->get();

            $skipped = array_flip(LoanSkip::query()
                ->where('month', $month)
                ->whereIn('loan_id', $active()->select('id'))
                ->pluck('loan_id')
                ->map(fn ($id): int => (int) $id)
                ->all());

            foreach ($loans as $loan) {
                $remaining = $loan->remaining();

                if ($remaining <= 0) {
                    continue;
                }

                $result[(int) $loan->employee_id][] = new LoanDue(
                    (int) $loan->id,
                    $loan->type,
                    $remaining,
                    $loan->installment,
                    isset($skipped[(int) $loan->id]),
                );
            }
        }

        return $result;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' day')->format('Y-m-d');
    }

    private static function daysBetween(string $from, string $to): int
    {
        $a = new DateTimeImmutable($from, new DateTimeZone('UTC'));
        $b = new DateTimeImmutable($to, new DateTimeZone('UTC'));

        return (int) $a->diff($b)->days + 1;
    }
}
