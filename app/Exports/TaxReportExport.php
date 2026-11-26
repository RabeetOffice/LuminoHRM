<?php

namespace App\Exports;

use App\Models\PayrollRun;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The yearly tax report as a workbook (Payroll > Tax Report > Export): tax-report-2026-27.xlsx, one sheet "Tax 2026-27".
 *
 * One row per employee of TaxReport::for: code and name (text), opening taxable income and opening tax, then per month
 * of the tax year its taxable pay and tax, then total taxable and total tax; a totals row. Every figure is a numeric
 * cell in whole rupees. Built from the report the controller has already read, so writing it runs no query.
 */
final class TaxReportExport implements Export, WithMultipleSheets
{
    /** @param  array{year: int, label: string, months: list<string>, rows: list<array<string, mixed>>, totals: array<string, mixed>}  $report  TaxReport::for */
    public function __construct(private readonly array $report) {}

    public static function fileName(string $label): string
    {
        return "tax-report-{$label}.xlsx";
    }

    /** @return list<PayrollSheet> */
    public function sheets(): array
    {
        $headings = ['Employee Code', 'Name', 'Opening Taxable Income', 'Opening Tax'];
        foreach ($this->report['months'] as $month) {
            $label = PayrollRun::monthLabel($month);
            $headings[] = "{$label} Taxable Pay";
            $headings[] = "{$label} Tax";
        }
        $headings[] = 'Total Taxable';
        $headings[] = 'Total Tax';

        $rows = [];
        foreach ($this->report['rows'] as $row) {
            $rows[] = [(string) $row['code'], (string) $row['name'], ...$this->figures($row)];
        }

        return [new PayrollSheet(
            "Tax {$this->report['label']}",
            $headings,
            $rows,
            ['', 'Total', ...$this->figures($this->report['totals'])],
            range(3, count($headings)),
        )];
    }

    /**
     * @param  array<string, mixed>  $line  a report row or the totals
     * @return list<int|float>
     */
    private function figures(array $line): array
    {
        $figures = [PayrollSheet::rupees((int) $line['opening_taxable']), PayrollSheet::rupees((int) $line['opening_tax'])];

        foreach ($this->report['months'] as $month) {
            $figures[] = PayrollSheet::rupees((int) ($line['months'][$month]['taxable'] ?? 0));
            $figures[] = PayrollSheet::rupees((int) ($line['months'][$month]['tax'] ?? 0));
        }

        $figures[] = PayrollSheet::rupees((int) $line['total_taxable']);
        $figures[] = PayrollSheet::rupees((int) $line['total_tax']);

        return $figures;
    }
}
