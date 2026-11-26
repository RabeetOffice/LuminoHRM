<?php

namespace App\Http\Controllers\Payroll;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MyPayslipController;
use App\Mail\PayslipIssued;
use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeAccess;
use App\Support\ListQuery;
use App\Support\Payroll\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Payroll > Payslips: every payslip of approved / paid runs, by month and person (Admin / HR).
 *
 *   GET  /payroll/payslips?month=&search=&page=   can:payroll.view
 *   GET  /payroll/payslips/{item}                 can:payroll.view   the PDF (inline)
 *   POST /payroll/payslips/{item}/resend          can:payroll.run    queue the email again
 *
 * Only lines that are not excluded, of approved / paid runs: a draft's or an excluded line is 404. People are scoped
 * by EmployeeAccess (out of scope: 403 on an id, absent from the list). Figures are the stored item columns, formatted
 * on the server. Reads per page: the months filter, the count and the page (with runs and employees).
 */
class PayslipController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $search = ListQuery::stringParam($request, 'search');
        $months = PayrollRun::query()
            ->whereIn('status', (array) config('hrm.payroll.locked_statuses'))
            ->orderByDesc('month')
            ->pluck('month')
            ->all();
        $askedMonth = $request->query('month');
        $month = is_string($askedMonth) && in_array($askedMonth, $months, true) ? $askedMonth : null;

        $paginator = $this->items($user, $search, $month)
            ->paginate(self::PER_PAGE, ['payroll_items.*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('payroll/payslips/index', [
            'rows' => [
                'data' => $paginator->getCollection()->map(fn (PayrollItem $item): array => [
                    'id' => $item->id,
                    'month' => $item->run->month,
                    'month_label' => PayrollRun::monthLabel($item->run->month),
                    'employee' => [
                        'id' => $item->employee_id,
                        'name' => (string) $item->employee?->full_name,
                        'code' => (string) $item->employee?->employee_code,
                    ],
                    'net' => Money::format((int) $item->net),
                    'generated_at' => $item->payslip_generated_at?->toIso8601String(),
                    'emailed_at' => $item->payslip_emailed_at?->toIso8601String(),
                ])->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['search' => $search, 'month' => $month],
            'months' => array_map(fn (string $m): array => ['value' => $m, 'label' => PayrollRun::monthLabel($m)], $months),
            'can_resend' => $user->can('payroll.run'),
        ]);
    }

    public function download(Request $request, PayrollItem $item): StreamedResponse
    {
        $this->authorizeItem($request->user(), $item);

        return MyPayslipController::pdfResponse($item);
    }

    public function resend(Request $request, PayrollItem $item): RedirectResponse
    {
        /** @var User $by */
        $by = $request->user();
        $this->authorizeItem($by, $item);

        $login = User::query()
            ->where('employee_id', $item->employee_id)
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        if ($login === null) {
            throw ValidationException::withMessages(['payslip' => 'This person has no active login with an email, so the payslip cannot be emailed.']);
        }

        DB::transaction(function () use ($by, $item, $login): void {
            // Only the payslip column; the line's frozen figures are never written.
            PayrollItem::query()->whereKey($item->id)->update(['payslip_emailed_at' => now()]);

            Audit::record('payroll', 'Payslip resent', $item->employee, [
                'run' => $item->payroll_run_id,
                'month' => $item->run->month,
                'item' => $item->id,
            ], $by);

            try {
                Mail::to($login)->queue(new PayslipIssued($item));
            } catch (Throwable $e) {
                report($e);
            }
        }, 3);

        $name = $item->employee !== null ? $item->employee->full_name : 'the employee';

        return back()->with('success', "The payslip will be emailed to {$name}.");
    }

    /** Approved / paid and not excluded (else 404); the person within the viewer's scope (else 403). */
    private function authorizeItem(User $user, PayrollItem $item): void
    {
        $item->loadMissing(['run', 'employee']);

        abort_if($item->excluded || ! $item->run->isLocked(), 404);
        abort_unless($item->employee !== null && (new EmployeeAccess($user))->canView($item->employee), 403);
    }

    /** @return Builder<PayrollItem> */
    private function items(User $user, string $search, ?string $month): Builder
    {
        $people = (new EmployeeAccess($user))->scope(Employee::query())->search($search)->select('employees.id');

        return PayrollItem::query()
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_items.payroll_run_id')
            ->join('employees', 'employees.id', '=', 'payroll_items.employee_id')
            ->whereIn('payroll_runs.status', (array) config('hrm.payroll.locked_statuses'))
            ->where('payroll_items.excluded', false)
            ->whereIn('payroll_items.employee_id', $people)
            ->when($month !== null, fn (Builder $q) => $q->where('payroll_runs.month', $month))
            ->orderByDesc('payroll_runs.month')
            ->orderBy('employees.first_name')
            ->orderBy('employees.last_name')
            ->orderBy('payroll_items.id')
            ->with(['run', 'employee:id,employee_code,first_name,last_name']);
    }
}
