<?php

namespace App\Exports;

use App\Imports\EmployeeSheet;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * The employees list as a workbook: the rows of the list's query (EmployeeListQuery, every page of it), with exactly the
 * template's columns in the template's order, so an export can be edited and imported again. Only people holding
 * employees.view-all (who see every field group) may export, so every column is included.
 *
 * Every cell is a text cell; text that begins like a formula (=, +, -, @, tab, carriage return) gets a leading "'".
 * The query is read in chunks (FromQuery), so a few thousand rows stay comfortable.
 */
final class EmployeesExport implements FromQuery, WithCustomValueBinder, WithEvents, WithHeadings, WithMapping, WithTitle
{
    use TextCells;

    /** @param  Builder<Employee>  $rows  the list's query, already filtered and ordered (ends with a unique id) */
    public function __construct(private readonly Builder $rows) {}

    public function query(): Builder
    {
        return $this->rows->with([
            'department:id,name',
            'designation:id,name',
            'manager:id,email',
            'contact',
            'bankAccount',
        ]);
    }

    public function title(): string
    {
        return 'Employees';
    }

    public function headings(): array
    {
        return EmployeeSheet::headers();
    }

    /**
     * @param  Employee  $row
     * @return list<string>
     */
    public function map($row): array
    {
        $values = [
            'first_name' => $row->first_name,
            'last_name' => $row->last_name,
            'email' => $row->email,
            'phone' => $row->phone,
            'dob' => $row->dob?->format('Y-m-d'),
            'gender' => EmployeeSheet::enumLabel($row->gender),
            'cnic' => EmployeeSheet::formatCnic($row->cnic),
            'department' => $row->department?->name,
            'designation' => $row->designation?->name,
            'manager_email' => $row->manager?->email,
            'joining_date' => $row->joining_date->format('Y-m-d'),
            'employment_type' => EmployeeSheet::enumLabel($row->employment_type),
            'status' => EmployeeSheet::enumLabel($row->status),
            'device_user_id' => $row->device_user_id,
            'employee_code' => $row->employee_code,
            'address' => $row->contact?->address,
            'emergency_name' => $row->contact?->emergency_name,
            'emergency_phone' => $row->contact?->emergency_phone,
            'bank_name' => $row->bankAccount?->bank_name,
            'account_title' => $row->bankAccount?->account_title,
            'account_number' => $row->bankAccount?->account_number,
        ];

        return array_map(fn (string $key) => self::safe($values[$key]), array_values(EmployeeSheet::COLUMNS));
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => self::styleSheet($event->sheet->getDelegate(), count(EmployeeSheet::COLUMNS)),
        ];
    }
}
