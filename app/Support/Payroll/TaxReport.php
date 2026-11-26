<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Settings;

/**
 * The yearly tax report (Payroll > Tax Report): per employee, the opening figures of the tax year (tax deducted and
 * taxable income before go-live, OpeningBalance) and the taxable pay and tax of every approved or paid run whose month
 * falls in the tax year (TaxYear::months, start month from settings('payroll.tax_year_start_month')), with totals.
 *
 * Draft runs never count (their figures may still change). Excluded lines are left out (their pay is zeroed anyway).
 * Everyone with an opening row or a counted line is listed, leavers included for the months they were paid. Figures are
 * paisa, read from the stored lines (frozen at approval), never recomputed. The taxable column is the line's GROSS
 * taxable pay (breakdown tax.gross_taxable_pay), whatever the tax mode, so it matches the opening "taxable income to
 * date"; for a percentage-of-basic profile payroll_items.taxable_pay is only the tax base, which the report ignores
 * (a line stored without that key falls back to taxable_pay).
 *
 * Reads: three queries whatever the headcount (opening rows, counted lines, the listed employees).
 */
final class TaxReport
{
    /** settings('payroll.tax_year_start_month'), 1..12, default 7. */
    public static function startMonth(): int
    {
        $month = (int) Settings::get('payroll.tax_year_start_month', 7);

        return $month >= 1 && $month <= 12 ? $month : 7;
    }

    /** The tax year holding $today ("Y-m-d" or "Y-m"). */
    public static function currentYear(string $today): int
    {
        return TaxYear::of(substr($today, 0, 7), self::startMonth());
    }

    /**
     * The years the report offers: from the earliest tax year with an opening row or an approved / paid run, to the
     * current tax year (just the current one when there is nothing earlier). Two queries.
     *
     * @return list<int>
     */
    public static function years(int $current): array
    {
        $earliest = $current;

        $opening = OpeningBalance::query()->min('tax_year');
        if ($opening !== null) {
            $earliest = min($earliest, (int) $opening);
        }

        $month = PayrollRun::query()->whereIn('status', (array) config('hrm.payroll.locked_statuses'))->min('month');
        if (is_string($month) && $month !== '') {
            $earliest = min($earliest, TaxYear::of($month, self::startMonth()));
        }

        return range($current, $earliest);
    }

    /**
     * @return array{
     *     year: int,
     *     label: string,
     *     months: list<string>,
     *     rows: list<array{employee_id: int, code: string, name: string, opening_taxable: int, opening_tax: int, months: array<string, array{taxable: int, tax: int}>, total_taxable: int, total_tax: int}>,
     *     totals: array{opening_taxable: int, opening_tax: int, months: array<string, array{taxable: int, tax: int}>, total_taxable: int, total_tax: int}
     * }
     */
    public static function for(int $taxYear): array
    {
        $start = self::startMonth();
        $months = TaxYear::months($taxYear, $start);

        /** @var array<int, array{taxable: int, tax: int}> $opening */
        $opening = [];
        foreach (OpeningBalance::query()->where('tax_year', $taxYear)->get(['employee_id', 'tax_deducted', 'taxable_income']) as $row) {
            $opening[$row->employee_id] = ['taxable' => $row->taxable_income, 'tax' => $row->tax_deducted];
        }

        /** @var array<int, array<string, array{taxable: int, tax: int}>> $lines */
        $lines = [];
        $items = PayrollItem::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->whereIn('payroll_runs.status', (array) config('hrm.payroll.locked_statuses'))
            ->whereIn('payroll_runs.month', $months)
            ->where('payroll_items.excluded', false)
            ->toBase()
            ->get(['payroll_items.employee_id', 'payroll_runs.month', 'payroll_items.taxable_pay', 'payroll_items.tax', 'payroll_items.breakdown']);

        foreach ($items as $item) {
            $id = (int) $item->employee_id;
            $month = (string) $item->month;
            $current = $lines[$id][$month] ?? ['taxable' => 0, 'tax' => 0];
            $lines[$id][$month] = [
                'taxable' => $current['taxable'] + self::grossTaxable($item),
                'tax' => $current['tax'] + (int) $item->tax,
            ];
        }

        $ids = array_values(array_unique([...array_keys($opening), ...array_keys($lines)]));
        $employees = $ids === [] ? collect() : Employee::query()
            ->whereIn('id', $ids)
            ->orderBy('employee_code')
            ->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name']);

        $zero = ['taxable' => 0, 'tax' => 0];
        $totals = [
            'opening_taxable' => 0,
            'opening_tax' => 0,
            'months' => array_fill_keys($months, $zero),
            'total_taxable' => 0,
            'total_tax' => 0,
        ];
        $rows = [];

        foreach ($employees as $employee) {
            $open = $opening[$employee->id] ?? $zero;
            $row = [
                'employee_id' => $employee->id,
                'code' => (string) $employee->employee_code,
                'name' => $employee->full_name,
                'opening_taxable' => $open['taxable'],
                'opening_tax' => $open['tax'],
                'months' => [],
                'total_taxable' => $open['taxable'],
                'total_tax' => $open['tax'],
            ];

            foreach ($months as $month) {
                $figure = $lines[$employee->id][$month] ?? $zero;
                $row['months'][$month] = $figure;
                $row['total_taxable'] += $figure['taxable'];
                $row['total_tax'] += $figure['tax'];
                $sum = $totals['months'][$month];
                $totals['months'][$month] = [
                    'taxable' => $sum['taxable'] + $figure['taxable'],
                    'tax' => $sum['tax'] + $figure['tax'],
                ];
            }

            $totals['opening_taxable'] += $row['opening_taxable'];
            $totals['opening_tax'] += $row['opening_tax'];
            $totals['total_taxable'] += $row['total_taxable'];
            $totals['total_tax'] += $row['total_tax'];
            $rows[] = $row;
        }

        return [
            'year' => $taxYear,
            'label' => TaxYear::label($taxYear, $start),
            'months' => $months,
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * The line's gross taxable pay: breakdown tax.gross_taxable_pay, else the taxable_pay column (older lines).
     */
    private static function grossTaxable(\stdClass $item): int
    {
        $breakdown = is_string($item->breakdown) ? json_decode($item->breakdown, true) : null;
        $gross = is_array($breakdown) ? ($breakdown['tax']['gross_taxable_pay'] ?? null) : null;

        return (int) ($gross ?? $item->taxable_pay);
    }
}
