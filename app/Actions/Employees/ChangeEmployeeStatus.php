<?php

namespace App\Actions\Employees;

use App\Actions\SetUserActive;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only way an employee's status changes. One transaction: the status, the last working day, the login account
 * and one audit entry.
 *
 * - inactive, resigned, terminated: the login is disabled (SetUserActive: sessions end, the last-active-Admin rule and
 *   the no-self-disable rule apply and refuse with their own messages).
 * - active, probation: the login is enabled again and the last working day is cleared.
 * - resigned and terminated need a last working day; inactive keeps one only if given.
 *
 * The date rules (not before the joining date, at most a year ahead) are checked by the request; this class only
 * insists that the day exists where it must.
 */
class ChangeEmployeeStatus
{
    /** Statuses that end a person's access. */
    public const DISABLING = ['inactive', 'resigned', 'terminated'];

    /** Statuses that need a last working day. */
    public const NEEDS_DAY = ['resigned', 'terminated'];

    private bool $changed = false;

    public function __construct(private readonly SetUserActive $setActive) {}

    /** Whether the last handle() changed anything (false when the stored status and day were asked for again). */
    public function changed(): bool
    {
        return $this->changed;
    }

    /**
     * @throws AccountRuleViolation the account rules refused (nothing is saved)
     * @throws ValidationException resigned or terminated without a last working day
     */
    public function handle(Employee $employee, string $status, ?string $lastWorkingDay, User $by): Employee
    {
        $this->changed = false;

        if (! in_array($status, config('hrm.employees.statuses'), true)) {
            throw new InvalidArgumentException("Unknown status [{$status}].");
        }

        $day = $status === 'active' || $status === 'probation' ? null : ($lastWorkingDay === '' ? null : $lastWorkingDay);

        if (in_array($status, self::NEEDS_DAY, true) && $day === null) {
            throw ValidationException::withMessages(['last_working_day' => 'Enter the last working day.']);
        }

        return DB::transaction(function () use ($employee, $status, $day, $by): Employee {
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            // Asking for what is already stored is a successful no-op: no write, no entry, the login is not touched.
            if ($current->status === $status && EmployeeWriter::auditValue($current->last_working_day) === $day) {
                return $current;
            }

            $account = $current->user;

            // Only an Admin changes anything about an Admin or HR person (judged on the role as stored now).
            if (! EmployeeRoles::mayTouchAccount($by, $account)) {
                throw new AccountRuleViolation(EmployeeRoles::PRIVILEGED_MESSAGE);
            }

            $wasActive = ! in_array($current->status, self::DISABLING, true);
            $old = [
                'status' => $current->status,
                'last_working_day' => EmployeeWriter::auditValue($current->last_working_day),
            ];

            $current->disableLogging(); // the entry below is the record of this change
            $current->status = $status;
            $current->setAttribute('last_working_day', $day);
            $current->save();

            // The login follows the status only when it moves between "can sign in" and "cannot"; a change inside a
            // group (or a re-post) never touches an account an Admin disabled or enabled on the Users screen.
            $willBeActive = ! in_array($status, self::DISABLING, true);

            if ($account !== null && $wasActive !== $willBeActive) {
                $this->setActive->handle($account, $willBeActive, actor: $by);
            }

            Audit::record(
                'employees',
                'Employee status changed',
                $current,
                [
                    'old' => $old,
                    'attributes' => ['status' => $status, 'last_working_day' => $day],
                ],
                $by,
            );

            $this->changed = true;

            return $current;
        }, attempts: 3);
    }
}
