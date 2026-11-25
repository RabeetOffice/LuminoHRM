<?php

namespace App\Exports;

use App\Models\Employee;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One month of attendance as a workbook (Attendance > Monthly > Export): one row per employee, one column per day with
 * the status letter, then the month's totals. Built from MonthGrid rows that the controller has already read (one
 * attendance query per 500 people), so writing the file runs no query.
 *
 * Letters: P present, L late, HD half day, A absent, LV on leave, H holiday, W weekend; "–" no record, pending
 * calculation, outside the employment window or a day still to come. Every cell is a text cell (TextCells), so a
 * name starting with "=" stays text.
 */
final class MonthlyAttendanceExport implements FromArray, WithCustomValueBinder, WithEvents, WithHeadings, WithTitle
{
    use TextCells;

    /** MonthGrid codes => the letters written in the file. */
    public const LETTERS = ['P' => 'P', 'L' => 'L', 'H' => 'HD', 'A' => 'A', 'V' => 'LV', 'O' => 'H', 'W' => 'W'];

    public const BLANK = '–';

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

    /** @return list<list<string>> */
    public function array(): array
    {
        $lines = [];

        foreach ($this->employees as $employee) {
            $row = $this->rows[$employee->id];
            $t = $row['totals'];

            $lines[] = array_map(strval(...), [
                $employee->employee_code,
                $employee->full_name,
                $employee->department?->name,
                $employee->designation?->name,
                ...array_map(fn (?array $cell) => $cell === null ? self::BLANK : (self::LETTERS[$cell[0]] ?? self::BLANK), $row['cells']),
                $t['present'], $t['late'], $t['half_day'], $t['absent'], $t['on_leave'], $t['off'],
                self::number($t['equivalent']), $t['working_days'],
                number_format($t['worked_minutes'] / 60, 2, '.', ''),
                $t['late_minutes'], $t['overtime_minutes'],
            ]);
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
            },
        ];
    }

    /** 21 or 20.5: no trailing ".0". */
    private static function number(int|float $value): string
    {
        return fmod((float) $value, 1.0) === 0.0 ? (string) (int) $value : (string) $value;
    }
}
