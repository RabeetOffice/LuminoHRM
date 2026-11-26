<?php

namespace App\Support\Exits;

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Payroll\PayrollAccess;

/**
 * Who may see, edit and approve a final settlement (design 5.9). Managers and Employees get nothing here: the figures
 * need exit.clearance and payroll.view (an employee's own statement is the document route's business).
 *
 *   SettlementAccess::mayView($hr, $settlement);     // exit.clearance + payroll.view; one's own only once approved
 *   SettlementAccess::mayEdit($hr, $settlement);     // prepare, lines, tax override, recalculate (drafts)
 *   SettlementAccess::mayApprove($admin, $settlement);
 *
 * editRefusal() gives the exact text for the two named refusals (own settlement, an Admin / HR person's by non-Admin).
 * The edit actions raise the own one as a 403 carrying that text, the other as a validation refusal; any other "no" is
 * a 403.
 */
final class SettlementAccess
{
    public const OWN = 'You cannot prepare or edit your own settlement.';

    public const PRIVILEGED = 'Only an Admin can edit the settlement of an Admin or HR person.';

    public const OWN_APPROVE = 'You cannot approve your own settlement while another Admin can.';

    public static function mayView(User $user, FinalSettlement $settlement): bool
    {
        if (! $user->can('exit.clearance') || ! $user->can('payroll.view')) {
            return false;
        }

        return ! self::isOwn($user, (int) $settlement->employee_id) || $settlement->isFrozen();
    }

    public static function mayEdit(User $user, FinalSettlement $settlement): bool
    {
        $resignation = Resignation::query()->find($settlement->resignation_id);

        return $resignation !== null && self::mayEditFor($user, $resignation);
    }

    /** exit.clearance and ExitRouting::mayManage (not own; an Admin / HR person's only by an Admin) + mayEditSalary. */
    public static function mayEditFor(User $user, Resignation $resignation, ?ExitRouting $routing = null): bool
    {
        $employee = $resignation->relationLoaded('employee') ? $resignation->employee : Employee::query()->find($resignation->employee_id);

        return $employee !== null
            && ($routing ?? new ExitRouting)->mayManage($user, $resignation)
            && PayrollAccess::mayEditSalary($user, $employee);
    }

    /** The named refusal for an edit by this user, or null (then mayEditFor decides; false = 403). */
    public static function editRefusal(User $user, Resignation $resignation, ?ExitRouting $routing = null): ?string
    {
        if (! $user->can('exit.clearance')) {
            return null;
        }

        if (self::isOwn($user, (int) $resignation->employee_id)) {
            return self::OWN;
        }

        $routing ??= new ExitRouting;

        return ! $user->hasRole('admin') && $routing->privilegedRole((int) $resignation->employee_id) !== null ? self::PRIVILEGED : null;
    }

    /** exit.manage; one's own settlement only while the sole active Admin (audited own_settlement). */
    public static function mayApprove(User $user, FinalSettlement $settlement): bool
    {
        if (! $user->can('exit.manage')) {
            return false;
        }

        return ! self::isOwn($user, (int) $settlement->employee_id) || PayrollAccess::soleActiveAdmin($user);
    }

    public static function isOwn(User $user, int $employeeId): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === $employeeId;
    }
}
