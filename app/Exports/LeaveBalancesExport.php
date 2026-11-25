<?php

namespace App\Exports;

use App\Models\Employee;
use App\Models\LeaveType;
use App\Support\Leave\BalanceLine;
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
 * One leave year's balances as a workbook (Leave > Balances > Export): one row per employee (code, name, department),
 * then for each active paid type its allocated, carried, adjustment, opening used, used, pending and remaining. Built
 * from LeaveBalances::forEmployees lines the controller has already read, so writing the file runs no query.
 *
 * Unpaid types are left out (they have no balance; a paid type later made unpaid keeps old rows that are never shown).
 * A type with no line for a person (should not happen for an active type) leaves its cells empty.
 *
 * Code, name and department are text cells (TextCells: never a formula). The figures are numeric cells with one
 * decimal (format 0.0), so Excel can sum them.
 */
final class LeaveBalancesExport implements FromArray, WithCustomValueBinder, WithEvents, WithHeadings, WithStrictNullComparison, WithTitle
{
    use TextCells {
        bindValue as private bindText;
    }

    /** The figures written per type, in order: BalanceLine property => heading suffix. */
    public const FIGURES = [
        'allocated' => 'Allocated',
        'carried' => 'Carried',
        'adjustment' => 'Adjustment',
        'openingUsed' => 'Opening Used',
        'used' => 'Used',
        'pending' => 'Pending',
        'remaining' => 'Remaining',
    ];

    /**
     * @param  list<LeaveType>  $types  active paid types, in column order
     * @param  list<Employee>  $employees  in row order, with department loaded
     * @param  array<int, array<int, BalanceLine>>  $lines  employee id => type id => line
     */
    public function __construct(
        private readonly array $types,
        private readonly array $employees,
        private readonly array $lines,
    ) {}

    public function title(): string
    {
        return 'Leave Balances';
    }

    /** @return list<string> */
    public function headings(): array
    {
        $headings = ['Employee Code', 'Name', 'Department'];

        foreach ($this->types as $type) {
            foreach (self::FIGURES as $label) {
                $headings[] = "{$type->name} {$label}";
            }
        }

        return $headings;
    }

    /** @return list<list<string|float|null>> */
    public function array(): array
    {
        $rows = [];

        foreach ($this->employees as $employee) {
            $row = [
                (string) $employee->employee_code,
                (string) $employee->full_name,
                (string) $employee->department?->name,
            ];

            foreach ($this->types as $type) {
                $line = $this->lines[$employee->id][$type->id] ?? null;

                foreach (array_keys(self::FIGURES) as $figure) {
                    $row[] = $line === null ? null : round((float) $line->{$figure}, 1);
                }
            }

            $rows[] = $row;
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $columns = count($this->headings());
                self::styleSheet($sheet, $columns);

                $lastRow = max(2, count($this->employees) + 1);

                for ($i = 4; $i <= $columns; $i++) {
                    $column = Coordinate::stringFromColumnIndex($i);
                    $sheet->getStyle("{$column}2:{$column}{$lastRow}")->getNumberFormat()->setFormatCode('0.0');
                    $sheet->getColumnDimension($column)->setWidth(14);
                }
            },
        ];
    }

    /** Floats (the figures) as numeric cells; everything else as text (TextCells). */
    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        return $this->bindText($cell, $value);
    }
}
