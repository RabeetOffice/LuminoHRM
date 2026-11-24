<?php

namespace App\Exports;

use App\Imports\EmployeeSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/** Sheet 1 of EmployeeTemplateExport: the header row and the example row. */
final class EmployeeTemplateSheet implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    use TextCells;

    private const EXAMPLE = [
        'first_name' => 'EXAMPLE - replace or delete this row',
        'last_name' => 'Khan',
        'email' => 'ayesha.khan@example.com',
        'phone' => '0300-1234567',
        'dob' => '1990-05-17',
        'gender' => 'Female',
        'cnic' => '42201-1234567-1',
        'department' => 'Engineering',
        'designation' => 'Software Engineer',
        'manager_email' => '',
        'joining_date' => '2024-03-01',
        'employment_type' => 'Full Time',
        'status' => 'Active',
        'device_user_id' => '101',
        'employee_code' => '',
        'address' => '12 Garden Road, Karachi',
        'emergency_name' => 'Sara Khan',
        'emergency_phone' => '0300-7654321',
        'bank_name' => 'Meezan Bank',
        'account_title' => 'Ayesha Khan',
        'account_number' => 'PK36MEZN0000001234567890',
    ];

    public function title(): string
    {
        return 'Employees';
    }

    public function array(): array
    {
        return [
            EmployeeSheet::headers(),
            array_map(fn (string $key) => self::EXAMPLE[$key], array_values(EmployeeSheet::COLUMNS)),
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                self::styleSheet($sheet, count(EmployeeSheet::COLUMNS));

                $example = $sheet->getStyle('A2:U2');
                $example->getFont()->setItalic(true)->getColor()->setRGB('808080');
            },
        ];
    }
}
