<?php

namespace App\Http\Controllers\Exits;

use App\Actions\Exits\SubmitResignation;
use App\Actions\Exits\WithdrawResignation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Exits\SubmitResignationRequest;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Resignation: the signed-in person's OWN exit, for every role.
 *
 *   GET  /my/resignation                          the form, or the open exit (status, flow, clearance ticks, downloads)
 *   POST /my/resignation                          resign
 *   POST /my/resignation/{resignation}/withdraw   withdraw before the final approval
 *
 * The record is the account's own employee_id; nothing in the URL names a person, and an id that is not the person's own
 * is 403. The open exit's props are ExitView's, so an employee never receives a settlement key: the statement comes as
 * `statement_url` once the settlement is approved, the letter as `letter_url` once issued. Ended exits are listed below,
 * with the rejection note or the cancel reason.
 *
 * Props: employee {name, status, joining_date} | null, current (ExitView props) | null, ended [row], can_submit, why_not,
 * form {today, max_days, notice_days}.
 */
class MyResignationController extends Controller
{
    public function show(Request $request, AttendanceClock $clock): Response
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id);

        if ($employee === null) {
            return Inertia::render('my/resignation', [
                'employee' => null, 'current' => null, 'ended' => [], 'can_submit' => false, 'why_not' => SubmitResignation::NO_RECORD,
                'form' => $this->form($clock),
            ]);
        }

        $routing = new ExitRouting;
        $all = Resignation::query()
            ->where('employee_id', $employee->id)
            ->with(['employee.department:id,name', 'employee.designation:id,name', 'employee.manager:id,first_name,last_name'])
            ->orderByDesc('id')
            ->get();
        $routing->prime($all);

        $open = $all->first(fn (Resignation $r) => $r->isOpen());
        $active = in_array($employee->status, ['active', 'probation'], true);

        return Inertia::render('my/resignation', [
            'employee' => [
                'name' => $employee->full_name,
                'status' => $employee->status,
                'joining_date' => $employee->joining_date->format('Y-m-d'),
            ],
            'current' => $open === null ? null : ExitView::for($user, $open),
            'ended' => $all->reject(fn (Resignation $r) => $r->isOpen())->take(20)
                ->map(fn (Resignation $r) => ExitView::row($r, $routing))->values()->all(),
            'can_submit' => $open === null && $active,
            'why_not' => $open !== null ? SubmitResignation::ALREADY_OPEN : ($active ? null : SubmitResignation::NOT_ACTIVE),
            'form' => $this->form($clock),
        ]);
    }

    public function store(SubmitResignationRequest $request, SubmitResignation $action): RedirectResponse
    {
        $action->handle($request->user(), (string) $request->validated('last_working_day'), (string) $request->validated('reason'));

        return back()->with('success', 'Resignation submitted.');
    }

    public function withdraw(Request $request, int $resignation, WithdrawResignation $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $found = $user->employee_id === null ? null : Resignation::query()->whereKey($resignation)->where('employee_id', $user->employee_id)->first();

        abort_if($found === null, 403);

        $action->handle($user, $found);

        return back()->with('success', 'Resignation withdrawn.');
    }

    /** @return array{today: string, max_days: int, notice_days: int} */
    private function form(AttendanceClock $clock): array
    {
        return [
            'today' => $clock->attendanceToday(),
            'max_days' => (int) config('hrm.exit.max_days_ahead'),
            'notice_days' => (int) settings('payroll.notice_period_days'),
        ];
    }
}
