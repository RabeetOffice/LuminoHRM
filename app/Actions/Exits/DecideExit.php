<?php

namespace App\Actions\Exits;

use App\Actions\Employees\EmployeeWriter;
use App\Actions\Employees\EmploymentEnd;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approves or rejects an exit at the step the deciding user is allowed to decide (ExitRouting::decidableStage): the
 * manager step (the exit then waits for HR) or the final step.
 *
 * Final approval, in one transaction (employee row, then the resignation row): the exit becomes approved, the employee's
 * last working day is set through EmploymentEnd (payroll lock check, attendance marks, leave trim; a refusal rolls
 * everything back), then the clearance rows are created (SeedClearance). A final approver may also decide an exit still
 * waiting for the manager; its manager_skipped flag is then set. The sole active Admin may decide their own exit
 * (own_decision, audited).
 *
 * Reject at either step needs a note of 1 to 500 characters. The status stays active until the nightly job.
 */
class DecideExit
{
    public const NO_NOTE = 'Enter a note to reject this exit.';

    public const NOTE_TOO_LONG = 'The note can be at most 500 characters.';

    public function __construct(
        private readonly EmploymentEnd $end,
        private readonly SeedClearance $seed,
    ) {}

    /** @return list<string> sentences for the person who decided (hand-corrected attendance days whose leave was trimmed) */
    public function notices(): array
    {
        return $this->end->notices();
    }

    /**
     * Approves at the step the user may decide: manager (the exit moves to HR) or final.
     *
     * @throws ValidationException
     */
    public function approve(User $by, Resignation $resignation): Resignation
    {
        return DB::transaction(function () use ($by, $resignation): Resignation {
            [$employee, $locked, $routing, $stage] = $this->lockAndRoute($by, $resignation);

            if ($stage === ExitRouting::STAGE_MANAGER) {
                $locked->status = ExitStates::MANAGER_APPROVED;
                $locked->manager_decided_by = $by->id;
                $locked->manager_decided_at = now();
                $locked->save();

                Audit::record('exit', 'Exit approved by manager', $locked, [
                    ...ExitGuards::context($locked, $employee),
                    'step' => ExitRouting::STAGE_MANAGER,
                    'last_working_day' => $locked->last_working_day->format('Y-m-d'),
                ], $by);

                return $locked->refresh();
            }

            $skipped = $locked->status === ExitStates::PENDING;
            $own = $routing->isOwn($by, $locked);
            $oldDay = EmployeeWriter::auditValue($employee->last_working_day);
            $day = $locked->last_working_day->format('Y-m-d');

            $locked->status = ExitStates::APPROVED;
            $locked->manager_skipped = $locked->manager_skipped || $skipped;
            $locked->final_decided_by = $by->id;
            $locked->final_decided_at = now();
            $locked->own_decision = $own;
            $locked->save();

            $this->end->apply($employee, $day, $by);
            $this->seed->for($locked);

            Audit::record('exit', 'Exit approved', $locked, [
                ...ExitGuards::context($locked, $employee),
                'step' => ExitRouting::STAGE_FINAL,
                'own' => $own,
                'manager_skipped' => $locked->manager_skipped,
                'old' => ['last_working_day' => $oldDay],
                'attributes' => ['last_working_day' => $day],
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Rejects at the step the user may decide. The note is shown to the employee.
     *
     * @throws ValidationException
     */
    public function reject(User $by, Resignation $resignation, string $note): Resignation
    {
        $note = trim($note);

        if ($note === '') {
            ExitGuards::refuse(self::NO_NOTE, 'note');
        }

        if (mb_strlen($note) > 500) {
            ExitGuards::refuse(self::NOTE_TOO_LONG, 'note');
        }

        return DB::transaction(function () use ($by, $resignation, $note): Resignation {
            [$employee, $locked, $routing, $stage] = $this->lockAndRoute($by, $resignation);

            if ($stage === ExitRouting::STAGE_MANAGER) {
                $locked->manager_decided_by = $by->id;
                $locked->manager_decided_at = now();
            } else {
                $locked->manager_skipped = $locked->manager_skipped || $locked->status === ExitStates::PENDING;
                $locked->final_decided_by = $by->id;
                $locked->final_decided_at = now();
                $locked->own_decision = $routing->isOwn($by, $locked);
            }

            $locked->status = ExitStates::REJECTED;
            $locked->decision_note = $note;
            $locked->save();

            Audit::record('exit', 'Exit rejected', $locked, [
                ...ExitGuards::context($locked, $employee),
                'step' => $stage,
                'note' => $note,
                'own' => $locked->own_decision,
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Locks the employee, then the resignation, re-reads the state and works out the step the user may decide.
     *
     * @return array{0: Employee, 1: Resignation, 2: ExitRouting, 3: string} the locked employee, the locked exit, the routing, the step
     */
    private function lockAndRoute(User $by, Resignation $resignation): array
    {
        $employee = ExitGuards::lockEmployee((int) $resignation->employee_id);
        $locked = ExitGuards::lockResignation((int) $resignation->id, $employee);

        ExitGuards::assertNotEnded($locked);

        $routing = new ExitRouting;
        $stage = $routing->decidableStage($by, $locked);

        if ($stage === null) {
            ExitGuards::refuse($routing->finalRefusal($by, $locked) ?? ExitRouting::NOT_WAITING_MESSAGE);
        }

        return [$employee, $locked, $routing, $stage];
    }
}
