<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\LoanActions;
use App\Actions\Payroll\SaveLoan;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoanRequest;
use App\Models\Employee;
use App\Models\Loan;
use App\Models\LoanRepayment;
use App\Models\LoanSkip;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\LoanSchedule;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use App\Support\PayrollLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll > Loans & Advances. Reads need can:payroll.view (Admin, HR: every loan, Admin's included); writes
 * can:payroll.run, cancel can:payroll.approve (routes). Whose loan a user may change is PayrollAccess::mayEditSalary,
 * checked again by the actions (403). Managers and Employees never reach these routes, so no loan figure reaches them.
 *
 * Money is sent as integer paisa (the page formats it); months as "YYYY-MM"; dates as "Y-m-d" strings.
 *
 * The list reads remaining through Loan::withRemaining (one aggregate), with the employees and skips eager loaded and
 * the latest approved month read once: a fixed number of queries whatever the row count. The "next deduction" month is
 * LoanSchedule's first month from max(first deduction month, the month after the latest approved one), skips applied.
 */
class LoanController extends Controller
{
    private const STATUSES = ['active', 'cleared', 'cancelled', 'all'];

    public function index(Request $request): Response
    {
        $status = in_array($request->query('status'), self::STATUSES, true) ? (string) $request->query('status') : 'active';
        $search = is_string($request->query('search')) ? mb_substr(trim($request->query('search')), 0, 100) : '';

        $latest = PayrollLock::latestLocked();
        $from = $latest === null ? null : LoanSchedule::addMonth($latest);

        $loans = Loan::query()
            ->withRemaining()
            ->with(['employee:id,employee_code,first_name,last_name', 'skips:id,loan_id,month'])
            ->when($search !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->search($search)))
            ->orderBy('id', 'desc')
            ->get();

        $rows = $loans->map(fn (Loan $loan) => self::row($loan, $from));
        $counts = ['all' => $rows->count()];
        foreach (['active', 'cleared', 'cancelled'] as $key) {
            $counts[$key] = $rows->where('status', $key)->count();
        }

        return Inertia::render('payroll/loans/index', [
            'loans' => $rows->when($status !== 'all', fn ($c) => $c->where('status', $status))->values()->all(),
            'counts' => $counts,
            'filters' => ['status' => $status, 'search' => $search],
            'employees' => $request->user()->can('payroll.run') ? self::editableEmployees($request->user()) : [],
            'months' => self::months($latest),
        ]);
    }

    public function show(Request $request, Loan $loan): Response
    {
        $loan->load([
            'employee:id,employee_code,first_name,last_name',
            'employee.user.roles',
            'repayments' => fn ($q) => $q->orderBy('month')->orderBy('id'),
            'repayments.item:id,payroll_run_id',
            'repayments.item.run:id,month',
            'repayments.createdBy:id,name',
            'skips' => fn ($q) => $q->orderBy('month'),
            'skips.createdBy:id,name',
            'createdBy:id,name',
            'cancelledBy:id,name',
        ]);

        /** @var User $user */
        $user = $request->user();
        $latest = PayrollLock::latestLocked();
        $from = $latest === null ? null : LoanSchedule::addMonth($latest);
        $remaining = $loan->remaining();
        $row = self::row($loan, $from);
        $mayEdit = $loan->isActive() && PayrollAccess::mayEditSalary($user, $loan->employee);
        $hasRepayments = $loan->repayments->isNotEmpty();

        $scheduleFrom = max($loan->start_month, $from ?? $loan->start_month);
        $schedule = $loan->isActive() && $remaining > 0
            ? LoanSchedule::project($remaining, $loan->installment, $scheduleFrom, $loan->skips->pluck('month')->all())
            : [];

        return Inertia::render('payroll/loans/show', [
            'loan' => [
                ...$row,
                'note' => $loan->note,
                'created_by' => $loan->createdBy?->name,
                'created_on' => $loan->created_at?->format('Y-m-d'),
                'cancelled_by' => $loan->cancelledBy?->name,
                'cancelled_on' => $loan->cancelled_at?->format('Y-m-d'),
                'cancel_reason' => $loan->cancel_reason,
                'has_repayments' => $hasRepayments,
                // Amount, first month and repaid-before-go-live may change only before any repayment and while the
                // loan was never due in an approved month (SaveLoan).
                'core_editable' => ! $hasRepayments && ($latest === null || $loan->start_month > $latest),
            ],
            'repayments' => $loan->repayments->map(fn (LoanRepayment $r) => [
                'id' => $r->id,
                'month' => $r->month,
                'amount' => $r->amount,
                'source' => $r->payroll_item_id === null
                    ? 'Cash'
                    : 'Payroll '.($r->item?->run !== null ? PayrollRun::monthLabel($r->item->run->month) : PayrollRun::monthLabel($r->month)),
                'note' => $r->note,
                'by' => $r->createdBy?->name,
                'recorded_on' => $r->created_at?->format('Y-m-d'),
            ])->values()->all(),
            'skips' => $loan->skips->map(fn (LoanSkip $s) => [
                'id' => $s->id,
                'month' => $s->month,
                'reason' => $s->reason,
                'by' => $s->createdBy?->name,
                'locked' => $latest !== null && $s->month <= $latest,
            ])->values()->all(),
            'schedule' => $schedule,
            'months' => self::months($latest),
            'can' => [
                'edit' => $mayEdit,
                'cancel' => $mayEdit && $user->can('payroll.approve'),
            ],
        ]);
    }

    public function store(LoanRequest $request, SaveLoan $save): RedirectResponse
    {
        $employee = Employee::query()->with('user.roles')->findOrFail((int) $request->input('employee_id'));
        $loan = $save->create($request->user(), $employee, ['type' => (string) $request->input('type'), ...$request->figures()]);

        return redirect()->route('payroll.loans.show', $loan)->with('success', ucfirst($loan->type).' added.');
    }

    public function update(LoanRequest $request, Loan $loan, SaveLoan $save): RedirectResponse
    {
        $save->update($request->user(), $loan, $request->figures());

        return back()->with('success', $save->changed() ? ucfirst($loan->type).' updated.' : 'Nothing changed.');
    }

    public function skip(Request $request, Loan $loan, LoanActions $actions): RedirectResponse
    {
        $data = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'reason' => ['bail', 'required', 'string', 'min:3', 'max:255'],
        ], ['month.required' => 'Choose a month.', 'month.regex' => 'Choose a month.', 'reason.required' => 'Give a reason.']);

        $actions->skip($request->user(), $loan, $data['month'], $data['reason']);

        return back()->with('success', PayrollRun::monthLabel($data['month']).' skipped.');
    }

    public function unskip(Request $request, Loan $loan, string $month, LoanActions $actions): RedirectResponse
    {
        $actions->unskip($request->user(), $loan, $month);

        return back()->with('success', 'The skip of '.PayrollRun::monthLabel($month).' was removed.');
    }

    public function repay(Request $request, Loan $loan, LoanActions $actions): RedirectResponse
    {
        if (is_string($request->input('amount'))) {
            $request->merge(['amount' => str_replace(',', '', trim($request->input('amount')))]);
        }

        $data = $request->validate([
            'amount' => ['bail', 'required', 'integer', 'min:1', 'max:'.LoanRequest::MAX_RUPEES],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'note' => ['bail', 'required', 'string', 'min:3', 'max:255'],
        ], [
            'amount.integer' => 'Enter whole rupees.',
            'month.required' => 'Choose a month.',
            'month.regex' => 'Choose a month.',
            'note.required' => 'Say how it was repaid, for example "Cash, receipt 41".',
        ]);

        $actions->repay($request->user(), $loan, Money::rupees((int) $data['amount']), $data['month'], $data['note']);

        return back()->with('success', 'Repayment of '.Money::format(Money::rupees((int) $data['amount'])).' recorded.');
    }

    public function cancel(Request $request, Loan $loan, LoanActions $actions): RedirectResponse
    {
        $data = $request->validate(
            ['reason' => ['bail', 'required', 'string', 'min:5', 'max:255']],
            ['reason.required' => 'Give a reason.', 'reason.min' => 'The reason must be at least 5 characters.'],
        );

        $actions->cancel($request->user(), $loan, $data['reason']);

        return back()->with('success', ucfirst($loan->type).' cancelled.');
    }

    /**
     * One list row (money in paisa). Status: cancelled, cleared (active, nothing left) or active.
     *
     * @return array<string, mixed>
     */
    private static function row(Loan $loan, ?string $from): array
    {
        $remaining = $loan->remaining();
        $status = ! $loan->isActive() ? 'cancelled' : ($remaining === 0 ? 'cleared' : 'active');
        $scheduleFrom = max($loan->start_month, $from ?? $loan->start_month);

        return [
            'id' => $loan->id,
            'employee' => [
                'id' => $loan->employee->id,
                'name' => $loan->employee->full_name,
                'code' => $loan->employee->employee_code,
            ],
            'type' => $loan->type,
            'amount' => $loan->amount,
            'installment' => $loan->installment,
            'start_month' => $loan->start_month,
            'opening_repaid' => $loan->opening_repaid,
            'repaid' => $loan->amount - $remaining,
            'remaining' => $remaining,
            'next_month' => $status === 'active'
                ? LoanSchedule::nextMonth($remaining, $loan->installment, $scheduleFrom, $loan->skips->pluck('month')->all())
                : null,
            'status' => $status,
        ];
    }

    /**
     * The people $user may add a loan for (not resigned or terminated), for the dialog's picker.
     *
     * @return list<array{id: int, name: string, code: string}>
     */
    private static function editableEmployees(User $user): array
    {
        return array_values(Employee::query()
            ->with('user.roles')
            ->whereNotIn('status', ['resigned', 'terminated'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'status'])
            ->filter(fn (Employee $e) => PayrollAccess::mayEditSalary($user, $e))
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code])
            ->all());
    }

    /**
     * The months the dialogs offer: the first open one (after the latest approved month, else the current month) and
     * the latest approved one, plus the current month.
     *
     * @return array{current: string, first_open: string|null, latest_locked: string|null}
     */
    private static function months(?string $latest): array
    {
        $current = substr(app(AttendanceClock::class)->attendanceToday(), 0, 7);

        return [
            'current' => $current,
            'first_open' => $latest === null ? null : LoanSchedule::addMonth($latest),
            'latest_locked' => $latest,
        ];
    }
}
