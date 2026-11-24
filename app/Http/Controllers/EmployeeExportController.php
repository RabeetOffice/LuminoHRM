<?php

namespace App\Http\Controllers;

use App\Exports\EmployeesExport;
use App\Imports\EmployeeSheet;
use App\Support\Audit\Audit;
use App\Support\EmployeeListQuery;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /employees/export (employees.view-all): the employees list as .xlsx, with the list's current filters
 * (EmployeeListQuery, the list's own query) and every matching row. One audit entry per export: who, how many rows,
 * the filters and the number of columns; never row data.
 */
class EmployeeExportController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $list = EmployeeListQuery::fromRequest($request, $user);

        // Before the file is built: an export that cannot be logged does not happen.
        Audit::record('employees', 'Employees exported', null, [
            'rows' => $list->rows()->count(),
            'filters' => $list->filters(),
            'columns' => count(EmployeeSheet::COLUMNS),
        ], $user);

        $today = now(settings('company.timezone', config('app.timezone')))->format('Y-m-d');

        return Excel::download(new EmployeesExport($list->rows()), "employees-{$today}.xlsx", ExcelType::XLSX);
    }
}
