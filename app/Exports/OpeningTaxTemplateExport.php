<?php

namespace App\Exports;

use App\Actions\Payroll\SaveOpeningBalance;
use App\Imports\OpeningTaxImport;
use App\Support\Payroll\TaxYear;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * The opening-tax import template: sheet 1 "Opening Tax" (the header row only: Employee Code, Tax Year, Taxable Income
 * To Date, Tax Deducted To Date; bold, frozen; the code and tax-year columns formatted as text so Excel keeps
 * "2026-27" as typed, the amount columns as whole numbers), sheet 2 "Help" (what each column takes, the tax years
 * accepted, an example). Every written cell is text (TextCells, formula-safe).
 *
 * templateSheet() and helpSheet() are shared with OpeningLoansTemplateExport.
 */
final class OpeningTaxTemplateExport implements Export, WithMultipleSheets
{
    public const FILE_NAME = 'opening-tax-template.xlsx';

    public function sheets(): array
    {
        $start = SaveOpeningBalance::startMonth();
        [$current, $previous] = OpeningTaxImport::acceptedYears();
        $currentLabel = TaxYear::label($current, $start);
        $previousLabel = TaxYear::label($previous, $start);

        return [
            self::templateSheet('Opening Tax', array_keys(OpeningTaxImport::columns()), [1, 2]),
            self::helpSheet([
                ['Column', 'Required', 'Format'],
                ['Employee Code', 'Required', 'The code of an existing employee, for example LD-0001.'],
                ['Tax Year', 'Required', "{$currentLabel} or {$previousLabel}, written exactly like that."],
                ['Taxable Income To Date', 'Required', 'Whole rupees, 0 or more: the taxable income paid in that tax year before this app.'],
                ['Tax Deducted To Date', 'Required', 'Whole rupees, 0 or more: the income tax deducted in that tax year before this app.'],
                [],
                ['Notes'],
                ['One row per person and tax year. Up to 2,000 rows and 5 MB per file, .xlsx only.'],
                ['The figures replace what is stored (they are not added): importing the same file again changes nothing.'],
                ['Nothing is saved until every row is ready.'],
                [],
                ['Example', '', "LD-0001 | {$currentLabel} | 420000 | 21000"],
            ]),
        ];
    }

    /**
     * A sheet holding only a header row: bold, frozen, 20 wide; the 1-based $textColumns formatted as text, the others
     * as whole numbers.
     *
     * @param  list<string>  $headers
     * @param  list<int>  $textColumns
     */
    public static function templateSheet(string $title, array $headers, array $textColumns): FromArray
    {
        return new class($title, $headers, $textColumns) implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
        {
            use TextCells;

            /**
             * @param  list<string>  $headers
             * @param  list<int>  $textColumns
             */
            public function __construct(private readonly string $title, private readonly array $headers, private readonly array $textColumns) {}

            public function title(): string
            {
                return $this->title;
            }

            public function array(): array
            {
                return [$this->headers];
            }

            public function registerEvents(): array
            {
                return [
                    AfterSheet::class => function (AfterSheet $event): void {
                        $sheet = $event->sheet->getDelegate();
                        $count = count($this->headers);
                        $last = Coordinate::stringFromColumnIndex($count);

                        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
                        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('EEF2F6');
                        $sheet->freezePane('A2');

                        for ($i = 1; $i <= $count; $i++) {
                            $column = Coordinate::stringFromColumnIndex($i);
                            $sheet->getColumnDimension($column)->setWidth(22);
                            $format = in_array($i, $this->textColumns, true) ? NumberFormat::FORMAT_TEXT : NumberFormat::FORMAT_NUMBER;
                            $sheet->getStyle("{$column}2:{$column}2001")->getNumberFormat()->setFormatCode($format);
                        }
                    },
                ];
            }
        };
    }

    /** @param  list<list<string>>  $rows */
    public static function helpSheet(array $rows): FromArray
    {
        return new class($rows) implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
        {
            use TextCells;

            /** @param  list<list<string>>  $rows */
            public function __construct(private readonly array $rows) {}

            public function title(): string
            {
                return 'Help';
            }

            public function array(): array
            {
                return $this->rows;
            }

            public function registerEvents(): array
            {
                return [
                    AfterSheet::class => function (AfterSheet $event): void {
                        $sheet = $event->sheet->getDelegate();
                        $sheet->getStyle('A1:C1')->getFont()->setBold(true);
                        $sheet->freezePane('A2');
                        $sheet->getColumnDimension('A')->setWidth(24);
                        $sheet->getColumnDimension('B')->setWidth(12);
                        $sheet->getColumnDimension('C')->setWidth(100);
                    },
                ];
            }
        };
    }
}
