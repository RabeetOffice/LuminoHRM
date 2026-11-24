<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides who may see which employee, which groups of fields, and who may delete.
 * Every controller, resource, page prop and export goes through it (field visibility is a server rule, not a
 * display choice: fields a viewer may not see are left out of the data, not hidden in the page).
 *
 *   $access = new EmployeeAccess($request->user());
 *   $employees = $access->scope(Employee::query())->paginate();
 *   $groups = $access->fieldGroups($employee);        // e.g. ['basic', 'employment']
 *
 * Whether someone may open the list at all is a route permission (middleware); scope() only narrows the rows.
 */
class EmployeeAccess
{
    /** The field groups of a record. `identity` is the CNIC and the date of birth. */
    public const GROUPS = ['basic', 'employment', 'contact', 'banking', 'documents', 'identity'];

    /** What a manager sees of a direct report. */
    private const TEAM_GROUPS = ['basic', 'employment'];

    private ?int $ownId = null;

    private bool $ownLoaded = false;

    public function __construct(private readonly User $user) {}

    /**
     * Narrows a query to the employees this user may see: everyone for employees.view-all; the user's own record plus
     * direct reports for employees.view-team; only the user's own record otherwise. A user with no employee record
     * and no view-all permission sees nothing beyond what their team rule gives (nothing).
     *
     * @param  Builder<Employee>  $query
     * @return Builder<Employee>
     */
    public function scope(Builder $query): Builder
    {
        if ($this->user->can('employees.view-all')) {
            return $query;
        }

        $own = $this->ownId();

        if ($own === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($this->user->can('employees.view-team')) {
            return $query->where(fn (Builder $w) => $w->whereKey($own)->orWhere('manager_id', $own));
        }

        return $query->whereKey($own);
    }

    public function canView(Employee $employee): bool
    {
        return $this->isOwn($employee) || $this->isViewAll() || $this->isDirectReport($employee);
    }

    public function canManage(): bool
    {
        return $this->user->can('employees.manage');
    }

    /** True only with employees.delete AND no blocker (see deleteBlockers()). */
    public function canDelete(Employee $employee): bool
    {
        return $this->user->can('employees.delete') && $this->deleteBlockers($employee) === [];
    }

    /**
     * Why this employee cannot be deleted, one message per reason (empty when nothing stands in the way).
     * Later phases append their own checks here (attendance, leave, payroll, assets ...). A message tells the
     * Admin what to do instead, so it can be shown as it is.
     *
     * @return list<string>
     */
    public function deleteBlockers(Employee $employee): array
    {
        $blockers = [];

        if ($employee->reports()->exists()) {
            $blockers[] = 'This employee has direct reports. Reassign them first.';
        }

        $account = $employee->user;
        if ($account !== null && $account->last_login_at !== null) {
            $blockers[] = 'This employee has signed in before. Set the status to Inactive, Resigned or Terminated instead.';
        }

        return $blockers;
    }

    /**
     * The groups of fields this user may see on this employee, in the order of self::GROUPS.
     * Own record: all six, whatever the role (read-only is decided elsewhere). view-all: all six. A manager looking at
     * a direct report: basic and employment only. Anyone else: none.
     *
     * @return list<string>
     */
    public function fieldGroups(Employee $employee): array
    {
        if ($this->isOwn($employee) || $this->isViewAll()) {
            return self::GROUPS;
        }

        if ($this->isDirectReport($employee)) {
            return self::TEAM_GROUPS;
        }

        return [];
    }

    private function isViewAll(): bool
    {
        return $this->user->can('employees.view-all');
    }

    private function isOwn(Employee $employee): bool
    {
        $own = $this->ownId();

        return $own !== null && (int) $employee->getKey() === $own;
    }

    private function isDirectReport(Employee $employee): bool
    {
        $own = $this->ownId();

        return $own !== null
            && $employee->manager_id !== null
            && (int) $employee->manager_id === $own
            && $this->user->can('employees.view-team');
    }

    private function ownId(): ?int
    {
        if (! $this->ownLoaded) {
            $this->ownId = $this->user->employee_id === null ? null : (int) $this->user->employee_id;
            $this->ownLoaded = true;
        }

        return $this->ownId;
    }
}
