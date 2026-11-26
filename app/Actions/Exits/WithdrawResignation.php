<?php

namespace App\Actions\Exits;

use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The employee withdraws their own resignation while it is pending or manager_approved. Never on someone's behalf (HR
 * rejects instead), never a termination, and not after the final approval ("ask HR": HR cancels an approved exit).
 */
class WithdrawResignation
{
    public const TOO_LATE = 'This resignation can no longer be withdrawn; ask HR.';

    /**
     * @throws ValidationException
     * @throws AuthorizationException not the employee's own resignation (or a termination)
     */
    public function handle(User $by, Resignation $resignation): Resignation
    {
        if ($by->employee_id === null || (int) $by->employee_id !== (int) $resignation->employee_id || $resignation->type !== 'resignation') {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($by, $resignation): Resignation {
            $employee = ExitGuards::lockEmployee((int) $resignation->employee_id);
            $locked = ExitGuards::lockResignation((int) $resignation->id, $employee);

            ExitGuards::assertNotEnded($locked);

            if ($locked->status === ExitStates::APPROVED) {
                ExitGuards::refuse(self::TOO_LATE);
            }

            $locked->status = ExitStates::WITHDRAWN;
            $locked->withdrawn_at = now();
            $locked->save();

            Audit::record('exit', 'Resignation withdrawn', $locked, [
                ...ExitGuards::context($locked, $employee),
                'last_working_day' => $locked->last_working_day->format('Y-m-d'),
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }
}
