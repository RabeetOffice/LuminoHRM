<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\ApprovePayrollRun;
use App\Actions\Payroll\DeleteDraftRun;
use App\Actions\Payroll\GeneratePayrollRun;
use App\Actions\Payroll\MarkRunPaid;
use App\Http\Controllers\Controller;
use App\Http\Requests\PayrollGenerateRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\PayrollAccess;
use App\Support\Payroll\PayrollChecks;
use App\Support\PayrollLock;
use DateTimeImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll > Payroll Runs: the list, generating a month, and the run page (checks, totals, lines) with its actions.
 * Salary data: only payroll.view (Admin, HR) reaches any of these routes; Managers and Employees get 403 and no figure
 * ever reaches their props. Money is sent as integer paisa (the page formats it), months as "YYYY-MM", dates as "Y-m-d".
 *
 *   GET    /payroll/runs                     can:payroll.view
 *   POST   /payroll/runs                     can:payroll.run      generate (or recalculate the month's draft)
 *   GET    /payroll/runs/{run}               can:payroll.view
 *   POST   /payroll/runs/{run}/recalculate   can:payroll.run      all lines, or one with employee_id
 *   POST   /payroll/runs/{run}/approve       can:payroll.approve  field fingerprint; "changed" reloads with a message
 *   POST   /payroll/runs/{run}/paid          can:payroll.run      field paid_on
 *   DELETE /payroll/runs/{run}               can:payroll.run      a draft only
 *
 * The actions check the rights again (403) and the rules (errors on `month`, `run`, `paid_on`).
 * The run page reads a fixed number of queries whatever the headcount: the run, its three users, the lines with their
 * employees, departments, logins and roles, the checks (PayrollChecks), and one existence read for the own-line note.
 */
class PayrollRunController extends Controller
{
    /** Months offered when no month is approved yet. */
    private const WINDOW = 12;

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $runs = PayrollRun::query()
            ->with(['generatedBy:id,name', 'approvedBy:id,name'])
            ->orderByDesc('month')
            ->get();

        return Inertia::render('payroll/runs/index', [
            'runs' => $runs->map(fn (PayrollRun $run): array => [
                'id' => $run->id,
                'month' => $run->month,
                'label' => $run->label(),
                'status' => $run->status,
                'headcount' => $run->headcount,
                'gross' => $run->gross,
                'net' => $run->net,
                'generated_by' => $run->generatedBy?->name,
                'generated_on' => $run->generated_at?->format('Y-m-d'),
                'approved_by' => $run->approvedBy?->name,
                'approved_on' => $run->approved_at?->format('Y-m-d'),
            ])->values()->all(),
            'generate' => $user->can('payroll.run') ? $this->generateOptions($runs) : null,
        ]);
    }

    public function store(PayrollGenerateRequest $request, GeneratePayrollRun $generate): RedirectResponse
    {
        $run = $generate->generate($request->user(), (string) $request->validated('month'));

        return redirect()->route('payroll.runs.show', $run)
            ->with('success', 'Payroll for '.$run->label().' calculated.');
    }

    public function show(Request $request, PayrollRun $run): Response
    {
        /** @var User $user */
        $user = $request->user();
        $run->load(['generatedBy:id,name', 'approvedBy:id,name', 'paidBy:id,name']);
        $locked = $run->isLocked();
        $draft = $run->isDraft();
        $summary = $run->summary ?? [];

        // A draft is judged live; an approved or paid run keeps the warnings it was approved with.
        $checks = $draft ? PayrollChecks::for($run) : array_values((array) ($summary['checks'] ?? []));

        $items = PayrollItem::query()
            ->where('payroll_run_id', $run->id)
            ->join('employees', 'employees.id', '=', 'payroll_items.employee_id')
            ->orderBy('employees.employee_code')
            ->orderBy('payroll_items.id')
            ->select('payroll_items.*')
            ->with(['employee.department:id,name', 'employee.user.roles'])
            ->get();

        $mayRun = $user->can('payroll.run');
        $payslipLinks = $locked && Route::has('payroll.payslips.download');

        return Inertia::render('payroll/runs/show', [
            'run' => [
                'id' => $run->id,
                'month' => $run->month,
                'label' => $run->label(),
                'status' => $run->status,
                'headcount' => $run->headcount,
                'gross' => $run->gross,
                'deductions' => $run->deductions,
                'tax' => $run->tax,
                'net' => $run->net,
                'generated_by' => $run->generatedBy?->name,
                'generated_on' => $run->generated_at?->format('Y-m-d'),
                'calculated_on' => $run->calculated_at?->format('Y-m-d'),
                'approved_by' => $run->approvedBy?->name,
                'approved_on' => $run->approved_at?->format('Y-m-d'),
                'paid_by' => $run->paidBy?->name,
                'paid_on' => $run->paid_on?->format('Y-m-d'),
                // The figures this page shows; sent back on approve so a change in between is caught.
                'fingerprint' => $run->fingerprint(),
                'can_edit' => $draft && $mayRun,
                'can_approve' => $draft && PayrollAccess::mayApprove($user, $run),
                'can_pay' => $run->status === 'approved' && $mayRun,
                'approves_own' => PayrollAccess::approvesOwn($user, $run),
                'payslip_count' => $items->where('excluded', false)->count(),
                // Of those, the people with an active login and an email: the ones the payslip is mailed to.
                'emailed_count' => $items->where('excluded', false)->filter(
                    fn (PayrollItem $item): bool => $item->employee->user?->is_active === true
                        && trim((string) $item->employee->user->email) !== '',
                )->count(),
                'today' => app(AttendanceClock::class)->attendanceToday(),
            ],
            'checks' => $checks,
            'lines' => $items->map(fn (PayrollItem $item): array => [
                'id' => $item->id,
                'employee' => [
                    'id' => $item->employee_id,
                    'name' => $item->employee->full_name,
                    'code' => $item->employee->employee_code,
                    'department' => $item->employee->department?->name,
                ],
                'days_paid' => $item->days_paid,
                'gross' => $item->gross,
                'attendance_deduction' => $item->attendance_deduction,
                // Fixed and one-off deductions together; the drawer splits them.
                'other_deductions' => $item->fixed_deductions + $item->other_deductions,
                'loan_deduction' => $item->loan_deduction,
                'tax' => $item->tax,
                'net' => $item->net,
                'excluded' => $item->excluded,
                'excluded_reason' => $item->excluded_reason,
                'tax_overridden' => $item->tax_overridden,
                'warnings' => count((array) $item->warnings),
                'blocking' => $item->blocking,
                'can_edit' => $draft && $mayRun && PayrollAccess::mayEditSalary($user, $item->employee),
                'payslip_url' => $payslipLinks && ! $item->excluded
                    ? route('payroll.payslips.download', $item)
                    : null,
            ])->values()->all(),
            'settled_separately' => array_values((array) ($summary['settled_separately'] ?? [])),
            'left_without_last_day' => array_values((array) ($summary['left_without_last_day'] ?? [])),
            'downloads' => [
                'bank_sheet' => $locked && $mayRun && Route::has('payroll.runs.bank-sheet')
                    ? route('payroll.runs.bank-sheet', $run) : null,
                'summary' => $locked && Route::has('payroll.runs.summary-export')
                    ? route('payroll.runs.summary-export', $run) : null,
                'payslips' => $locked && Route::has('payroll.payslips.index')
                    ? route('payroll.payslips.index', ['month' => $run->month]) : null,
            ],
        ]);
    }

    public function recalculate(Request $request, PayrollRun $run, GeneratePayrollRun $generate): RedirectResponse
    {
        $data = $request->validate(['employee_id' => ['nullable', 'integer', 'min:1']]);
        $employeeId = isset($data['employee_id']) ? (int) $data['employee_id'] : null;

        $generate->recalculate($request->user(), $run, $employeeId);

        return back()->with('success', $employeeId === null ? 'Payroll recalculated.' : 'Line recalculated.');
    }

    public function approve(Request $request, PayrollRun $run, ApprovePayrollRun $approve): RedirectResponse
    {
        $data = $request->validate(
            ['fingerprint' => ['required', 'string', 'size:64']],
            ['fingerprint.required' => 'Reload the page and review the figures again.', 'fingerprint.size' => 'Reload the page and review the figures again.'],
        );

        if ($approve->approve($request->user(), $run, $data['fingerprint']) === 'changed') {
            return back()->with('error', $approve->changedLines > 0
                ? "Figures changed for {$approve->changedLines} ".($approve->changedLines === 1 ? 'person' : 'people').' — review and approve again.'
                : 'The figures changed since you opened this page — review and approve again.');
        }

        return back()->with('success', 'Payroll for '.$run->label().' approved. Payslips are on their way.');
    }

    public function paid(Request $request, PayrollRun $run, MarkRunPaid $paid): RedirectResponse
    {
        $data = $request->validate(
            ['paid_on' => ['required', 'string', 'regex:/^\d{4}-\d{2}-\d{2}$/']],
            ['paid_on.required' => 'Enter the payment date.', 'paid_on.regex' => 'Enter the payment date.'],
        );

        $paid->handle($request->user(), $run, $data['paid_on']);

        return back()->with('success', 'Payroll for '.$run->label().' marked paid.');
    }

    public function destroy(Request $request, PayrollRun $run, DeleteDraftRun $delete): RedirectResponse
    {
        $label = $run->label();
        $delete->handle($request->user(), $run);

        return redirect()->route('payroll.runs.index')->with('success', "The draft payroll for {$label} was deleted.");
    }

    /**
     * The months the Generate dialog offers, newest first: from the month after the latest approved one (else the last
     * twelve) up to the current attendance month, and the default (last month, else the first open one).
     *
     * @param  Collection<int, PayrollRun>  $runs
     * @return array{months: list<array{value: string, label: string, has_draft: bool}>, default: string|null}
     */
    private function generateOptions(Collection $runs): array
    {
        $current = substr(app(AttendanceClock::class)->attendanceToday(), 0, 7);
        $latest = PayrollLock::latestLocked();
        $first = new DateTimeImmutable($current.'-01');
        $floor = $latest === null
            ? $first->modify('-'.(self::WINDOW - 1).' months')->format('Y-m')
            : (new DateTimeImmutable($latest.'-01'))->modify('+1 month')->format('Y-m');
        $drafts = $runs->where('status', 'draft')->pluck('month')->flip();

        $months = [];
        for ($month = $current; $month >= $floor; $month = (new DateTimeImmutable($month.'-01'))->modify('-1 month')->format('Y-m')) {
            $months[] = [
                'value' => $month,
                'label' => PayrollRun::monthLabel($month),
                'has_draft' => $drafts->has($month),
            ];
        }

        $previous = $first->modify('-1 month')->format('Y-m');
        $default = $months === [] ? null : ($previous >= $floor ? $previous : $months[array_key_last($months)]['value']);

        return ['months' => $months, 'default' => $default];
    }
}
