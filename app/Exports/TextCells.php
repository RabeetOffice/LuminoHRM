<?php

namespace App\Exports;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Shared by the employee template and the export: every value is written as an explicit TEXT cell (so a CNIC, phone,
 * machine ID or account number never turns into a number, and nothing is ever a formula). Text that a spreadsheet would
 * treat as a formula also gets the cell style's "quote prefix" flag, which keeps Excel from re-reading it as a formula
 * when someone edits the cell. The value itself is never changed: no literal "'" is added, so "+92 300 1234567" shows and
 * re-imports exactly as it was.
 */
trait TextCells
{
    /** Characters that make a spreadsheet read a cell as a formula (or a DDE payload). */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** Whether a spreadsheet would read this text as a formula (or a DDE payload) if it were typed in. */
    public static function looksLikeFormula(string $text): bool
    {
        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true);
    }

    /** WithCustomValueBinder: strings as strings, always; formula-looking text also gets the quote-prefix style. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return (new DefaultValueBinder)->bindValue($cell, null);
        }

        $text = (string) $value;
        $cell->setValueExplicit($text, DataType::TYPE_STRING);

        if (self::looksLikeFormula($text)) {
            $cell->getStyle()->setQuotePrefix(true);
        }

        return true;
    }

    /** Bold, frozen header and text format for the columns, so Excel does not turn typed dates or digits into numbers. */
    private static function styleSheet(Worksheet $sheet, int $columns): void
    {
        $last = Coordinate::stringFromColumnIndex($columns);

        $sheet->getStyle("A1:{$last}1")->getFont()->setBold(true);
        $sheet->getStyle("A1:{$last}1")->getFill()->setFillType('solid')->getStartColor()->setRGB('EEF2F6');
        $sheet->freezePane('A2');

        $text = new Style;
        $text->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        $sheet->getParent()?->addCellXf($text);

        for ($i = 1; $i <= $columns; $i++) {
            $column = Coordinate::stringFromColumnIndex($i);
            $dimension = $sheet->getColumnDimension($column);
            $dimension->setXfIndex($text->getIndex());
            $dimension->setWidth(20);
        }

        $highest = $sheet->getHighestRow();

        if ($highest >= 2) {
            $sheet->getStyle("A2:{$last}{$highest}")->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);
        }
    }
}
