<?php

namespace App\Actions\Exits;

use App\Actions\Employees\EmployeeWriter;
use App\Actions\Employees\EmploymentEnd;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR moves the last working day of an approved exit (until its settlement is approved). Same rules as recording (not
 * before the joining date, at most 365 days ahead, a past day allowed, not in a month an approved or paid run already
 * paid this person). Locks: employee, resignation, payroll_runs shared, settlement. The employee's day moves through EmploymentEnd
 * (payroll lock check, attendance marks, leave trim again: leave already trimmed is not restored); a draft settlement
 * follows the new day (its month) and is marked stale, so it must be recalculated before it can be approved.
 *
 * Once the nightly job has changed the status the day may only move to another date before today.
 */
class ChangeExitLastDay
{
    public const NOT_APPROVED = 'Only an approved exit can change its last working day.';

    public const STATUS_APPLIED = 'The status has already changed, so the last working day can only move to a date before today.';

    public function __construct(private readonly EmploymentEnd $end) {}

    /** @return list<string> */
    public function notices(): array
    {
        return $this->end->notices();
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException the user may not administer this exit
     */
    public function handle(User $by, Resignation $resignation, string $lastDay): Resignation
    {
        return DB::transaction(function () use ($by, $resignation, $lastDay): Resignation {
            $employee = ExitGuards::lockEmployee((int) $resignation->employee_id);
            $locked = ExitGuards::lockResignation((int) $resignation->id, $employee);

            if (! (new ExitRouting)->mayManage($by, $locked)) {
                throw new AuthorizationException;
            }

            ExitGuards::assertNotEnded($locked);

            if ($locked->status !== ExitStates::APPROVED) {
                ExitGuards::refuse(self::NOT_APPROVED);
            }

            ExitGuards::lockRuns(); // before the settlement row (lock order; EmploymentEnd reads the runs later)
            $settlement = ExitGuards::lockSettlement((int) $locked->id);

            if ($settlement?->isFrozen()) {
                ExitGuards::refuse(ExitGuards::SETTLEMENT_FROZEN);
            }

            ExitGuards::assertLastDay($employee, $lastDay, minToday: false);
            ExitGuards::assertNotPaidInRun($employee, $lastDay);

            if ($locked->status_applied_at !== null && $lastDay >= ExitGuards::today()) {
                ExitGuards::refuse(self::STATUS_APPLIED, 'last_working_day');
            }

            $old = $locked->last_working_day->format('Y-m-d');

            if ($old === $lastDay && EmployeeWriter::auditValue($employee->last_working_day) === $lastDay) {
                return $locked->refresh(); // nothing to change
            }

            $this->end->apply($employee, $lastDay, $by);

            $locked->setAttribute('last_working_day', $lastDay);
            $locked->save();

            if ($settlement !== null) {
                $settlement->month = substr($lastDay, 0, 7);
                $settlement->stale = true;
                $settlement->save();
            }

            Audit::record('exit', 'Exit last working day changed', $locked, [
                ...ExitGuards::context($locked, $employee),
                'old' => ['last_working_day' => $old],
                'attributes' => ['last_working_day' => $lastDay],
                'settlement_stale' => $settlement !== null,
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }
}
