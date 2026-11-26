<?php

namespace App\Support\Exits;

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides who approves which step of an exit, who sees it and who administers it (record, change the
 * last working day, cancel, clearance, settlement drafts). Every controller and action calls it; no screen re-implements
 * a rule. Mirrors App\Support\Leave\LeaveRouting.
 *
 * Steps: the reporting manager first (permission exit.approve-team), then the final step (HR or Admin, exit.clearance).
 * The manager step is skipped (the exit starts at the final step) when HR or Admin recorded it, it is a termination, the
 * person has no manager, the manager is the person, the manager has no login or a disabled one, the manager lacks
 * exit.approve-team, or the manager holds exit.clearance (an HR / Admin manager decides once, as the final approver).
 *
 * The final decision: exit.clearance, never the user who approved as manager, and
 *   - an exit of a person whose login holds admin: another Admin, or the person themself only while they are the only
 *     active Admin (audited by the action as own);
 *   - an exit of a person whose login holds hr: an Admin who is not that person;
 *   - anyone else's exit: anyone with exit.clearance who is not that person.
 * A user who may decide the final step may also decide an exit still waiting for the manager (the manager step is then
 * marked skipped), so an exit never gets stuck behind a manager who does not answer.
 *
 * Administering (mayManage, mayRecordFor): exit.clearance; never one's own exit; an Admin or HR person's only by an Admin.
 *
 * The step is worked out when needed, never stored. Using it: one instance per request / action (it caches manager
 * logins, the privileged map and the Admin count); never bind it as a singleton. findVisible() returns null both for an
 * id that does not exist and for one out of the user's scope: controllers abort(403) on null.
 */
class ExitRouting
{
    public const STAGE_MANAGER = 'manager';

    public const STAGE_FINAL = 'final';

    public const OWN_MESSAGE = 'You cannot decide your own exit.';

    public const PRIVILEGED_MESSAGE = 'Only an Admin can decide the exit of an Admin or HR person.';

    public const NOT_WAITING_MESSAGE = 'This exit is not waiting for your decision.';

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
            : ($this->logins[$managerId] = User::query()->with(['roles', 'permissions'])->where('employee_id', $managerId)->first());

        if ($user === null || ! $user->is_active || (int) $user->employee_id === (int) $employee->id) {
            return null;
        }

        if (! $user->can('exit.approve-team') || $user->can('exit.clearance')) {
            return null;
        }

