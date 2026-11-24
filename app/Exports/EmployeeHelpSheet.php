<?php

namespace App\Exports;

use App\Imports\EmployeeSheet;
use App\Models\Department;
use App\Models\Designation;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/** Sheet 2 of EmployeeTemplateExport: what each column takes, and the departments and designations. */
final class EmployeeHelpSheet implements FromArray, WithCustomValueBinder, WithEvents, WithTitle
{
    use TextCells;

    private const FORMATS = [
        'first_name' => 'Text, up to 100 characters.',
        'last_name' => 'Text, up to 100 characters.',
        'email' => 'Email address. Must not belong to another employee. Becomes the login.',
        'phone' => 'Text, up to 30 characters.',
        'dob' => 'Date as YYYY-MM-DD (DD/MM/YYYY and Excel dates are also read). At least 16 years before the joining date.',
        'gender' => 'One of the allowed values (any letter case).',
        'cnic' => '13 digits, with or without dashes (12345-1234567-1).',
        'department' => 'The name of an existing department (see the list below).',
        'designation' => 'The name of a designation of that department (see the list below).',
        'manager_email' => 'Work email of an Active or Probation employee, or of another row in this file. Leave blank for none.',
        'joining_date' => 'Date as YYYY-MM-DD (DD/MM/YYYY and Excel dates are also read).',
        'employment_type' => 'One of the allowed values (any letter case).',
        'status' => 'One of the allowed values. Inactive, Resigned and Terminated people get a disabled login.',
        'device_user_id' => 'The ID the attendance machine knows the person by. Up to 20 characters, unique.',
        'employee_code' => 'Leave blank: codes like LD-0001 are assigned in row order. Only an Admin may fill it.',
        'address' => 'Text, up to 1,000 characters.',
        'emergency_name' => 'Text, up to 100 characters.',
        'emergency_phone' => 'Text, up to 30 characters.',
        'bank_name' => 'Text, up to 100 characters.',
        'account_title' => 'Text, up to 100 characters.',
        'account_number' => 'Text, up to 50 characters.',
    ];

    public function title(): string
    {
        return 'Help';
    }

    public function array(): array
    {
        $rows = [['Column', 'Required', 'Format', 'Allowed values']];

        foreach (EmployeeSheet::COLUMNS as $key) {
            $allowed = isset(EmployeeSheet::ENUMS[$key])
                ? implode(', ', array_map(EmployeeSheet::enumLabel(...), EmployeeSheet::enumValues($key)))
                : '';

            $rows[] = [EmployeeSheet::label($key), EmployeeSheet::isRequired($key) ? 'Required' : 'Optional', self::FORMATS[$key], $allowed];
        }

        $rows[] = [];
        $rows[] = ['Notes'];
        $rows[] = ['Keep the first row of the Employees sheet as it is. Up to 2,000 rows and 5 MB per file, .xlsx only.'];
        $rows[] = ['The example row (First Name "'.EmployeeSheet::EXAMPLE_MARKER.'") is skipped. Nothing is saved until every row is ready.'];
        $rows[] = ['No email is sent during the import. Send set-password links from the Employees list afterwards.'];
        $rows[] = [];
        $rows[] = ['Department', 'Designations (as of '.now(settings('company.timezone', config('app.timezone')))->format('Y-m-d').')'];

        $designations = Designation::query()->orderBy('name')->get(['name', 'department_id'])->groupBy('department_id');

        foreach (Department::query()->orderBy('name')->get(['id', 'name']) as $department) {
            $names = ($designations[$department->id] ?? collect())->pluck('name')->all();
            $rows[] = [$department->name, implode(', ', $names)];
        }

        return $rows;
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event): void {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A1:D1')->getFont()->setBold(true);
                $sheet->freezePane('A2');
                $sheet->getColumnDimension('A')->setWidth(26);
                $sheet->getColumnDimension('B')->setWidth(12);
                $sheet->getColumnDimension('C')->setWidth(90);
                $sheet->getColumnDimension('D')->setWidth(60);
            },
        ];
    }
}
