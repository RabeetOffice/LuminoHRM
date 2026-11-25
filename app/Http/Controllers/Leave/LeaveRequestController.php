<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\ApplyForLeave;
use App\Actions\Leave\LeaveDecisions;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveCancelRequest;
use App\Http\Requests\LeaveDecisionRequest;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\EmployeeFiles;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\LeaveRouting;
use App\Support\Leave\WaitingForMe;
use App\Support\ListQuery;
use App\Support\PayrollLock;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Leave > Requests and the request detail page; the decisions and HR's cancellation of approved leave.
 *
 *   GET  /leave/requests?tab=waiting|all&status=&type=&department=&from=&to=&search=
 *        leave.approve-all (everyone's) or leave.approve-team (own and direct reports'); others 403
 *   GET  /leave/requests/{leaveRequest}                      the requester or anyone LeaveRouting lets see it
 *   PUT  /leave/requests/{leaveRequest}/manager-decide       the routed manager (LeaveRouting)
 *   PUT  /leave/requests/{leaveRequest}/final-decide         leave.approve-all + LeaveRouting::mayDecideFinal
 *   POST /leave/requests/{leaveRequest}/cancel-approved      leave.approve-all + LeaveRouting::mayAdminister
 *
 * Applying, the preview, the attachment and cancelling an open request are LeaveApplicationController's (Task 5).
 *
 * A request outside the user's scope and one that does not exist are both 403 (ids cannot be probed). A stale or
 * refused decision comes back as a flash error, never as a 500 or a second write. The "Waiting for me" tab lists the
 * open requests waiting at the step this user decides (WaitingForMe: HR sees final-step requests only); routing is
 * primed once for the page and the cancel windows of approved rows come from one grouped query, so the list runs no
 * query per row. Counts per tab use the same filters as the rows.
 */
class LeaveRequestController extends Controller
{
    private const TABS = ['waiting', 'all'];

    public function __construct(
        private readonly LeaveRouting $routing,
        private readonly AttendanceClock $clock,
    ) {}

