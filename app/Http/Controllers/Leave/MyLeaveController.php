<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\ApplyForLeave;
use App\Http\Controllers\Controller;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveRouting;
use App\Support\ListQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Leaves: the signed-in person's OWN balances and requests, for every role.
 *
 *   GET /my/leaves?year=YYYY&page=N
 *
 * The record is the account's own employee_id; nothing in the URL names a person. A user without an employee record
 * gets the page with employee = null (a friendly empty state). Balances are LeaveBalances::lines for the chosen year
 * (always ->toArray(): an unpaid line is unlimited with nulls); requests are the person's own, newest first, 20 per
 * page, whatever the year. Dates are "Y-m-d" strings and server-made labels.
 *
 * Props: employee {name} | null, year, years, balances, types (active, for the apply dialog), requests (paginated),
 * window {min, max}, today.
 */
class MyLeaveController extends Controller
{
    private const PER_PAGE = 20;

    public function show(Request $request, AttendanceClock $clock, LeaveBalances $balances, LeaveRouting $routing, ApplyForLeave $apply): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $today = $clock->attendanceToday();
        $current = (int) substr($today, 0, 4);

        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);
        $first = $employee !== null ? max((int) $employee->joining_date->format('Y'), $current - 4) : $current;
        $years = range(min($first, $current), $current + 1);

        $asked = $request->query('year');
        $year = is_string($asked) && preg_match('/^\d{4}$/', $asked) === 1 && in_array((int) $asked, $years, true)
            ? (int) $asked
            : $current;

        $types = LeaveType::query()->active()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'colour', 'is_paid'])
            ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'colour' => $t->colour, 'is_paid' => (bool) $t->is_paid])
            ->values()->all();

        $base = [
            'year' => $year,
            'years' => $years,
            'types' => $types,
            'window' => $apply->window(),
            'today' => $today,
        ];

        if ($employee === null) {
            return Inertia::render('my/leaves', [
                ...$base,
                'employee' => null,
                'balances' => [],
                'requests' => ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => self::PER_PAGE, 'total' => 0, 'from' => null, 'to' => null],
            ]);
        }

        $paginator = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->with(['type:id,name,colour'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $items = $paginator->getCollection();
        $items->each(fn (LeaveRequest $r) => $r->setRelation('employee', $employee));
        $routing->prime($items);

        return Inertia::render('my/leaves', [
            ...$base,
            'employee' => ['name' => $employee->full_name],
            'balances' => array_map(fn (BalanceLine $line) => $line->toArray(), $balances->lines($employee, $year)),
            'requests' => [
                'data' => $items->map(fn (LeaveRequest $r) => $this->row($r, $user, $employee, $routing))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function row(LeaveRequest $r, User $user, Employee $employee, LeaveRouting $routing): array
    {
        $stage = $routing->stage($r);
        $from = $r->from_date->format('Y-m-d');
        $to = $r->to_date->format('Y-m-d');

        return [
            'id' => $r->id,
            'type' => $r->type->name,
            'colour' => $r->type->colour,
            'from' => $from,
            'to' => $to,
            'half' => $r->half,
            'dates' => LeaveRequestSubmitted::dates($from, $to, $r->half),
            'days' => (float) $r->days,
            'reason' => $r->reason,
            'status' => $r->status,
            'stage' => $stage,
            // The manager's first name while the request waits for them (never more about anyone else).
            'waiting_for' => $stage === LeaveRouting::STAGE_MANAGER ? $this->managerFirstName($employee) : null,
            'decision_note' => in_array($r->status, ['rejected', 'approved'], true) ? $r->decision_note : null,
            'can_cancel' => $routing->canCancelOpen($user, $r),
            'has_attachment' => ApplyForLeave::isAttachmentPath($r->employee_id, $r->attachment_path),
            'partly_cancelled' => $r->isPartlyCancelled()
                ? 'Cancelled from '.ApplyForLeave::dateLabel($r->cancelled_from->format('Y-m-d'))
                : null,
        ];
    }

    /** @var array<int, string|null> manager id => first name (one query per page) */
    private array $managerNames = [];

    private function managerFirstName(Employee $employee): ?string
    {
        if ($employee->manager_id === null) {
            return null;
        }

        $id = (int) $employee->manager_id;

        return array_key_exists($id, $this->managerNames)
            ? $this->managerNames[$id]
            : ($this->managerNames[$id] = Employee::query()->whereKey($id)->value('first_name'));
    }
}
