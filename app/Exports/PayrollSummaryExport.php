<?php

namespace App\Exports;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithProperties;

/**
 * The monthly payroll summary of a run, any status (Payroll > Runs > Summary): payroll-summary-2026-11.xlsx, or
 * payroll-summary-2026-11-DRAFT.xlsx with a sheet titled "DRAFT November 2026" for a draft (its figures may still
 * change).
 *
 * One row per line: code, name, department (text), days paid and every figure column of the item in whole rupees
 * (numeric cells), excluded ("Yes" / "No"); then a totals row. Figures are the stored item columns; code, name and
 * department from the frozen breakdown when present, else the employee record (department loaded). No query.
 */
final class PayrollSummaryExport implements Export, WithMultipleSheets, WithProperties
{
    /** Item figure columns, in order => heading. */
    public const COLUMNS = [
        'basic' => 'Basic',
        'allowances' => 'Allowances',
        'overtime' => 'Overtime',
        'additions' => 'Additions',
        'gross' => 'Gross',
        'attendance_deduction' => 'Attendance Deduction',
        'fixed_deductions' => 'Fixed Deductions',
        'other_deductions' => 'Other Deductions',
        'loan_deduction' => 'Loan Deduction',
        'taxable_pay' => 'Taxable Pay',
        'tax' => 'Tax',
        'net' => 'Net',
    ];

    /** @var list<PayrollItem> */
    private readonly array $items;

    /** @param  iterable<PayrollItem>  $items  employee (with department) loaded, in row order */
    public function __construct(private readonly PayrollRun $run, iterable $items)
    {
        $list = [];
        foreach ($items as $item) {
            $list[] = $item;
        }
        $this->items = $list;
    }

    public static function fileName(PayrollRun $run): string
    {
        return "payroll-summary-{$run->month}".($run->isDraft() ? '-DRAFT' : '').'.xlsx';
    }

    public function title(): string
    {
        return ($this->run->isDraft() ? 'DRAFT ' : '').$this->run->label();
    }

    public function rowCount(): int
    {
        return count($this->items);
    }

    /** @return array<string, string> */
    public function properties(): array
    {
        return ['title' => ($this->run->isDraft() ? 'DRAFT - ' : '').'Payroll summary '.$this->run->label()];
    }

    /** @return list<PayrollSheet> */
    public function sheets(): array
    {
        $rows = [];
        $sums = array_fill_keys(array_keys(self::COLUMNS), 0);
        $days = 0;

        foreach ($this->items as $item) {
            [$code, $name] = BankSheetExport::identity($item);
            $frozen = is_array($item->breakdown) ? ($item->breakdown['employee'] ?? null) : null;
            $department = is_array($frozen) && array_key_exists('department', $frozen)
                ? (string) $frozen['department']
                : (string) $item->employee?->department?->name;

            $row = [$code, $name, $department, $item->days_paid];
            foreach (array_keys(self::COLUMNS) as $column) {
                $row[] = PayrollSheet::rupees((int) $item->{$column});
                $sums[$column] += (int) $item->{$column};
            }
            $row[] = $item->excluded ? 'Yes' : 'No';
            $days += $item->days_paid;
            $rows[] = $row;
        }

        $totals = ['', 'Total', '', $days];
        foreach ($sums as $sum) {
            $totals[] = PayrollSheet::rupees($sum);
        }
        $totals[] = '';

        return [new PayrollSheet(
            $this->title(),
            ['Employee Code', 'Name', 'Department', 'Days Paid', ...array_values(self::COLUMNS), 'Excluded'],
            $rows,
            $totals,
            range(4, 4 + count(self::COLUMNS)),
        )];
    }
}
