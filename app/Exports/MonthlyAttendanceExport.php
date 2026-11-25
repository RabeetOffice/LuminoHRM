<?php

namespace App\Exports;

use App\Models\Employee;
use App\Support\Attendance\MonthGrid;
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
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

/**
 * One month of attendance as a workbook (Attendance > Monthly > Export): one row per employee, one column per day with
 * the status letter, then the month's totals. Built from MonthGrid rows that the controller has already read (one
 * attendance query per 500 people), so writing the file runs no query.
 *
 * Letters: P present, L late, HD half day, A absent, LV on leave, H holiday, W weekend; "…" pending calculation (a
 * row whose status is not worked out yet, never counted); "–" no record (also a day outside the employment window or
 * still to come). A key row under the table (after one blank row) says so.
 *
 * Names, codes and day letters are text cells (TextCells), so a name starting with "=" stays text. The totals (counts,
 * hours, minutes) are numeric cells, so Excel can sum them; a number is never read as a formula.
 */
final class MonthlyAttendanceExport implements FromArray, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use TextCells {
        bindValue as private bindText;
    }

    /** MonthGrid codes => the letters written in the file. */
    public const LETTERS = ['P' => 'P', 'L' => 'L', 'H' => 'HD', 'A' => 'A', 'V' => 'LV', 'O' => 'H', 'W' => 'W'];

    public const BLANK = '–';

    /** A day whose row is still pending calculation (status null). */
    public const PENDING = '…';

    public const KEY = 'Key: P present · L late · HD half day · A absent · LV on leave · H holiday · W weekend · … pending calculation (not counted) · – no record';

    private const TOTALS = [
        'Present', 'Late', 'Half Day', 'Absent', 'On Leave', 'Weekend/Holiday', 'Present-equivalent', 'Working Days',
        'Worked Hours', 'Late Minutes', 'Overtime Minutes',
    ];

    /**
     * @param  list<array{day: int, date: string, weekday: string, future: bool, holiday: string|null}>  $days
     * @param  list<Employee>  $employees  in row order, with department and designation loaded
     * @param  array<int, array{window: mixed, cells: list<list<int|string|null>|null>, totals: array<string, int|float>}>  $rows  MonthGrid::rows()
     */
    public function __construct(
        private readonly array $days,
        private readonly array $employees,
        private readonly array $rows,
    ) {}

    public function title(): string
    {
        return 'Attendance';
    }

    /** @return list<string> */
    public function headings(): array
    {
        return [
            'Employee Code', 'Name', 'Department', 'Designation',
            ...array_map(fn (array $d) => sprintf('%02d %s', $d['day'], $d['weekday']), $this->days),
            ...self::TOTALS,
        ];
    }

    /** @return list<list<string|int|float>> */
    public function array(): array
    {
        $lines = [];

        foreach ($this->employees as $employee) {
            $row = $this->rows[$employee->id];
            $t = $row['totals'];

            $lines[] = [
                ...array_map(strval(...), [
                    $employee->employee_code,
                    $employee->full_name,
                    $employee->department?->name,
                    $employee->designation?->name,
                ]),
                ...array_map(fn (?array $cell) => self::letter($cell), $row['cells']),
                // Numbers, so the totals can be summed in Excel.
                (int) $t['present'], (int) $t['late'], (int) $t['half_day'], (int) $t['absent'], (int) $t['on_leave'], (int) $t['off'],
                self::number($t['equivalent']), (int) $t['working_days'],
                round($t['worked_minutes'] / 60, 2),
                (int) $t['late_minutes'], (int) $t['overtime_minutes'],
            ];
        }

        return $lines;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                self::styleSheet($sheet, count($this->headings()));

                // Day columns only need room for a letter or two.
                for ($i = 5; $i < 5 + count($this->days); $i++) {
                    $sheet->getColumnDimensionByColumn($i)->setWidth(7);
                }

                // The totals are numbers: a number format instead of the text format styleSheet() gives every column.
                $firstTotal = 5 + count($this->days);
                $lastRow = max(2, count($this->employees) + 1);

                for ($i = $firstTotal; $i < $firstTotal + count(self::TOTALS); $i++) {
                    $column = Coordinate::stringFromColumnIndex($i);
                    $format = self::TOTALS[$i - $firstTotal] === 'Worked Hours' ? '0.00' : NumberFormat::FORMAT_GENERAL;
                    $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode($format);
                }

                // One blank row under the table, then the key to the marks.
                $sheet->setCellValueExplicit('A'.(count($this->employees) + 3), self::KEY, DataType::TYPE_STRING);
            },
        ];
    }

    /**
     * Strings as text cells (TextCells); ints and floats (the totals) as numeric cells. A number cannot be read as a
     * formula, so this stays formula-safe.
     */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return $this->bindText($cell, $value);
    }

    /**
     * The letter written for one MonthGrid cell.
     *
     * @param  list<int|string|null>|null  $cell
     */
    private static function letter(?array $cell): string
    {
        if ($cell === null) {
            return self::BLANK;
        }

        if ($cell[0] === MonthGrid::PENDING) {
            return self::PENDING;
        }

        return self::LETTERS[$cell[0]] ?? self::BLANK;
    }

    /** 21 or 20.5: an int when whole. */
    private static function number(int|float $value): int|float
    {
        return fmod((float) $value, 1.0) === 0.0 ? (int) $value : (float) $value;
    }
}
