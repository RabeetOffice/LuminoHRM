<?php

namespace App\Support\Attendance;

use App\Actions\Attendance\ApplyManualCorrection;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who decides a correction request, at which step, and who may see it.
 *
 * Steps: the reporting manager first, then HR (attendance.edit). The manager step is skipped (the request waits for
 * HR straight away) when the employee has no manager, the manager has no login or a disabled one, the manager's login
 * is the requester's own, the manager may not open the screen (no attendance.view-team), or the manager holds
 * attendance.edit themselves. In that last case the manager decides once, as HR: one person never approves the same
 * request at both steps.
 *
 * HR's decision needs, on top of attendance.edit, the same "may correct this record" rule as the Daily screen
 * (ApplyManualCorrection::mayCorrect): a request of an Admin or HR account is decided by an Admin. A user who may
 * decide as HR may also decide a request still waiting for the manager (approving skips the manager, audited as
 * manager_skipped), so a request never gets stuck behind a manager who does not answer.
 *
 * The step is worked out when it is needed (not stored), so a request waiting for a manager whose login is later
 * disabled moves to HR by itself, and a new manager sees (and decides) the requests of their new report; a manager's
 * approval already given is kept when the manager changes afterwards.
 *
 * Nobody decides their own request at any step. The user who approved as manager can never also decide the HR step.
 */
class CorrectionRouting
{
    public const STAGE_MANAGER = 'manager';

    public const STAGE_HR = 'hr';

    /** @var array<int, User|null> manager employee id => their login, for one request's list */
    private array $logins = [];

    /** @var array<int, true>|null employee ids whose login is Admin or HR, loaded once per instance */
    private ?array $privileged = null;

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

        if (! $user->can('attendance.view-team') || $user->can('attendance.edit')) {
            return null;
        }

        return $user;
    }

    /** The step a request is waiting at: "manager", "hr", or null once it is decided or cancelled. */
    public function stage(AttendanceCorrection $correction): ?string
    {
        return match ($correction->status) {
            'pending' => $this->managerApprover($correction->employee) !== null ? self::STAGE_MANAGER : self::STAGE_HR,
            'manager_approved' => self::STAGE_HR,
            default => null,
        };
    }

    /** True when the user's own employee record made the request. */
    public function isRequester(User $user, AttendanceCorrection $correction): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $correction->employee_id;
    }

    /**
     * Whether the user may give HR's (final) decision on this request, whatever its state: attendance.edit, not the
     * requester, not the user who approved it as manager, and allowed to correct this employee's record.
     */
    public function mayDecideAsHr(User $user, AttendanceCorrection $correction): bool
    {
        $approvedAsManager = $correction->manager_id !== null && (int) $correction->manager_id === (int) $user->id;

        return $user->can('attendance.edit')
            && ! $this->isRequester($user, $correction)
            && ! $approvedAsManager
            && ApplyManualCorrection::mayCorrect($user, $correction->employee, $this->isPrivileged($correction->employee));
    }

    /**
     * Whether the user is one of this request's deciders at all (the routed manager now, the manager who decided, or
     * someone who may decide as HR), whatever its state. Anyone else gets 403 before any "already decided" message.
     */
    public function isApprover(User $user, AttendanceCorrection $correction): bool
    {
        if ($this->isRequester($user, $correction)) {
            return false;
        }

        return ($correction->manager_id !== null && (int) $correction->manager_id === (int) $user->id)
            || ($this->managerApprover($correction->employee)?->is($user) ?? false)
            || $this->mayDecideAsHr($user, $correction);
    }

    /**
     * The step this user may decide now ("manager" or "hr"), or null. A user who may decide as HR gets "hr" while the
     * request still waits for the manager (deciding then skips the manager), unless they are that manager.
     */
    public function decidableStage(User $user, AttendanceCorrection $correction): ?string
    {
        if ($this->isRequester($user, $correction)) {
            return null;
        }

        $stage = $this->stage($correction);

        if ($stage === self::STAGE_MANAGER && ($this->managerApprover($correction->employee)?->is($user) ?? false)) {
            return self::STAGE_MANAGER;
        }

        if ($stage !== null && $this->mayDecideAsHr($user, $correction)) {
            return self::STAGE_HR;
        }

        return null;
    }

    /** True when this user deciding now would skip the manager step. */
    public function skipsManager(User $user, AttendanceCorrection $correction): bool
    {
        return $this->stage($correction) === self::STAGE_MANAGER && $this->decidableStage($user, $correction) === self::STAGE_HR;
    }

    /** The requester may cancel while nobody has decided anything yet. */
    public function canCancel(User $user, AttendanceCorrection $correction): bool
    {
        return $this->isRequester($user, $correction) && $correction->status === 'pending';
    }

    /**
     * Active logins that may decide the HR step of this employee's requests (attendance.edit and allowed to correct
     * this record), never the requester's own login: nobody is mailed about a request they cannot decide.
     *
     * @return Collection<int, User>
     */
    public function hrApprovers(Employee $employee, ?User $except = null): Collection
    {
        $privileged = $this->isPrivileged($employee);

        return User::permission('attendance.edit')
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employee->id))
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->orderBy('id')
            ->get()
            ->filter(fn (User $u) => ApplyManualCorrection::mayCorrect($u, $employee, $privileged))
            ->values();
    }

    /**
     * The requests this user may see: those of the employees EmployeeAccess lets them see (as on the Daily screen),
     * for a user with attendance.view-all or attendance.view-team; nothing otherwise.
     *
     * @param  Builder<AttendanceCorrection>  $query
     * @return Builder<AttendanceCorrection>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if (! $user->canAny(['attendance.view-all', 'attendance.view-team'])) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->can('employees.view-all')) {
            return $query;
        }

        return $query->whereIn('employee_id', (new EmployeeAccess($user))->scope(Employee::query())->select('id'));
    }

    /** The request with this id when the user may see it, else null (a missing id and one out of scope look alike). */
    public function findVisible(User $user, int $id): ?AttendanceCorrection
    {
        return $this->scope(AttendanceCorrection::query()->whereKey($id), $user)->first();
    }

    public function canSee(User $user, AttendanceCorrection $correction): bool
    {
        return $this->scope(AttendanceCorrection::query()->whereKey($correction->id), $user)->exists();
    }

    /** Whether this employee's login holds the admin or hr role (one query per instance, for every employee). */
    private function isPrivileged(Employee $employee): bool
    {
        $this->privileged ??= array_fill_keys(
            User::role(['admin', 'hr'])->whereNotNull('employee_id')->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
            true,
        );

        return isset($this->privileged[(int) $employee->id]);
    }
}
