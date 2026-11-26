<?php

namespace App\Support;

use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\Review;
use App\Models\User;
use App\Support\Leave\LeaveDays;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

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

    public const LEAVE_REQUESTS_MESSAGE = 'This employee has leave requests. Set the status to Inactive, Resigned or Terminated instead.';

    public const LEAVE_ADJUSTMENTS_MESSAGE = 'This employee has leave balance adjustments. Set the status to Inactive, Resigned or Terminated instead.';

    public const PAYROLL_MESSAGE = 'This employee has payroll history. Set the status to Inactive, Resigned or Terminated instead.';

    public const ASSETS_MESSAGE = 'This employee has asset history. Set the status to Inactive, Resigned or Terminated instead.';

    public const REVIEWS_MESSAGE = 'This employee has performance reviews or KPIs. Set the status to Inactive, Resigned or Terminated instead.';

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

        if ($employee->attendances()->exists()) {
            $blockers[] = 'This employee has attendance records. Set the status to Inactive, Resigned or Terminated instead.';
        }

        // The foreign key cascades, so a hard delete would silently remove the request history.
        if (AttendanceCorrection::query()->where('employee_id', $employee->getKey())->exists()) {
            $blockers[] = 'This employee has attendance correction requests. Set the status to Inactive, Resigned or Terminated instead.';
        }

        // Leave rows restrict the delete at the database; any request (whatever its status) is history to keep.
        if (LeaveRequest::query()->where('employee_id', $employee->getKey())->exists()) {
            $blockers[] = self::LEAVE_REQUESTS_MESSAGE;
        }

        // Plain balance rows go with the record (DeleteEmployee); hand-entered figures are kept.
        if (self::hasLeaveBalanceAdjustments((int) $employee->getKey())) {
            $blockers[] = self::LEAVE_ADJUSTMENTS_MESSAGE;
        }

        // Payroll rows restrict the delete at the database; salary history is kept.
        if ($employee->salaryProfiles()->exists() || $employee->loans()->exists()
            || $employee->openingBalances()->exists() || $employee->payrollItems()->exists()) {
            $blockers[] = self::PAYROLL_MESSAGE;
        }

        // Assignment rows restrict the delete at the database; who held what is history to keep.
        if ($employee->assetAssignments()->exists()) {
            $blockers[] = self::ASSETS_MESSAGE;
        }

        // Review and KPI rows restrict the delete at the database; reviews (as the person reviewed or as the reviewer) and
        // personal KPIs (active or not) are history to keep.
        if (self::hasPerformanceRecords((int) $employee->getKey())) {
            $blockers[] = self::REVIEWS_MESSAGE;
        }

        return $blockers;
    }

    /** Whether the employee has any review (as reviewee or reviewer) or any personal KPI, whatever its status. */
    public static function hasPerformanceRecords(int $employeeId): bool
    {
        return Review::query()->where('employee_id', $employeeId)->orWhere('reviewer_id', $employeeId)->exists()
            || Kpi::query()->where('employee_id', $employeeId)->exists();
    }

    /**
     * Whether any of the employee's leave balance rows holds a hand-entered figure: a non-zero adjustment or opening
     * used, or an `allocated` HR changed by hand (a "Leave balance adjusted" audit entry whose old and new allocated
     * differ — the audit trail is the reliable record of that edit; comparing with allocationFor() would also flag rows
     * created under an older quota or pro-rating setting).
     */
    public static function hasLeaveBalanceAdjustments(int $employeeId): bool
    {
        $figures = LeaveBalance::query()
            ->where('employee_id', $employeeId)
            ->where(fn (Builder $q) => $q->where('adjustment', '!=', 0)->orWhere('opening_used', '!=', 0))
            ->exists();

        if ($figures) {
            return true;
        }

        foreach (Activity::query()
            ->where('log_name', 'leave')->where('description', 'Leave balance adjusted')
            ->where('subject_type', (new Employee)->getMorphClass())->where('subject_id', $employeeId)
            ->get(['properties']) as $entry) {
            $p = $entry->properties;

            if (LeaveDays::halves($p['old']['allocated'] ?? 0) !== LeaveDays::halves($p['attributes']['allocated'] ?? 0)) {
                return true;
            }
        }

        return false;
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
