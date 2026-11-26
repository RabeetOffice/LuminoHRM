<?php

namespace App\Actions\Employees;

use App\Actions\SetUserActive;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\Leavers;
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
 *
 * Payroll lock: a last working day change is refused ([last_working_day] PayrollLock::message()) when a day it adds to
 * or removes from the employment window ((earlier day, later day], no day counting as 9999-12-31) is in a month with an
 * approved or paid payroll run. A status change that keeps the day touches no locked month (an approved month has
 * ended, and a leaver without a day loses only days from today on).
 */
class ChangeEmployeeStatus
{
    /** The refusal while an exit is open: its last working day is changed on the exit page. */
    public const OPEN_EXIT_MESSAGE = 'This person has an open exit. Change the last working day on the exit page.';

    /** Statuses that end a person's access. */
    public const DISABLING = Leavers::STATUSES;

    /** Statuses that need a last working day. */
    public const NEEDS_DAY = ['resigned', 'terminated'];

    private bool $changed = false;

    public function __construct(
        private readonly SetUserActive $setActive,
        private readonly EmploymentEnd $end,
    ) {}

    /** Whether the last handle() changed anything (false when the stored status and day were asked for again). */
    public function changed(): bool
    {
        return $this->changed;
    }

    /**
     * After handle(): sentences for the person who saved (flash), e.g. hand-corrected attendance days whose leave the
     * trim removed (they keep their leave status: check them). Empty when there is nothing to say.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->changed ? $this->end->notices() : [];
    }

    /**
     * @param  User|null  $by  who changes it; null = the system (the nightly exit job): the Admin / HR rule is skipped and
     *                         the audit entries have no causer
     *
     * @throws AccountRuleViolation the account rules refused (nothing is saved)
     * @throws ValidationException resigned or terminated without a last working day
     */
    public function handle(Employee $employee, string $status, ?string $lastWorkingDay, ?User $by): Employee
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
            $account = $current->user;

            // Only an Admin changes anything about an Admin or HR person (judged on the role as stored now). Asked
            // before the no-op check, so a refused person is told so even when they re-post the current status.
            if ($by !== null && ! EmployeeRoles::mayTouchAccount($by, $account)) {
                throw new AccountRuleViolation(EmployeeRoles::PRIVILEGED_MESSAGE);
            }

            // Asking for what is already stored is a successful no-op: no write, no entry, the login is not touched.
            if ($current->status === $status && EmployeeWriter::auditValue($current->last_working_day) === $day) {
                return $current;
            }

            // While an exit is open its last working day belongs to the exit page: this screen may not set, move or
            // clear it (a status change that keeps the day is fine). Read after the employee row lock.
            $oldDay = EmployeeWriter::auditValue($current->last_working_day);

            if ($oldDay !== $day && $current->openResignation()->exists()) {
                throw ValidationException::withMessages(['last_working_day' => self::OPEN_EXIT_MESSAGE]);
            }

            $wasActive = ! in_array($current->status, self::DISABLING, true);
            $old = [
                'status' => $current->status,
                'last_working_day' => $oldDay,
            ];

            // The status and the day are saved together by EmploymentEnd, which also checks the payroll lock on the
            // window the day change adds or removes, flags the attendance days outside the window and trims leave.
            $current->disableLogging(); // the entry below is the record of this change
            $current->status = $status;
            $this->end->apply($current, $day, $by);

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
