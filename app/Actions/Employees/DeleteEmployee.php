<?php

namespace App\Actions\Employees;

use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\EmployeeRoles;
use App\Support\LastAdminGuard;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Removes a mistaken employee record: the row, its satellites (database cascade), its stored photo and documents
 * (after the commit) and its login account, which must never have been used. One transaction with one audit entry
 * that keeps the name and code, because the row will be gone.
 *
 * The caller has checked employees.delete and EmployeeAccess::deleteBlockers(); this class re-checks the account rule
 * that no one can end up without an active Admin, the two leave blockers (leave requests, hand-entered balance
 * figures) and the payroll blocker (salary profiles, loans, payroll lines, opening balances) under the row lock. The employee's plain leave balance rows are deleted with the record.
 */
class DeleteEmployee
{
    /** @throws AccountRuleViolation */
    public function handle(Employee $employee, User $by): void
    {
        $id = (int) $employee->getKey();

        DB::transaction(function () use ($employee, $by): void {
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $account = $current->user;

            // Leave rows restrict the delete at the database. Re-checked under the lock (the caller's blockers ran before
            // it): any request, or a balance row with a hand-entered figure, refuses the delete.
            if (LeaveRequest::query()->where('employee_id', $current->getKey())->exists()) {
                throw new AccountRuleViolation(EmployeeAccess::LEAVE_REQUESTS_MESSAGE);
            }

            if (EmployeeAccess::hasLeaveBalanceAdjustments((int) $current->getKey())) {
                throw new AccountRuleViolation(EmployeeAccess::LEAVE_ADJUSTMENTS_MESSAGE);
            }

            // Payroll rows restrict the delete at the database too; salary history is kept. Re-checked under the lock.
            if ($current->salaryProfiles()->exists() || $current->loans()->exists()
                || $current->payrollItems()->exists() || $current->openingBalances()->exists()) {
                throw new AccountRuleViolation(EmployeeAccess::PAYROLL_MESSAGE);
            }

            Audit::record(
                'employees',
                'Employee deleted',
                $current,
                [
                    'attributes' => [
                        'employee_code' => $current->employee_code,
                        'first_name' => $current->first_name,
                        'last_name' => $current->last_name,
                        'email' => $current->email,
                        'account_removed' => $account !== null,
                    ],
                ],
                $by,
            );

            $current->disableLogging();

            if ($account !== null) {
                if (! EmployeeRoles::mayTouchAccount($by, $account)) {
                    throw new AccountRuleViolation(EmployeeRoles::PRIVILEGED_MESSAGE);
                }

                $madeWithEmployee = Activity::query()
                    ->where('log_name', 'users')
                    ->where('subject_type', $account->getMorphClass())
                    ->where('subject_id', $account->getKey())
                    ->where('description', EmployeeWriter::CREATED_WITH_EMPLOYEE)
                    ->get()
                    ->contains(fn (Activity $entry) => (int) ($entry->properties['employee_id'] ?? 0) === (int) $current->getKey());

                if ($madeWithEmployee && $account->last_login_at === null) {
                    // Made for this person and never used: it goes with them, and so do its password links.
                    LastAdminGuard::protect($account, function () use ($account, $by): void {
                        Audit::record('users', 'Account deleted with its employee record', $account, [], $by);
                        DB::table('password_reset_tokens')->where('email', $account->email)->delete();
                        $account->delete();
                    });
                } else {
                    // The account existed before this record (or has been used): it stays, just unlinked.
                    $account->forceFill(['employee_id' => null])->save();
                }
            }

            // Plain allocated balance rows go with the record (their foreign key restricts the delete).
            LeaveBalance::query()->where('employee_id', $current->getKey())->delete();

            $current->delete();
        }, attempts: 3);

        // The rows went with the record (database cascade); the photo and document files go now that it has committed.
        // A disk failure must not turn a completed delete into an error page; report it and leave the leftovers.
        try {
            EmployeeFiles::deleteAll($id);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
