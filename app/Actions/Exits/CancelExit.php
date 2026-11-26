<?php

namespace App\Actions\Exits;

use App\Actions\Employees\EmploymentEnd;
use App\Models\FinalSettlementLine;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR cancels an approved exit (reason required) while its settlement is not approved and the person has not yet left.
 * The employee's last working day is cleared through EmploymentEnd (payroll lock check applies); the draft settlement and
 * its lines are deleted (audited); clearance rows stay as history. Leave already trimmed is not restored.
 *
 * An exit that is still pending is rejected, not cancelled.
 */
class CancelExit
{
    public const NOT_APPROVED = 'Only an approved exit can be cancelled; reject it instead.';

    public const NO_REASON = 'Enter a reason.';

    public const REASON_TOO_LONG = 'The reason can be at most 500 characters.';

    /** Shown to the person who cancelled (flash info). */
    public const LEAVE_STAYS = 'Leave cancelled for the old last working day stays cancelled; apply again if needed.';

    public function __construct(private readonly EmploymentEnd $end) {}

    public static function alreadyLeft(string $name, string $status): string
    {
        return "{$name} has already left (status ".ucfirst($status).'), so the exit cannot be cancelled.';
    }

    /**
     * @throws ValidationException
     * @throws AuthorizationException the user may not administer this exit
     */
    public function handle(User $by, Resignation $resignation, string $reason): Resignation
    {
        $reason = trim($reason);

        if ($reason === '') {
            ExitGuards::refuse(self::NO_REASON, 'reason');
        }

        if (mb_strlen($reason) > 500) {
            ExitGuards::refuse(self::REASON_TOO_LONG, 'reason');
        }

        return DB::transaction(function () use ($by, $resignation, $reason): Resignation {
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

            if (in_array($employee->status, Leavers::STATUSES, true) || $locked->status_applied_at !== null) {
                ExitGuards::refuse(self::alreadyLeft($employee->full_name, (string) $employee->status));
            }

            $oldDay = $locked->last_working_day->format('Y-m-d');

            $this->end->apply($employee, null, $by);

            if ($settlement !== null) {
                $lines = FinalSettlementLine::query()->where('final_settlement_id', $settlement->id)->count();

                Audit::record('exit', 'Settlement draft deleted', $settlement, [
                    ...ExitGuards::context($locked, $employee),
                    'month' => $settlement->month,
                    'net' => $settlement->net,
                    'lines' => $lines,
                    'reason' => 'Exit cancelled',
                ], $by);

                FinalSettlementLine::query()->where('final_settlement_id', $settlement->id)->delete();
                $settlement->delete();
            }

            $locked->status = ExitStates::CANCELLED;
            $locked->cancelled_by = $by->id;
            $locked->cancelled_at = now();
            $locked->cancel_reason = $reason;
            $locked->save();

            Audit::record('exit', 'Exit cancelled', $locked, [
                ...ExitGuards::context($locked, $employee),
                'reason' => $reason,
                'old' => ['last_working_day' => $oldDay],
                'attributes' => ['last_working_day' => null],
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }

    /** @return list<string> */
    public function notices(): array
    {
        return [...$this->end->notices(), self::LEAVE_STAYS];
    }
}
