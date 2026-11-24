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
 * machine ID or account number never turns into a number, and nothing is ever a formula), and text that a spreadsheet
 * would treat as a formula is prefixed with a single quote.
 */
trait TextCells
{
    /** Characters that make a spreadsheet read a cell as a formula (or a DDE payload). */
    private const FORMULA_STARTS = ['=', '+', '-', '@', "\t", "\r"];

    /** The value with a leading "'" when it begins like a formula; '' for null. */
    public static function safe(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;

        return $text !== '' && in_array($text[0], self::FORMULA_STARTS, true) ? "'".$text : $text;
    }

    /** WithCustomValueBinder: strings as strings, always. */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value === null || $value === '') {
            return (new DefaultValueBinder)->bindValue($cell, null);
        }

        $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);

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
