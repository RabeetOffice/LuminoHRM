<?php

namespace App\Actions\Employees;

use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\LastAdminGuard;
use Illuminate\Support\Facades\DB;

/**
 * Removes a mistaken employee record: the row, its satellites (database cascade) and its login account, which must never
 * have been used. One transaction with one audit entry that keeps the name and code, because the row will be gone.
 *
 * The caller has checked employees.delete and EmployeeAccess::deleteBlockers(); this class re-checks the account rule
 * that no one can end up without an active Admin.
 */
class DeleteEmployee
{
    /** @throws AccountRuleViolation */
    public function handle(Employee $employee, User $by): void
    {
        DB::transaction(function () use ($employee, $by): void {
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();
            $account = $current->user;

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
                LastAdminGuard::protect($account, function () use ($account, $by): void {
                    Audit::record('users', 'Account deleted with its employee record', $account, [], $by);
                    $account->delete();
                });
            }

            $current->delete();
        }, attempts: 3);
    }
}
