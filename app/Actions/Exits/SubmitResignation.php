<?php

namespace App\Actions\Exits;

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitNotice;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An employee resigns (My Resignation). Needs an employee record that is active or on probation, no open exit, a last
 * working day from today to 365 days ahead and not before the joining date, and a reason of up to 1,000 characters.
 * submitted_on is today (AttendanceClock::attendanceToday()); the current notice period is copied onto the row.
 *
 * The exit starts with the manager (pending), or goes straight to HR (manager_approved, manager_skipped) when
 * ExitRouting says the manager step is skipped. One transaction: employee row first, then the new row and the entry.
 */
class SubmitResignation
{
    public const NO_RECORD = 'Only employees with an employee record can resign.';

    public const NOT_ACTIVE = 'Only an active employee can resign.';

    public const ALREADY_OPEN = 'You already have an open resignation.';

    public const NO_REASON = 'Enter a reason.';

    public const REASON_TOO_LONG = 'The reason can be at most 1,000 characters.';

    /** @throws ValidationException */
    public function handle(User $by, string $lastDay, string $reason): Resignation
    {
        if ($by->employee_id === null) {
            ExitGuards::refuse(self::NO_RECORD);
        }

        $reason = trim($reason);

        if ($reason === '') {
            ExitGuards::refuse(self::NO_REASON, 'reason');
        }

        if (mb_strlen($reason) > 1000) {
            ExitGuards::refuse(self::REASON_TOO_LONG, 'reason');
        }

        $employeeId = (int) $by->employee_id;

        return DB::transaction(function () use ($by, $lastDay, $reason, $employeeId): Resignation {
            $employee = ExitGuards::lockEmployee($employeeId);

            if (! in_array($employee->status, ['active', 'probation'], true)) {
                ExitGuards::refuse(self::NOT_ACTIVE);
            }

            if (Resignation::query()->where('employee_id', $employee->id)->whereIn('status', ExitStates::open())->exists()) {
                ExitGuards::refuse(self::ALREADY_OPEN);
            }

            ExitGuards::assertLastDay($employee, $lastDay, minToday: true);

            $routing = new ExitRouting;
            $resignation = new Resignation([
                'employee_id' => $employee->id,
                'type' => 'resignation',
                'submitted_on' => ExitGuards::today(),
                'last_working_day' => $lastDay,
                'reason' => $reason,
                'notice_period_days' => (int) settings('payroll.notice_period_days'),
                'manager_skipped' => false,
            ]);
            $resignation->setRelation('employee', $employee);

            $skips = $routing->skipsManager($resignation);
            $resignation->status = $skips ? ExitStates::MANAGER_APPROVED : ExitStates::PENDING;
            $resignation->manager_skipped = $skips;
            $resignation->save();

            $given = ExitNotice::given($resignation->submitted_on->format('Y-m-d'), $lastDay);

            Audit::record('exit', 'Resignation submitted', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'last_working_day' => $lastDay,
                'reason' => $reason,
                'notice_given' => $given,
                'notice_required' => $resignation->notice_period_days,
                'manager_skipped' => $skips,
            ], $by);

            return $resignation->refresh();
        }, attempts: 3);
    }
}
