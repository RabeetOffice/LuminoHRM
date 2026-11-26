<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayslipPdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * My Payslips: the signed-in person's OWN payslips, for every role.
 *
 *   GET /my/payslips              the list
 *   GET /my/payslips/{item}       one PDF (inline)
 *
 * The record is the account's own employee_id. Only lines of approved / paid runs that are not excluded are listed or
 * served: never a draft. Any other item id (someone else's, a draft's, an excluded line) is 403. Figures come from the
 * frozen breakdown totals, formatted on the server. A user without an employee record gets employee = null.
 *
 * Props: employee {name} | null, payslips: list of {id, month, label, net, gross, tax} (newest first).
 */
class MyPayslipController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);

        $payslips = $employee === null ? [] : self::ownItems($employee->id)
            ->get()
            ->map(function (PayrollItem $item): array {
                $totals = (array) (($item->breakdown ?? [])['totals'] ?? []);

                return [
                    'id' => $item->id,
                    'month' => $item->run->month,
                    'label' => PayrollRun::monthLabel($item->run->month),
                    'net' => Money::format((int) ($totals['net'] ?? 0)),
                    'gross' => Money::format((int) ($totals['gross'] ?? 0)),
                    'tax' => Money::format((int) ($totals['tax'] ?? 0)),
                ];
            })->values()->all();

        return Inertia::render('my/payslips', [
            'employee' => $employee === null ? null : ['name' => $employee->full_name],
            'payslips' => $payslips,
        ]);
    }

    public function download(Request $request, PayrollItem $item): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $item->loadMissing('run');

        abort_unless(
            $user->employee_id !== null
                && $item->employee_id === $user->employee_id
                && ! $item->excluded
                && $item->run->isLocked(),
            403,
        );

        return self::pdfResponse($item);
    }

    /** The PDF of an item, inline, never cached by a shared cache. */
    public static function pdfResponse(PayrollItem $item): StreamedResponse
    {
        $path = PayslipPdf::pathOrRender($item);

        return PayslipPdf::disk()->response($path, PayslipPdf::filename($item), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], 'inline');
    }

    /** @return Builder<PayrollItem> */
    private static function ownItems(int $employeeId): Builder
    {
        return PayrollItem::query()
            ->select('payroll_items.*')
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->where('payroll_items.employee_id', $employeeId)
            ->where('payroll_items.excluded', false)
            ->whereIn('payroll_runs.status', (array) config('hrm.payroll.locked_statuses'))
            ->orderByDesc('payroll_runs.month')
            ->with('run');
    }
}
