<?php

namespace App\Actions\Employees;

use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
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
 * that no one can end up without an active Admin.
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
