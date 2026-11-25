<?php

namespace App\Support\Payroll;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\EmployeeRoles;

/**
 * Who may change salary data and who may approve a payroll run. Seeing salary data is the route permission
 * payroll.view (Admin, HR); Managers and Employees never get a salary figure except their own approved payslips.
 *
 *   PayrollAccess::mayEditSalary($hr, $employee);   // profiles, loans, opening figures, draft line edits
 *   PayrollAccess::mayApprove($admin, $run);
 */
final class PayrollAccess
{
    /**
     * payroll.run; never one's own record unless Admin; a person whose login is Admin or HR only by an Admin
     * (EmployeeRoles::mayTouchAccount). Applies to salary profiles, loans, opening figures and draft line edits.
     */
    public static function mayEditSalary(User $by, Employee $employee): bool
    {
        if (! $by->can('payroll.run')) {
            return false;
        }

        if ($by->hasRole('admin')) {
            return true;
        }

        if ($by->employee_id !== null && (int) $by->employee_id === (int) $employee->getKey()) {
            return false;
        }

        return EmployeeRoles::mayTouchAccount($by, $employee->user);
    }

    /** Whether the run holds a line (excluded or not) of $by's own employee record. */
    public static function approvesOwn(User $by, PayrollRun $run): bool
    {
        if ($by->employee_id === null) {
            return false;
        }

        return PayrollItem::query()
            ->where('payroll_run_id', $run->getKey())
            ->where('employee_id', $by->employee_id)
            ->exists();
    }

    /** $user holds the admin role and no OTHER active user does. */
    public static function soleActiveAdmin(User $user): bool
    {
        if (! $user->hasRole('admin')) {
            return false;
        }

        return ! User::role('admin')
            ->where('users.is_active', true)
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /**
     * payroll.approve, and not a run holding the approver's own line unless they are the sole active Admin (then the
     * approval is audited with own_payslip: true). Whether the run may be approved now (draft, no blocking check) is
     * the action's business.
     */
    public static function mayApprove(User $by, PayrollRun $run): bool
    {
        if (! $by->can('payroll.approve')) {
            return false;
        }

        return ! self::approvesOwn($by, $run) || self::soleActiveAdmin($by);
    }
}
