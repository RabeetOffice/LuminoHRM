<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\Export;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * The import template: sheet 1 "Employees" (the header row, bold and frozen, and one grey example row that the import
 * skips because its First Name is exactly EmployeeSheet::EXAMPLE_MARKER), sheet 2 "Help" (each column, whether it is required, its format,
 * the allowed values, and the departments and designations that exist right now). Every cell is text.
 */
final class EmployeeTemplateExport implements Export, WithMultipleSheets
{
    public const FILE_NAME = 'employee-import-template.xlsx';

    public function sheets(): array
    {
        return [new EmployeeTemplateSheet, new EmployeeHelpSheet];
    }
}
