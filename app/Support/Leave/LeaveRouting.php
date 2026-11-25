<?php

namespace App\Support\Leave;

use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\PayrollLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The one place that decides who approves which step of a leave request, who sees it, who cancels it and who
 * administers an employee's leave (on-behalf apply, HR cancel, balance edits, opening import rows). Every controller
 * and action calls it; no screen re-implements a rule. Mirrors App\Support\Attendance\CorrectionRouting.
 *
 * Steps: the reporting manager first, then the final step (HR or Admin, leave.approve-all). The manager step is
 * skipped (the request goes straight to the final step) when the employee has no manager, the manager is the employee,
 * the manager has no login or a disabled one, the manager's login is the requester's own, the manager lacks
 * leave.approve-team (an Employee-role person with reports), or the manager holds leave.approve-all (an HR/Admin
 * manager decides once, as the final approver). When settings('leave.hr_step_enabled') is off the routing is the same;
 * the decision action (Task 6) makes a manager approval final.
 *
 * The final decision: leave.approve-all, never the user who approved as manager, and
 *   - a request of a person whose login holds admin: another Admin, or the requester themself only while they are the
 *     only active Admin (audited by the action);
 *   - a request of a person whose login holds hr: an Admin who is not the requester;
 *   - anyone else's request: anyone with leave.approve-all who is not the requester.
 * A user who may decide the final step may also decide a request still waiting for the manager (deciding then sets
 * manager_skipped), so a request never gets stuck behind a manager who does not answer.
 *
 * The step is worked out when needed, never stored: a request waiting for a manager whose login is later disabled moves
 * to the final step by itself, and a new manager sees (and decides) the requests of their new report; a manager's
 * approval already given stands when the manager changes afterwards.
 */
class LeaveRouting
{
    public const STAGE_MANAGER = 'manager';

    public const STAGE_FINAL = 'final';

    /** @var array<int, User|null> manager employee id => their login */
    private array $logins = [];

    /** @var array<int, 'admin'|'hr'>|null employee id => the privileged role their login holds (admin wins), loaded once */
    private ?array $privileged = null;

    private ?int $activeAdmins = null;

    /** The login that decides the manager step for this employee, or null when that step is skipped. */
    public function managerApprover(Employee $employee): ?User
    {
        $managerId = $employee->manager_id;

        if ($managerId === null || (int) $managerId === (int) $employee->id) {
            return null;
        }

        $managerId = (int) $managerId;
        $user = array_key_exists($managerId, $this->logins)
            ? $this->logins[$managerId]
            : ($this->logins[$managerId] = User::query()->where('employee_id', $managerId)->first());

        if ($user === null || ! $user->is_active || (int) $user->employee_id === (int) $employee->id) {
            return null;
        }

        if (! $user->can('leave.approve-team') || $user->can('leave.approve-all')) {
            return null;
        }

        return $user;
    }

    /** Whether the final (HR) step follows a manager approval: settings('leave.hr_step_enabled'). */
    public function hrStepEnabled(): bool
    {
        return (bool) settings('leave.hr_step_enabled', true);
    }

    /** The step a request is waiting at: "manager", "final", or null once it is decided or cancelled. */
    public function stage(LeaveRequest $request): ?string
    {
        return match ($request->status) {
            'pending' => $this->managerApprover($request->employee) !== null ? self::STAGE_MANAGER : self::STAGE_FINAL,
            'manager_approved' => self::STAGE_FINAL,
            default => null,
        };
    }