    public function index(Request $request, ApplyForLeave $apply): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['leave.approve-all', 'leave.approve-team']), 403);

        $tab = ListQuery::stringParam($request, 'tab');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'waiting';
        $filters = $this->filters($request);

        $filtered = function () use ($user, $filters): Builder {
            $query = $this->routing->scope(LeaveRequest::query(), $user);

            return $this->applyFilters($query, $filters);
        };

        // Waiting for me: the open requests in scope (and filters) waiting at the step this user decides (WaitingForMe;
        // the dashboard's Pending count uses the same rule).
        $waitingIds = WaitingForMe::ids($user, fn (Builder $q): Builder => $this->applyFilters($q, $filters), $this->routing);

        $paginator = $filtered()
            ->when($tab === 'waiting', fn (Builder $q) => $q->whereIn('id', $waitingIds))
            ->with(['employee', 'type:id,name,colour,is_paid'])
            ->orderBy($tab === 'waiting' ? 'from_date' : 'created_at', $tab === 'waiting' ? 'asc' : 'desc')
            ->orderBy('id', $tab === 'waiting' ? 'asc' : 'desc')
            ->paginate(ListQuery::perPage($request), ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        /** @var Collection<int, LeaveRequest> $items */
        $items = $paginator->getCollection();
        $this->routing->prime($items);
        $windows = $this->cancelWindows($items->where('status', 'approved')->pluck('id')->all());

        $canApplyOnBehalf = $user->can('leave.approve-all');

        return Inertia::render('leave/requests', [
            'tab' => $tab,
            'filters' => $filters,
            'requests' => [
                'data' => $items->map(fn (LeaveRequest $r) => $this->row($r, $user, $windows[$r->id] ?? null))->values()->all(),
                ...$this->pageMeta($paginator),
            ],
            'counts' => ['waiting' => count($waitingIds), 'all' => $filtered()->count()],
            'statuses' => (array) config('hrm.leave.statuses'),
            'types' => LeaveType::query()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'colour', 'is_paid', 'is_active'])
                ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'colour' => $t->colour, 'is_paid' => (bool) $t->is_paid, 'is_active' => (bool) $t->is_active])
                ->all(),
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->all(),
            'today' => $this->clock->attendanceToday(),
            'can' => ['apply_on_behalf' => $canApplyOnBehalf],
            // For "Apply on behalf": the people whose leave this user may administer, and the dialog's window.
            'on_behalf' => $canApplyOnBehalf ? [
                'employees' => $this->administrable($user),
                'window' => $apply->window(),
            ] : null,
        ]);
    }

    public function show(Request $request, LeaveBalances $balances, int $leaveRequest): Response
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        $found->load(['employee', 'type', 'appliedBy:id,name,employee_id', 'managerDecider:id,name', 'hrDecider:id,name', 'canceller:id,name']);
        $employee = $found->employee;
        $type = $found->type;
        $from = $found->from_date->format('Y-m-d');
        $to = $found->to_date->format('Y-m-d');
        $half = $found->is_half_day ? $found->half : null;

        $days = LeaveDay::query()->toBase()->where('leave_request_id', $found->id)->orderBy('date')->orderBy('half')
            ->get(['date', 'half', 'amount'])
            ->map(fn (object $d) => ['date' => substr((string) $d->date, 0, 10), 'half' => (string) $d->half, 'amount' => (float) $d->amount])
            ->all();
        $daysSource = $days !== [] ? 'ledger' : 'none';
        $planRefusal = null;

        if ($days === [] && $found->isOpen()) {
            $plan = LeaveDays::plan($employee, $from, $to, $half);
            $days = array_map(fn (array $d) => ['date' => $d['date'], 'half' => $d['half'], 'amount' => (float) $d['amount']], $plan->days);
            $daysSource = 'plan';
            $planRefusal = $plan->refusal;
        }

        $isRequester = $this->routing->isRequester($user, $found);
        $showBalance = ! $type->isUnpaid() && ($isRequester || $this->routing->isApprover($user, $found));

        $balance = null;
        if ($showBalance) {
            $before = [];
            foreach ((array) $found->balance_before as $year => $available) {
                $before[(int) $year] = (float) $available;
            }

            $years = array_keys($before);
            for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
                $years[] = $y;
            }
            $years = array_values(array_unique($years));
            sort($years);

            $balance = array_map(fn (int $y) => [
                'year' => $y,
                'before' => $before[$y] ?? null,
                'now' => $balances->line($employee, $type, $y)->toArray(),
            ], $years);
        }

        $needsAttention = [];
        if ($found->status === 'approved' && $days !== []) {
            $needsAttention = Attendance::query()->toBase()
                ->where('employee_id', $employee->id)
                ->whereIn('date', array_values(array_unique(array_column($days, 'date'))))
                ->where('is_manual', true)
                ->orderBy('date')
                ->pluck('date')
                ->map(fn ($d) => substr((string) $d, 0, 10))
                ->unique()->values()->all();
        }

        $stage = $this->routing->stage($found);
        $onBehalf = $found->appliedBy !== null && (int) $found->appliedBy->employee_id !== (int) $found->employee_id;
        $history = $this->history($found);
        $window = $found->status === 'approved' ? ($this->cancelWindows([$found->id])[$found->id] ?? null) : null;

        // Where "back" goes: the requests list for approvers, My Leaves for everyone else (they cannot open the list).
        $back = $user->canAny(['leave.approve-all', 'leave.approve-team'])
            ? ['url' => route('leave.requests.index', [], false), 'label' => 'Leave Requests']
            : ['url' => route('my.leaves', [], false), 'label' => 'My Leaves'];

        return Inertia::render('leave/request-show', [
            'back' => $back,
            'request' => [
                ...$this->row($found, $user, $window),
                'reason' => $found->reason,
                // Who applied, when it was someone other than the employee (HR on their behalf).
                'applied_by' => $onBehalf ? $found->appliedBy->name : null,
                'applied_time' => $found->created_at?->format('H:i'),
                'manager_decider' => $found->managerDecider?->name,
                'manager_decided_on' => $found->manager_decided_at?->format('Y-m-d'),
                'hr_decider' => $found->hrDecider?->name,
                'hr_decided_on' => $found->hr_decided_at?->format('Y-m-d'),
                'manager_skipped' => (bool) $found->manager_skipped,
                'decision_note' => $found->decision_note,
                // The one decision_note column holds the last note; each step's own note comes from the audit trail.
                'manager_note' => $this->stepNote($history, 'manager'),
                'hr_note' => $this->stepNote($history, 'final'),
                'canceller' => $found->canceller?->name,
                'cancelled_on' => $found->cancelled_at?->format('Y-m-d'),
                'cancel_reason' => $found->cancel_reason,
                'attachment_url' => $found->attachment_path !== null && Route::has('leave.requests.attachment')
                    ? route('leave.requests.attachment', ['leaveRequest' => $found->id], false) : null,
                'is_own' => $isRequester,
                'stage' => $stage,
            ],
            'days' => $days,
            'days_source' => $daysSource,
            'plan_refusal' => $planRefusal,
            'balance' => $balance,
            'needs_attention' => $needsAttention,
            'history' => array_map(fn (array $h): array => array_diff_key($h, ['step' => true]), $history),
            'today' => $this->clock->attendanceToday(),
        ]);
    }

    public function managerDecide(LeaveDecisionRequest $request, LeaveDecisions $decisions, int $leaveRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        return $this->attempt(fn () => $decisions->decideAsManager($user, $found, $request->approves(), $request->note()));
    }

    public function finalDecide(LeaveDecisionRequest $request, LeaveDecisions $decisions, int $leaveRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        return $this->attempt(fn () => $decisions->decideFinal($user, $found, $request->approves(), $request->note()));
    }

    public function cancelApproved(LeaveCancelRequest $request, LeaveDecisions $decisions, int $leaveRequest): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->routing->findVisible($user, $leaveRequest);
        abort_if($found === null, 403);

        // No date: the action picks max(first cancellable day, today) under its lock.
        return $this->attempt(fn () => $decisions->cancelApproved($user, $found, $request->fromDate(), $request->reason()));
    }

    /**
     * Runs a write. A stale or refused one (key "decision") comes back as a flash error; a field error (a bad
     * cancel-from date, a missing reason) goes back to the form.
     */
    private function attempt(callable $write): RedirectResponse
    {
        try {
            return back()->with('success', $write());
        } catch (ValidationException $e) {
            if (! array_key_exists('decision', $e->errors())) {
                throw $e;
            }

            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }
    }

    /**
     * @return array{status: string, type: int|null, department: int|null, from: string, to: string, search: string}
     */
    private function filters(Request $request): array
    {
        $status = ListQuery::stringParam($request, 'status');
        $date = function (string $key) use ($request): string {
            $value = ListQuery::stringParam($request, $key);
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

            return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : '';
        };
        $id = function (string $key) use ($request): ?int {
            $value = ListQuery::stringParam($request, $key);

            return ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
        };

        return [
            'status' => in_array($status, (array) config('hrm.leave.statuses'), true) ? $status : '',
            'type' => $id('type'),
            'department' => $id('department'),
            'from' => $date('from'),
            'to' => $date('to'),
            'search' => mb_substr(ListQuery::stringParam($request, 'search'), 0, 100),
        ];
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @param  array{status: string, type: int|null, department: int|null, from: string, to: string, search: string}  $f
     * @return Builder<LeaveRequest>
     */
    private function applyFilters(Builder $query, array $f): Builder
    {
        return $query
            ->when($f['status'] !== '', fn (Builder $q) => $q->where('status', $f['status']))
            ->when($f['type'] !== null, fn (Builder $q) => $q->where('leave_type_id', $f['type']))
            ->when($f['department'] !== null, fn (Builder $q) => $q->whereIn('employee_id', Employee::query()->select('id')->where('department_id', $f['department'])))
            // Requests overlapping the range ("<" the next day: SQLite may hold a date as "Y-m-d 00:00:00").
            ->when($f['from'] !== '', fn (Builder $q) => $q->where('to_date', '>=', $f['from']))
            ->when($f['to'] !== '', fn (Builder $q) => $q->where('from_date', '<', (new DateTimeImmutable($f['to']))->modify('+1 day')->format('Y-m-d')))
            ->when($f['search'] !== '', fn (Builder $q) => $q->whereIn('employee_id', Employee::query()->select('id')->search($f['search'])));
    }

    /**
     * @param  array{min: string|null, max: string}|null  $window  the request's cancel window (cancelWindows())
     * @return array<string, mixed>
     */
    private function row(LeaveRequest $r, User $user, ?array $window = null): array
    {
        $stage = $this->routing->stage($r);
        $manager = $stage === LeaveRouting::STAGE_MANAGER ? $this->routing->managerApprover($r->employee) : null;
        $canCancelApproved = LeaveDecisions::mayCancelApproved($this->routing, $user, $r, $window['min'] ?? null);
        $cancelRange = $canCancelApproved && $window !== null ? $this->cancelRange($window) : null;

        return [
            'id' => $r->id,
            'employee' => [
                'id' => $r->employee->id,
                'name' => $r->employee->full_name,
                'code' => $r->employee->employee_code,
                'photo_url' => EmployeeFiles::photoUrl($r->employee),
            ],
            'type' => ['id' => $r->type->id, 'name' => $r->type->name, 'colour' => $r->type->colour, 'is_paid' => (bool) $r->type->is_paid],
            'from' => $r->from_date->format('Y-m-d'),
            'to' => $r->to_date->format('Y-m-d'),
            'half' => $r->is_half_day ? $r->half : null,
            'days' => (float) $r->days,
            'applied_on' => $r->created_at?->format('Y-m-d'),
            'status' => $r->status,
            'stage' => $stage,
            'manager_first_name' => $manager !== null ? explode(' ', trim($manager->name))[0] : null,
            'manager_name' => $manager?->name,
            // Whether the manager's approval is the final one (HR step off; never for an Admin / HR requester).
            'manager_approval_final' => $stage === LeaveRouting::STAGE_MANAGER && $this->routing->managerApprovalIsFinal($r),
            'partly_cancelled' => $r->isPartlyCancelled(),
            'cancelled_from' => $r->cancelled_from?->format('Y-m-d'),
            'can' => [
                'decide' => $this->routing->decidableStage($user, $r),
                'skips_manager' => $this->routing->skipsManager($user, $r),
                'cancel_open' => $this->routing->canCancelOpen($user, $r),
                'cancel_approved' => $canCancelApproved,
            ],
            'cancel_range' => $cancelRange,
        ];
    }

    /**
     * The days HR may cancel from: earliest to the last leave day, and the default (today, kept inside that range).
     *
     * @param  array{min: string|null, max: string}  $window
     * @return array{min: string, max: string, default: string}|null
     */
    private function cancelRange(array $window): ?array
    {
        if ($window['min'] === null) {
            return null;
        }

        $today = $this->clock->attendanceToday();

        return ['min' => $window['min'], 'max' => $window['max'], 'default' => max($window['min'], min($today, $window['max']))];
    }

    /**
     * Each request's cancel window from ONE grouped query over these requests' leave days (by request and month): the
     * first removable day (the LeaveRouting::earliestCancellableDate rule: the first day after the last day in a locked
     * payroll month, null when none is left) and the last leave day. Requests without leave days are left out.
     *
     * @param  list<int>|array<int, int>  $ids
     * @return array<int, array{min: string|null, max: string}>
     */
    private function cancelWindows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = LeaveDay::query()->toBase()
            ->whereIn('leave_request_id', array_values($ids))
            ->selectRaw('leave_request_id, substr(date, 1, 7) as month, min(date) as first_day, max(date) as last_day')
            ->groupByRaw('leave_request_id, substr(date, 1, 7)')
            ->orderBy('leave_request_id')
            ->orderBy('month')
            ->get();

        $windows = [];

        foreach ($rows as $row) {
            $id = (int) $row->leave_request_id;
            $window = $windows[$id] ?? ['min' => null, 'max' => ''];

            if (PayrollLock::isLocked((string) $row->month)) {
                $window['min'] = null;
            } else {
                $window['min'] ??= substr((string) $row->first_day, 0, 10);
            }

            $window['max'] = substr((string) $row->last_day, 0, 10);
            $windows[$id] = $window;
        }

        return $windows;
    }

    /**
     * The note of the last decision at this step ("manager" or "final") in the request's history, or null.
     *
     * @param  list<array{step: string|null, note: string|null}>  $history
     */
    private function stepNote(array $history, string $step): ?string
    {
        $note = null;

        foreach ($history as $entry) {
            if ($entry['step'] === $step) {
                $note = $entry['note'];
            }
        }

        return $note;
    }

    /**
     * The request's audit entries, oldest first: what, who, when, note.
     *
     * @return list<array{description: string, by: string, on: string|null, time: string|null, note: string|null, step: string|null}>
     */
    private function history(LeaveRequest $r): array
    {
        return array_values(Activity::query()
            ->where('log_name', 'leave')
            ->where('properties->request', $r->id)
            ->with('causer')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (Activity $a): array {
                $note = $a->properties['note'] ?? ($a->properties['reason'] ?? null);
                $causer = $a->causer;

                return [
                    'description' => $a->description,
                    'by' => $causer instanceof User ? $causer->name : 'System',
                    'on' => $a->created_at?->format('Y-m-d'),
                    'time' => $a->created_at?->format('H:i'),
                    'note' => is_string($note) ? $note : null,
                    'step' => is_string($a->properties['step'] ?? null) ? $a->properties['step'] : null,
                ];
            })
            ->all());
    }

    /**
     * Working people whose leave this user may administer (on-behalf applications), never the user's own record (they
     * apply for themselves on My Leaves).
     *
     * @return list<array{id: int, name: string, code: string}>
     */
    private function administrable(User $user): array
    {
        return array_values(Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->when($user->employee_id !== null, fn (Builder $q) => $q->whereKeyNot($user->employee_id))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'employee_code'])
            ->filter(fn (Employee $e) => $this->routing->mayAdminister($user, $e))
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code])
            ->all());
    }

    /**
     * @param  LengthAwarePaginator<int, LeaveRequest>  $paginator
     * @return array<string, int|null>
     */
    private function pageMeta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
