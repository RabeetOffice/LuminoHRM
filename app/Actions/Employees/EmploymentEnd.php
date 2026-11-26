<?php

namespace App\Actions\Employees;

use App\Actions\Leave\LeaveEmploymentTrim;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\RebuildMarks;
use App\Support\PayrollLock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The one step that puts a last working day on an employee (or clears it) and makes everything that follows the day
 * follow it. Extracted unchanged from ChangeEmployeeStatus; ChangeEmployeeStatus and the exit actions both call it.
 *
 * The caller holds the employee row FOR UPDATE inside its own transaction (lock order: employee, then payroll_runs
 * shared, then attendance and leave) and may already have set other attributes (the status) on $locked: they are saved
 * with the day.
 *
 * Payroll lock: a day set, moved or cleared changes the employment window on the days (earlier, later], an open end
 * counting as 9999-12-31. It is refused ([last_working_day] PayrollLock::message()) when one of those days is in a month
 * with an approved or paid payroll run, so a last working day on the last day of an approved month is fine and one
 * before it is not. When the day is unchanged nothing is checked (an approved month has ended, and a leaver without a
 * day loses only days from today on).
 *
 * Then, whether or not the day changed (a status change alone can move the window): the person is saved, attendance days
 * outside the window are flagged for the rebuild (RebuildMarks::outsideEmployment; manual days stay), and leave outside
 * it is trimmed (LeaveEmploymentTrim: approved days removed and refunded, open requests cancelled, audited).
 */
class EmploymentEnd
{
    /** An employment window with no last working day ends here (for the payroll lock check). */
    private const OPEN_END = '9999-12-31';

    public function __construct(private readonly LeaveEmploymentTrim $trim) {}

    /**
     * Sentences for the person who saved (flash) after apply(): hand-corrected attendance days whose leave the trim
     * removed (they keep their leave status: check them). Empty when there is nothing to say.
     *
     * @return list<string>
     */
    public function notices(): array
    {
        return $this->trim->notices();
    }

    /**
     * @param  string|null  $lastWorkingDay  "Y-m-d", or null to clear it
     * @param  User|null  $by  who changed it; null = the system (the nightly job)
     * @return bool whether the last working day changed
     *
     * @throws ValidationException the change touches a locked payroll month
     */
    public function apply(Employee $locked, ?string $lastWorkingDay, ?User $by): bool
    {
        $oldDay = EmployeeWriter::auditValue($locked->last_working_day);
        $changed = $oldDay !== $lastWorkingDay;

        if ($changed) {
            $from = (string) ($oldDay ?? self::OPEN_END);
            $to = (string) ($lastWorkingDay ?? self::OPEN_END);
            [$low, $high] = $from <= $to ? [$from, $to] : [$to, $from];
            $lockedMonth = PayrollLock::rangeTouchesLocked(CarbonImmutable::createFromFormat('!Y-m-d', $low)->addDay()->format('Y-m-d'), $high);

            if ($lockedMonth !== null) {
                throw ValidationException::withMessages(['last_working_day' => PayrollLock::message($lockedMonth)]);
            }
        }

        $locked->disableLogging(); // the caller's audit entry is the record of this change
        $locked->setAttribute('last_working_day', $lastWorkingDay);
        $locked->save();

        // Attendance days now outside the person's window (after a new last working day, or from today for a leaver
        // without one) are flagged so the rebuild removes them; manual days stay. Days the window gained (a last working
        // day extended or cleared, back to active) are backfilled by the hourly close.
        RebuildMarks::outsideEmployment($locked);

        // Leave outside the new window goes too: approved days after the last working day (from today for a leaver
        // without one) are removed and refunded, open requests reaching past it are cancelled (audited; mail after the
        // commit, only to an active login).
        $this->trim->apply($locked, $by);

        return $changed;
    }
}