        return $user;
    }

    /**
     * Loads, in one query, the logins (with roles and permissions) of the managers of these exits' employees into this
     * instance's cache, so stage() / decidableStage() over a list run no query per row. The exits should have their
     * employee eager-loaded (Resignation::with('employee')).
     *
     * @param  iterable<Resignation>  $resignations
     */
    public function prime(iterable $resignations): void
    {
        $wanted = [];

        foreach ($resignations as $resignation) {
            $managerId = $resignation->employee->manager_id;

            if ($managerId !== null && ! array_key_exists((int) $managerId, $this->logins)) {
                $wanted[(int) $managerId] = true;
            }
        }

        if ($wanted === []) {
            return;
        }

        $found = User::query()->with(['roles', 'permissions'])
            ->whereIn('employee_id', array_keys($wanted))
            ->get()
            ->keyBy(fn (User $u) => (int) $u->employee_id);

        foreach (array_keys($wanted) as $managerId) {
            $this->logins[$managerId] = $found->get($managerId);
        }
    }

    /**
     * Whether the manager step is skipped for this exit: HR or Admin recorded it (manager_skipped already set), it is a
     * termination, or the person has no usable manager. Used when an exit is created (a new Resignation in hand) and
     * for display; the stored manager_skipped flag is the record of what happened.
     */
    public function skipsManager(Resignation $resignation): bool
    {
        return $resignation->manager_skipped
            || $resignation->type === 'termination'
            || $this->managerApprover($resignation->employee) === null;
    }

    /** The step an exit is waiting at: "manager", "final", or null once it is decided, withdrawn or cancelled. */
    public function stage(Resignation $resignation): ?string
    {
        return match ($resignation->status) {
            ExitStates::PENDING => $this->managerApprover($resignation->employee) !== null ? self::STAGE_MANAGER : self::STAGE_FINAL,
            ExitStates::MANAGER_APPROVED => self::STAGE_FINAL,
            default => null,
        };
    }

    /** True when the user's own employee record is the exit's. */
    public function isOwn(User $user, Resignation $resignation): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $resignation->employee_id;
    }

    /** Whether the user may decide the manager step of this exit now (they are the routed manager, it is waiting there). */
    public function mayDecideManager(User $user, Resignation $resignation): bool
    {
        return $this->stage($resignation) === self::STAGE_MANAGER
            && ! $this->isOwn($user, $resignation)
            && ($this->managerApprover($resignation->employee)?->is($user) ?? false);
    }

    /** Whether the user may give the final decision on this exit, whatever its state (see the class comment). */
    public function mayDecideFinal(User $user, Resignation $resignation): bool
    {
        return $this->finalRefusal($user, $resignation) === null;
    }

    /** Why the user may not give the final decision (the exact refusal text), or null when they may. */
    public function finalRefusal(User $user, Resignation $resignation): ?string
    {
        if (! $user->can('exit.clearance')) {
            return self::NOT_WAITING_MESSAGE;
        }

        if ($resignation->manager_decided_by !== null && (int) $resignation->manager_decided_by === (int) $user->id) {
            return self::NOT_WAITING_MESSAGE;
        }

        $own = $this->isOwn($user, $resignation);

        return match ($this->privilegedRole((int) $resignation->employee_id)) {
            'admin' => ! $user->hasRole('admin')
                ? self::PRIVILEGED_MESSAGE
                : ($own && ! $this->isSoleActiveAdmin($user) ? self::OWN_MESSAGE : null),
            'hr' => ! $user->hasRole('admin')
                ? ($own ? self::OWN_MESSAGE : self::PRIVILEGED_MESSAGE)
                : ($own ? self::OWN_MESSAGE : null),
            default => $own ? self::OWN_MESSAGE : null,
        };
    }

    /**
     * The step this user may decide now ("manager" or "final"), or null. A user who may decide the final step gets
     * "final" while the exit still waits for the manager (deciding then skips the manager).
     */
    public function decidableStage(User $user, Resignation $resignation): ?string
    {
        $stage = $this->stage($resignation);

        if ($stage === null) {
            return null;
        }

        if ($this->mayDecideManager($user, $resignation)) {
            return self::STAGE_MANAGER;
        }

        return $this->mayDecideFinal($user, $resignation) ? self::STAGE_FINAL : null;
    }

    /**
     * Whether the user may record an exit for this employee: exit.clearance, never for themselves, and an Admin or HR
     * person's only by an Admin.
     */
    public function mayRecordFor(User $user, Employee $employee): bool
    {
        if (! $user->can('exit.clearance')) {
            return false;
        }

        if ($user->employee_id !== null && (int) $user->employee_id === (int) $employee->id) {
            return false;
        }

        return $user->hasRole('admin') || $this->privilegedRole((int) $employee->id) === null;
    }

    /**
     * Whether the user may administer this exit (change the last working day, cancel, clearance, settlement drafts):
     * exit.clearance, not their own exit, and an Admin or HR person's only by an Admin.
     */
    public function mayManage(User $user, Resignation $resignation): bool
    {
        if (! $user->can('exit.clearance') || $this->isOwn($user, $resignation)) {
            return false;
        }

        return $user->hasRole('admin') || $this->privilegedRole((int) $resignation->employee_id) === null;
    }

    /**
     * The exits this user may see: everyone's with exit.clearance; with exit.approve-team those of the employees
     * EmployeeAccess lets them see (own and direct reports); otherwise only their own.
     *
     * @param  Builder<Resignation>  $query
     * @return Builder<Resignation>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('exit.clearance')) {
            return $query;
        }

        if ($user->can('exit.approve-team')) {
            return $query->whereIn('employee_id', (new EmployeeAccess($user))->scope(Employee::query())->select('id'));
        }

        if ($user->employee_id === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('employee_id', $user->employee_id);
    }

    /** The exit with this id when the user may see it, else null (a missing id and one out of scope look alike). */
    public function findVisible(User $user, int $id): ?Resignation
    {
        return $this->scope(Resignation::query()->whereKey($id), $user)->first();
    }

    /**
     * The exits waiting for this user's decision now (their manager step, or the final step for a final approver),
     * newest submission first. Worked out with decidableStage() over the open, undecided exits in scope, so the privileged
     * and own-exit rules apply; the result is a query on the matching ids.
     *
     * @return Builder<Resignation>
     */
    public function waitingFor(User $user): Builder
    {
        $candidates = $this->scope(
            Resignation::query()->with('employee')->whereIn('status', [ExitStates::PENDING, ExitStates::MANAGER_APPROVED]),
            $user,
        )->get();

        $this->prime($candidates);

        $ids = $candidates
            ->filter(fn (Resignation $r) => $this->decidableStage($user, $r) !== null)
            ->pluck('id')
            ->all();

        return Resignation::query()->whereKey($ids)->orderByDesc('submitted_on')->orderByDesc('id');
    }

    /** The privileged role this employee's login holds ("admin" wins over "hr"), or null (one query per instance). */
    public function privilegedRole(int $employeeId): ?string
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
    public function isSoleActiveAdmin(User $user): bool
    {
        $this->activeAdmins ??= User::role('admin')->where('is_active', true)->count();

        return $user->is_active && $user->hasRole('admin') && $this->activeAdmins === 1;
    }
}
