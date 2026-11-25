<?php

namespace App\Exports;

use App\Imports\LeaveOpeningImport;
use App\Models\LeaveType;
use App\Support\Attendance\AttendanceClock;
use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * The opening-balance import template: sheet 1 "Opening Balances" (the header row only: Employee Code, Leave Type, Year,
 * Days Used; bold, frozen, text columns), sheet 2 "Help" (what each column takes, the active paid types that exist now,
 * the years accepted, and an example). Every cell is text (TextCells).
 */
final class LeaveOpeningTemplateExport implements Export, WithMultipleSheets
{
    public const FILE_NAME = 'leave-opening-balances-template.xlsx';

    public function sheets(): array
    {
        $current = (int) substr(app(AttendanceClock::class)->attendanceToday(), 0, 4);
        $types = array_values(LeaveType::query()->active()->where('is_paid', true)->orderBy('sort_order')->orderBy('id')->get()->map(fn (LeaveType $t): string => $t->name)->all());

        return [
            new class implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
            {
                use TextCells;

                public function title(): string
                {
                    return 'Opening Balances';
                }

                public function array(): array
                {
                    return [array_keys(LeaveOpeningImport::COLUMNS)];
                }

                public function registerEvents(): array
                {
                    return [
                        AfterSheet::class => function (AfterSheet $event): void {
                            self::styleSheet($event->sheet->getDelegate(), count(LeaveOpeningImport::COLUMNS));
                        },
                    ];
                }
            },
            new class($current, $types) implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
            {
                use TextCells;

                /** @param  list<string>  $types */
                public function __construct(private readonly int $current, private readonly array $types) {}

                public function title(): string
                {
                    return 'Help';
                }

                public function array(): array
                {
                    $previous = $this->current - 1;

                    return [
                        ['Column', 'Required', 'Format'],
                        ['Employee Code', 'Required', 'The code of an existing employee, for example LD-0001.'],
                        ['Leave Type', 'Required', 'The name of an active paid leave type: '.($this->types === [] ? '(none yet)' : implode(', ', $this->types)).'.'],
                        ['Year', 'Required', "{$this->current} or {$previous}."],
                        ['Days Used', 'Required', 'Days of that leave the person used in that year before this app, 0 to 365, whole or half days (e.g. 3.5).'],
                        [],
                        ['Notes'],
                        ['One row per person, leave type and year. Up to 2,000 rows and 5 MB per file, .xlsx only.'],
                        ['Days Used replaces the stored figure (it is not added): importing the same file again changes nothing.'],
                        ['Nothing is saved until every row is ready. A row that leaves someone overdrawn is shown as a warning and saved.'],
                        [],
                        ['Example', '', "LD-0001 | Annual | {$this->current} | 3.5"],
                    ];
                }

                public function registerEvents(): array
                {
                    return [
                        AfterSheet::class => function (AfterSheet $event): void {
                            $sheet = $event->sheet->getDelegate();
                            $sheet->getStyle('A1:C1')->getFont()->setBold(true);
                            $sheet->freezePane('A2');
                            $sheet->getColumnDimension('A')->setWidth(18);
                            $sheet->getColumnDimension('B')->setWidth(12);
                            $sheet->getColumnDimension('C')->setWidth(100);
                        },
                    ];
                }
            },
        ];
    }
}
