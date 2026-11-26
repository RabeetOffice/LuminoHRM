<?php

namespace App\Actions\Exits;

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitNotice;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR (exit.clearance) records a resignation or a termination for an employee. The exit starts at the HR step
 * (manager_approved, manager_skipped). With $approveNow the final approval runs in the same transaction when the
 * recorder may decide it (DecideExit::approve: sets the last working day, creates the clearance rows).
 *
 * Rules: never for oneself ("You cannot record an exit for yourself."); an Admin or HR person's only by an Admin; type
 * resignation or termination; reason 1 to 1,000 characters; submitted on a real date, not in the future; last working day
 * not before the joining date and at most 365 days ahead (a past day is allowed: the payroll lock check decides, when the
 * day is set at approval). One open exit per person.
 */
class RecordExit
{
    public const SELF = 'You cannot record an exit for yourself.';

    public const PRIVILEGED = 'Only an Admin can record the exit of an Admin or HR person.';

    public const BAD_TYPE = 'Choose resignation or termination.';

    public const FUTURE_SUBMITTED = 'The submitted date cannot be in the future.';

    public function __construct(private readonly DecideExit $decide) {}

    /**
     * @throws ValidationException
     * @throws AuthorizationException the user lacks exit.clearance
     */
    public function handle(User $by, Employee $employee, string $type, string $lastDay, string $reason, string $submittedOn, bool $approveNow): Resignation
    {
        if (! $by->can('exit.clearance')) {
            throw new AuthorizationException;
        }

        if (! in_array($type, (array) config('hrm.exit.types'), true)) {
            ExitGuards::refuse(self::BAD_TYPE, 'type');
        }

        $reason = trim($reason);

        if ($reason === '') {
            ExitGuards::refuse(SubmitResignation::NO_REASON, 'reason');
        }

        if (mb_strlen($reason) > 1000) {
            ExitGuards::refuse(SubmitResignation::REASON_TOO_LONG, 'reason');
        }

        return DB::transaction(function () use ($by, $employee, $type, $lastDay, $reason, $submittedOn, $approveNow): Resignation {
            $locked = ExitGuards::lockEmployee((int) $employee->id);
            $routing = new ExitRouting;

            if ($by->employee_id !== null && (int) $by->employee_id === (int) $locked->id) {
                ExitGuards::refuse(self::SELF);
            }

            if (! $routing->mayRecordFor($by, $locked)) {
                ExitGuards::refuse(self::PRIVILEGED);
            }

            if (Resignation::query()->where('employee_id', $locked->id)->whereIn('status', ExitStates::open())->exists()) {
                ExitGuards::refuse("{$locked->full_name} already has an open exit.");
            }

            if (! ExitGuards::isDate($submittedOn)) {
                ExitGuards::refuse(ExitGuards::BAD_DATE, 'submitted_on');
            }

            if ($submittedOn > ExitGuards::today()) {
                ExitGuards::refuse(self::FUTURE_SUBMITTED, 'submitted_on');
            }

            ExitGuards::assertLastDay($locked, $lastDay, minToday: false);

            $resignation = Resignation::query()->create([
                'employee_id' => $locked->id,
                'type' => $type,
                'submitted_on' => $submittedOn,
                'last_working_day' => $lastDay,
                'reason' => $reason,
                'notice_period_days' => (int) settings('payroll.notice_period_days'),
                'status' => ExitStates::MANAGER_APPROVED,
                'manager_skipped' => true,
                'recorded_by' => $by->id,
            ]);

            Audit::record('exit', 'Exit recorded', $resignation, [
                ...ExitGuards::context($resignation, $locked),
                'last_working_day' => $lastDay,
                'reason' => $reason,
                'submitted_on' => $submittedOn,
                'notice_given' => ExitNotice::given($submittedOn, $lastDay),
                'notice_required' => $resignation->notice_period_days,
                'approve_now' => $approveNow,
            ], $by);

            $resignation->setRelation('employee', $locked);

            if ($approveNow && $routing->mayDecideFinal($by, $resignation)) {
                return $this->decide->approve($by, $resignation);
            }

            return $resignation->refresh();
        }, attempts: 3);
    }

    /** @return list<string> */
    public function notices(): array
    {
        return $this->decide->notices();
    }
}
