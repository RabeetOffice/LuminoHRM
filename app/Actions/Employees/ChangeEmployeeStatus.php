<?php

namespace App\Actions\Employees;

use App\Actions\Leave\LeaveEmploymentTrim;
use App\Actions\SetUserActive;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\Leavers;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use App\Support\PayrollLock;
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
 * Payroll lock: a last working day change whose old-to-new range touches a month with an approved or paid payroll run
 * is refused ([last_working_day] PayrollLock::message()); with no old day the new day's month is checked, when cleared
 * the old day's month. A status change that keeps the day touches no locked month (an approved month has ended, and
 * a leaver without a day loses only days from today on).
 */
class ChangeEmployeeStatus
{
    /** Statuses that end a person's access. */
    public const DISABLING = Leavers::STATUSES;

    /** Statuses that need a last working day. */
    public const NEEDS_DAY = ['resigned', 'terminated'];

    private bool $changed = false;

    public function __construct(
        private readonly SetUserActive $setActive,
        private readonly LeaveEmploymentTrim $trim,
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
        return $this->changed ? $this->trim->notices() : [];
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
            $account = $current->user;

            // Only an Admin changes anything about an Admin or HR person (judged on the role as stored now). Asked
            // before the no-op check, so a refused person is told so even when they re-post the current status.
            if (! EmployeeRoles::mayTouchAccount($by, $account)) {
                throw new AccountRuleViolation(EmployeeRoles::PRIVILEGED_MESSAGE);
            }

            // Asking for what is already stored is a successful no-op: no write, no entry, the login is not touched.
            if ($current->status === $status && EmployeeWriter::auditValue($current->last_working_day) === $day) {
                return $current;
            }

            // A last working day set, moved or cleared across a month whose payroll is approved would change that month:
            // refused. Set from none: the new day's month; cleared: the old day's month; moved: every month between.
            // After the employee row lock (lock order: employee, then payroll_runs shared, then attendance and leave).
            $oldDay = EmployeeWriter::auditValue($current->last_working_day);

            if ($oldDay !== $day) {
                $locked = PayrollLock::rangeTouchesLocked((string) ($oldDay ?? $day), (string) ($day ?? $oldDay));

                if ($locked !== null) {
                    throw ValidationException::withMessages(['last_working_day' => PayrollLock::message($locked)]);
                }
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

            // Attendance days now outside the person's window (after a new last working day, or from today for a
            // leaver without one) are flagged so the rebuild removes them; manual days stay. Days the window gained
            // (a last working day extended or cleared, back to active) are backfilled by the hourly close.
            RebuildMarks::outsideEmployment($current);

            // Leave outside the new window goes too: approved days after the last working day (from today for a leaver
            // without one) are removed and refunded, open requests reaching past it are cancelled (audited; mail after
            // the commit, only to an active login, which a leaver's is not once the step below disables it).
            $this->trim->apply($current, $by);

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
