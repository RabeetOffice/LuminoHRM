<?php

namespace App\Actions\Exits;

use App\Models\Clearance;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitRouting;
use App\Support\Exits\ExitStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR ticks or unticks one clearance item of an approved exit (design 5.4), storing who and when. exit.clearance and
 * ExitRouting::mayManage (not one's own exit; an Admin / HR person's only by an Admin) else 403.
 *
 * Locks: employee FOR UPDATE, resignation FOR UPDATE, the settlement row FOR UPDATE (frozen check), the clearance row
 * FOR UPDATE. Refusals [clearance]: the exit ended (ExitGuards::ENDED), AUTOMATIC (the assets item), FROZEN (the
 * settlement is approved). No change = nothing written. Audited "Clearance item ticked" / "Clearance item unticked".
 */
class TickClearanceItem
{
    public const AUTOMATIC = 'Assets returned is ticked automatically when nothing is held.';

    public const FROZEN = 'Clearance is frozen once the settlement is approved.';

    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, Clearance $clearance, bool $cleared): Clearance
    {
        return DB::transaction(function () use ($by, $clearance, $cleared): Clearance {
            // Ids only (they never change); everything decided is read under the locks below.
            $resignationId = (int) $clearance->resignation_id;
            $employeeId = (int) Resignation::query()->whereKey($resignationId)->value('employee_id');

            $employee = ExitGuards::lockEmployee($employeeId);
            $resignation = ExitGuards::lockResignation($resignationId, $employee);

            if (! (new ExitRouting)->mayManage($by, $resignation)) {
                throw new AuthorizationException;
            }

            if (ExitStates::isEnded((string) $resignation->status)) {
                ExitGuards::refuse(ExitGuards::ENDED, 'clearance');
            }

            $settlement = ExitGuards::lockSettlement($resignationId);
            $item = Clearance::query()->whereKey($clearance->id)->lockForUpdate()->firstOrFail();

            if ($item->kind === 'assets') {
                ExitGuards::refuse(self::AUTOMATIC, 'clearance');
            }

            if ($settlement?->isFrozen()) {
                ExitGuards::refuse(self::FROZEN, 'clearance');
            }

            if ($item->isCleared() === $cleared) {
                return $item;
            }

            $item->forceFill($cleared
                ? ['cleared_at' => now(), 'cleared_by' => $by->id]
                : ['cleared_at' => null, 'cleared_by' => null])->save();

            Audit::record('exit', $cleared ? 'Clearance item ticked' : 'Clearance item unticked', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'clearance' => $item->id,
                'label' => $item->label,
                'kind' => $item->kind,
            ], $by);

            return $item->refresh();
        }, attempts: 3);
    }
}
