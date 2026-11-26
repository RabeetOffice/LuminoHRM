<?php

namespace App\Http\Controllers\Payroll;

use App\Exports\BankSheetExport;
use App\Exports\PayrollSummaryExport;
use App\Http\Controllers\Controller;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Run downloads (salary data: Admin / HR only, checked by route middleware).
 *
 * GET /payroll/runs/{run}/bank-sheet  can:payroll.run   approved / paid runs only (a draft is 404): BankSheetExport
 * GET /payroll/runs/{run}/summary     can:payroll.view  any status (a draft file says DRAFT): PayrollSummaryExport
 *
 * Each export is audited before the file is built (an export that cannot be logged does not happen): subject the run,
 * the month and row counts, never a figure or an account number. Reads: the run and its lines with their employees
 * (and departments for the summary), whatever the headcount.
 */
class PayrollExportController extends Controller
{
    public function bankSheet(Request $request, PayrollRun $run): BinaryFileResponse
    {
        abort_unless($run->isLocked(), 404);

        /** @var User $user */
        $user = $request->user();
        $export = new BankSheetExport($run, $this->items($run, false));

        Audit::record('payroll', 'Bank sheet exported', $run, [
            'month' => $run->month,
            'rows' => $export->transferCount(),
            'outside_rows' => $export->outsideCount(),
        ], $user);

        return Excel::download($export, BankSheetExport::fileName($run), ExcelType::XLSX);
    }

    public function summary(Request $request, PayrollRun $run): BinaryFileResponse
    {
        /** @var User $user */
        $user = $request->user();
        $export = new PayrollSummaryExport($run, $this->items($run, true));

        Audit::record('payroll', 'Payroll summary exported', $run, [
            'month' => $run->month,
            'status' => $run->status,
            'rows' => $export->rowCount(),
        ], $user);

        return Excel::download($export, PayrollSummaryExport::fileName($run), ExcelType::XLSX);
    }

    /**
     * The run's lines ordered by employee code (two queries; three with departments).
     *
     * @return Collection<int, PayrollItem>
     */
    private function items(PayrollRun $run, bool $withDepartment): Collection
    {
        return $run->items()
            ->join('employees', 'employees.id', '=', 'payroll_items.employee_id')
            ->orderBy('employees.employee_code')
            ->orderBy('payroll_items.id')
            ->select('payroll_items.*')
            ->with($withDepartment ? ['employee.department:id,name'] : ['employee'])
            ->get();
    }
}
