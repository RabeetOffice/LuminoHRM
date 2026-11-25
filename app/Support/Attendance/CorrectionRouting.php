<?php

namespace App\Support\Attendance;

use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
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
 * The step is worked out when it is needed (not stored), so a request waiting for a manager whose login is later
 * disabled moves to HR by itself, and a new manager sees the requests of their new report.
 *
 * Nobody decides their own request at any step; an HR user who is the requester needs another Admin or HR user. The
 * user who approved as manager can never also decide the HR step.
 */
class CorrectionRouting
{
    public const STAGE_MANAGER = 'manager';

    public const STAGE_HR = 'hr';

    /** @var array<int, User|null> manager employee id => their login, for one request's list */
    private array $logins = [];

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

    /** The step this user may decide now ("manager" or "hr"), or null. */
    public function decidableStage(User $user, AttendanceCorrection $correction): ?string
    {
        if ($this->isRequester($user, $correction)) {
            return null;
        }

        $stage = $this->stage($correction);

        if ($stage === self::STAGE_MANAGER) {
            return $this->managerApprover($correction->employee)?->is($user) ? self::STAGE_MANAGER : null;
        }

        if ($stage === self::STAGE_HR) {
            $approvedAsManager = $correction->manager_id !== null && (int) $correction->manager_id === (int) $user->id;

            return $user->can('attendance.edit') && ! $approvedAsManager ? self::STAGE_HR : null;
        }

        return null;
    }

    /** The requester may cancel while nobody has decided anything yet. */
    public function canCancel(User $user, AttendanceCorrection $correction): bool
    {
        return $this->isRequester($user, $correction) && $correction->status === 'pending';
    }

    /**
     * Active logins that decide the HR step of this employee's requests, never the requester's own login.
     *
     * @return Collection<int, User>
     */
    public function hrApprovers(Employee $employee, ?User $except = null): Collection
    {
        return User::permission('attendance.edit')
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('employee_id')->orWhere('employee_id', '!=', $employee->id))
            ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except->id))
            ->orderBy('id')
            ->get();
    }

    /**
     * The requests this user may see: everyone's with attendance.view-all; otherwise their direct reports' and their
     * own with attendance.view-team; nothing else.
     *
     * @param  Builder<AttendanceCorrection>  $query
     * @return Builder<AttendanceCorrection>
     */
    public function scope(Builder $query, User $user): Builder
    {
        if ($user->can('attendance.view-all')) {
            return $query;
        }

        $own = $user->employee_id;

        if ($own === null || ! $user->can('attendance.view-team')) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $w) => $w
            ->where('employee_id', $own)
            ->orWhereIn('employee_id', Employee::query()->select('id')->where('manager_id', $own)));
    }

    public function canSee(User $user, AttendanceCorrection $correction): bool
    {
        return $this->scope(AttendanceCorrection::query()->whereKey($correction->id), $user)->exists();
    }
}