    /** True when the user's own employee record is the request's. */
    public function isRequester(User $user, LeaveRequest $request): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $request->employee_id;
    }

    /** Whether the user may give the final decision on this request, whatever its state (see the class comment). */
    public function mayDecideFinal(User $user, LeaveRequest $request): bool
    {
        if (! $user->can('leave.approve-all')) {
            return false;
        }

        if ($request->manager_decided_by !== null && (int) $request->manager_decided_by === (int) $user->id) {
            return false;
        }

        $isRequester = $this->isRequester($user, $request);

        return match ($this->privilegedRole((int) $request->employee_id)) {
            'admin' => $user->hasRole('admin') && (! $isRequester || $this->isSoleActiveAdmin($user)),
            'hr' => $user->hasRole('admin') && ! $isRequester,
            default => ! $isRequester,
        };
    }

    /**
     * The step this user may decide now ("manager" or "final"), or null. A user who may decide the final step gets
     * "final" while the request still waits for the manager (deciding then skips the manager).
     */
    public function decidableStage(User $user, LeaveRequest $request): ?string
    {
        $stage = $this->stage($request);

        if ($stage === null) {
            return null;
        }

        if ($stage === self::STAGE_MANAGER && ($this->managerApprover($request->employee)?->is($user) ?? false)) {
            return self::STAGE_MANAGER;
        }

        return $this->mayDecideFinal($user, $request) ? self::STAGE_FINAL : null;
    }

    /** True when this user deciding now would skip the manager step. */
    public function skipsManager(User $user, LeaveRequest $request): bool
    {
        return $this->stage($request) === self::STAGE_MANAGER && $this->decidableStage($user, $request) === self::STAGE_FINAL;
    }

    /**
     * Whether the user is one of this request's deciders at all (the routed manager now, the manager who decided, or
     * someone who may decide the final step), whatever its state. Anyone else gets 403 before any "already decided"
     * message.
     */
    public function isApprover(User $user, LeaveRequest $request): bool
    {
        if ($request->manager_decided_by !== null && (int) $request->manager_decided_by === (int) $user->id) {
            return true;
        }

        if (! $this->isRequester($user, $request) && ($this->managerApprover($request->employee)?->is($user) ?? false)) {
            return true;
        }

        return $this->mayDecideFinal($user, $request);
    }

    /**
     * Whether the user may act on this employee's leave as HR (apply on their behalf, cancel approved leave, edit
     * balances, import opening rows): leave.approve-all; never their own record unless Admin; a person whose login is
     * Admin or HR only by an Admin (the EmployeeRoles::mayTouchAccount rule).
     */
    public function mayAdminister(User $user, Employee $employee): bool
    {
        if (! $user->can('leave.approve-all')) {
            return false;
        }

        if ($user->hasRole('admin')) {
            return true;
        }

        if ($user->employee_id !== null && (int) $user->employee_id === (int) $employee->id) {
            return false;
        }

        return $this->privilegedRole((int) $employee->id) === null;
    }

    /** An open request may be cancelled by its requester or by someone who may administer the employee's leave. */
    public function canCancelOpen(User $user, LeaveRequest $request): bool
    {
        return $request->isOpen()
            && ($this->isRequester($user, $request) || $this->mayAdminister($user, $request->employee));
    }

    /**
     * Approved leave is cancelled by HR / Admin only (never the requester), and only while it still has leave days that
     * may be removed (on or after earliestCancellableDate()).
     */
    public function canCancelApproved(User $user, LeaveRequest $request): bool
    {
        return $request->status === 'approved'
            && ! $this->isRequester($user, $request)
            && $this->mayAdminister($user, $request->employee)
            && $this->earliestCancellableDate($request) !== null;
    }

    /**
     * The first of the request's leave days that may still be removed ("Y-m-d"): the first day after the last day that
     * falls in a locked payroll month (cancelling from a date removes every day from it on, so nothing before a locked
     * day can go). Null when the request has no removable leave day.
     */
    public function earliestCancellableDate(LeaveRequest $request): ?string
    {
        $dates = LeaveDay::query()->toBase()
            ->where('leave_request_id', $request->id)
            ->orderBy('date')
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->unique()
            ->values()
            ->all();

        $earliest = null;

        foreach ($dates as $date) {
            if (PayrollLock::isLocked(substr($date, 0, 7))) {
                $earliest = null;

                continue;
            }

            $earliest ??= $date;
        }

        return $earliest;
    }

    /**
     * The requests this user may see: everyone's with leave.approve-all; with leave.approve-team those of the employees
     * EmployeeAccess lets them see (own and direct reports); otherwise only their own.
     *
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('leave.approve-all')) {
            return $query;
        }

        if ($user->can('leave.approve-team')) {
            return $query->whereIn('employee_id', (new EmployeeAccess($user))->scope(Employee::query())->select('id'));
        }

        if ($user->employee_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('employee_id', $user->employee_id);
    }

    /** The request with this id when the user may see it, else null (a missing id and one out of scope look alike). */
    public function findVisible(User $user, int $id): ?LeaveRequest
    {
        return $this->scope(LeaveRequest::query()->whereKey($id), $user)->first();
    }

    public function canSee(User $user, LeaveRequest $request): bool
    {
        return $this->scope(LeaveRequest::query()->whereKey($request->id), $user)->exists();
    }

    /**
     * Active logins that may give the final decision on this employee's requests, never the employee's own login (for
     * mail: nobody is mailed about a request they cannot decide).
     *
     * @return Collection<int, User>
     */
    public function finalApprovers(Employee $employee, ?User $except = null): Collection
    {
        $probe = (new LeaveRequest)->forceFill(['employee_id' => $employee->id, 'status' => 'pending']);
        $probe->setRelation('employee', $employee);

        return User::permission('leave.approve-all')
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employee->id))
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => $this->mayDecideFinal($u, $probe))
            ->values();
    }

    /** The privileged role this employee's login holds ("admin" wins over "hr"), or null (one query per instance). */
    private function privilegedRole(int $employeeId): ?string
    {
        if ($this->privileged === null) {
            $this->privileged = [];

            foreach (['hr', 'admin'] as $role) { // admin second, so it overwrites hr for a login holding both
                foreach (User::role($role)->whereNotNull('employee_id')->pluck('employee_id') as $id) {
                    $this->privileged[(int) $id] = $role;
                }
            }
        }

        return $this->privileged[$employeeId] ?? null;
    }

    /** Whether $user is an active Admin and no other active Admin exists. */
    private function isSoleActiveAdmin(User $user): bool
    {
        $this->activeAdmins ??= User::role('admin')->where('is_active', true)->count();

        return $user->is_active && $user->hasRole('admin') && $this->activeAdmins === 1;
    }
}
