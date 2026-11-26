<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * One sheet of a payroll workbook (bank sheet, payroll summary, tax report), built from rows the controller has
 * already read, so writing it runs no query.
 *
 * Strings are text cells (TextCells: an account number keeps its leading zeros and is never a number, and text a
 * spreadsheet would read as a formula gets the quote prefix). Ints and floats are numeric cells (whole rupees,
 * Payroll\Money paisa / 100 via self::rupees), formatted with thousands separators. An optional totals row is bold.
 */
final class PayrollSheet implements FromArray, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use TextCells {
        bindValue as private bindText;
    }

    /**
     * @param  list<string>  $headings
     * @param  list<list<string|int|float|null>>  $rows
     * @param  list<string|int|float|null>|null  $totals  written under the rows, bold
     * @param  list<int>  $numericColumns  1-based columns given the number format
     */
    public function __construct(
        private readonly string $title,
        private readonly array $headings,
        private readonly array $rows,
        private readonly ?array $totals = null,
        private readonly array $numericColumns = [],
    ) {}

    /** Paisa as whole rupees for a numeric cell (an int; a float only if paisa ever appear). */
    public static function rupees(int $paisa): int|float
    {
        return $paisa % 100 === 0 ? intdiv($paisa, 100) : $paisa / 100;
    }

    public function title(): string
    {
        return $this->title;
    }

    /** @return list<string> */
    public function headings(): array
    {
        return $this->headings;
    }

    /** @return list<list<string|int|float|null>> */
    public function array(): array
    {
        return $this->totals === null ? $this->rows : [...$this->rows, $this->totals];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $columns = count($this->headings);
                self::styleSheet($sheet, $columns);

                $lastRow = count($this->array()) + 1;

                foreach ($this->numericColumns as $index) {
                    $column = Coordinate::stringFromColumnIndex($index);
                    if ($lastRow >= 2) {
                        $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('#,##0');
                    }
                    $sheet->getColumnDimension($column)->setWidth(14);
                }

                if ($this->totals !== null) {
                    $last = Coordinate::stringFromColumnIndex($columns);
                    $sheet->getStyle("A{$lastRow}:{$last}{$lastRow}")->getFont()->setBold(true);
                }
            },
        ];
    }

    /** Numbers as numeric cells; everything else as text (TextCells). */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return $this->bindText($cell, $value);
    }
}
