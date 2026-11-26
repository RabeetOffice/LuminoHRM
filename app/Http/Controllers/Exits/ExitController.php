<?php

namespace App\Http\Controllers\Exits;

use App\Actions\Exits\CancelExit;
use App\Actions\Exits\ChangeExitLastDay;
use App\Actions\Exits\DecideExit;
use App\Actions\Exits\ExitGuards;
use App\Actions\Exits\RecordExit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exits\CancelExitRequest;
use App\Http\Requests\Exits\ChangeLastDayRequest;
use App\Http\Requests\Exits\DecideExitRequest;
use App\Http\Requests\Exits\RecordExitRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\EmployeeAccess;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use App\Support\Exits\ExitsWaiting;
use App\Support\Exits\ExitView;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Exit > Resignations & Clearance: the list, the exit page and the exit workflow's writes (record, decide, change the last
 * working day, cancel). The list and the page are for exit.clearance (HR, Admin) and exit.approve-team (managers, their
 * reports only); an id outside ExitRouting::scope is 403, and each action checks the actor again under its locks
 * (AuthorizationException -> 403). Refusals come back as validation errors keyed `exit` (the whole action) or by field.
 * An employee's own exit is on My Resignation, not here.
 */
class ExitController extends Controller
{
    /** Rows per page. */
    public const PER_PAGE = 25;

    /** Employees offered in the record dialog's picker. */
    public const OPTIONS = 20;

    private const TABS = ['waiting', 'open', 'all', 'ended'];

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['exit.clearance', 'exit.approve-team']), 403);

        $routing = new ExitRouting;
        $money = $user->can('exit.clearance') && $user->can('payroll.view');
        $tab = $request->query('tab');
        $tab = is_string($tab) && in_array($tab, self::TABS, true) ? $tab : 'waiting';
        $search = ListQuery::stringParam($request, 'search');
        $type = $request->query('type');
        $type = is_string($type) && in_array($type, (array) config('hrm.exit.types'), true) ? $type : '';
        $department = $request->query('department');
        $department = is_string($department) && ctype_digit($department) && strlen($department) < 10 ? (int) $department : null;

        $waiting = ExitsWaiting::ids($user);
        $open = ExitStates::open();

        $query = $routing->scope(Resignation::query(), $user)
            ->with(['employee.department:id,name', 'employee.designation:id,name', 'employee.manager:id,first_name,last_name'])
            ->when($tab === 'waiting', fn (Builder $q) => $q->whereKey($waiting))
            ->when($tab === 'open', fn (Builder $q) => $q->whereIn('status', $open))
            ->when($tab === 'ended', fn (Builder $q) => $q->whereNotIn('status', $open))
            ->when($type !== '', fn (Builder $q) => $q->where('type', $type))
            ->when($department !== null, fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->where('department_id', $department)))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->search($search)))
            ->orderBy('last_working_day')
            ->orderByDesc('id');

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $rows = $paginator->getCollection();
        $routing->prime($rows);
        $statuses = $money ? ExitView::settlementStatuses($rows) : [];

        $byStatus = $routing->scope(Resignation::query(), $user)->toBase()
            ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $openCount = (int) collect($open)->sum(fn (string $s) => (int) ($byStatus[$s] ?? 0));
        $total = (int) $byStatus->sum();

        return Inertia::render('exit/index', [
            'exits' => [
                'data' => $rows->map(function (Resignation $r) use ($routing, $money, $statuses, $waiting) {
                    $row = ExitView::row($r, $routing, $money, $statuses[$r->id] ?? null);
                    $row['waiting_for_me'] = in_array((int) $r->id, $waiting, true);

                    return $row;
                })->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => ['waiting' => count($waiting), 'open' => $openCount, 'all' => $total, 'ended' => $total - $openCount],
            'filters' => ['tab' => $tab, 'search' => $search, 'type' => $type, 'department' => $department],
            'options' => [
                'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->all(),
                'types' => array_values((array) config('hrm.exit.types')),
            ],
            'today' => app(AttendanceClock::class)->attendanceToday(),
            'can' => ['record' => $user->can('exit.clearance'), 'settlements' => $money],
        ]);
    }

    /**
     * The record dialog's people: active and probation employees with no open exit whom the user may record for, found by
     * `q` (name or code) a few at a time. Never oneself; an Admin or HR person only for an Admin.
     */
    public function employeeOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('exit.clearance'), 403);

        $routing = new ExitRouting;
        $access = new EmployeeAccess($user);
        $term = ListQuery::stringParam($request, 'q');

        $people = $access->scope(Employee::query())
            ->whereIn('status', ['active', 'probation'])
            ->whereDoesntHave('openResignation')
            ->search($term)
            ->with('department:id,name')
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->limit(self::OPTIONS * 3)
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'department_id', 'joining_date']);

        return response()->json([
            'employees' => $people
                ->filter(fn (Employee $e) => $routing->mayRecordFor($user, $e))
                ->take(self::OPTIONS)
                ->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'code' => $e->employee_code,
                    'name' => $e->full_name,
                    'department' => $e->department?->name,
                    'joining_date' => $e->joining_date?->format('Y-m-d'),
                ])->values()->all(),
        ]);
    }

    public function store(RecordExitRequest $request, RecordExit $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can('exit.clearance'), 403);

        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));

        abort_unless((new EmployeeAccess($user))->canView($employee), 403);

        $resignation = $action->handle(
            $user,
            $employee,
            (string) $request->validated('type'),
            (string) $request->validated('last_working_day'),
            (string) $request->validated('reason'),
            (string) ($request->validated('submitted_on') ?? ExitGuards::today()),
            $request->boolean('approve_now'),
        );

        return $this->done(
            redirect()->route('exit.show', $resignation->id),
            $resignation->status === ExitStates::APPROVED ? "Exit recorded and approved for {$employee->full_name}." : "Exit recorded for {$employee->full_name}.",
            $action->notices(),
        );
    }

    /** The exit page. The props come from ExitView only; an exit the viewer may not open is 403. */
    public function show(Request $request, int $resignation): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['exit.clearance', 'exit.approve-team']), 403);

        $found = $this->visible($request, $resignation);

        return Inertia::render('exit/show', ExitView::for($user, $found) + [
            'today' => app(AttendanceClock::class)->attendanceToday(),
        ]);
    }

    public function decide(DecideExitRequest $request, int $resignation, DecideExit $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $this->visible($request, $resignation);

        if ($request->validated('decision') === 'reject') {
            $action->reject($user, $found, (string) $request->validated('note'));

            return back()->with('success', 'Exit rejected.');
        }

        $action->approve($user, $found);

        return $this->done(back(), 'Exit approved.', $action->notices());
    }

    public function lastDay(ChangeLastDayRequest $request, int $resignation, ChangeExitLastDay $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $resignation), (string) $request->validated('last_working_day'));

        return $this->done(back(), 'Last working day changed.', $action->notices());
    }

    public function cancel(CancelExitRequest $request, int $resignation, CancelExit $action): RedirectResponse
    {
        $action->handle($request->user(), $this->visible($request, $resignation), (string) $request->validated('reason'));

        return $this->done(back(), 'Exit cancelled.', $action->notices());
    }

    /**
     * @param  list<string>  $notices
     */
    private function done(RedirectResponse $redirect, string $message, array $notices): RedirectResponse
    {
        $redirect->with('success', $message);

        return $notices === [] ? $redirect : $redirect->with('info', implode(' ', $notices));
    }

    private function visible(Request $request, int $id): Resignation
    {
        $found = (new ExitRouting)->findVisible($request->user(), $id);

        abort_if($found === null, 403);

        return $found;
    }
}
