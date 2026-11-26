<?php

namespace App\Actions\Exits;

use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\SettlementAccess;
use App\Support\Exits\SettlementCalculator;
use App\Support\Exits\SettlementResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR recalculates a settlement draft from fresh inputs (attendance, leave, profile, loans, lines, override): the
 * figures and breakdown are stored, `stale` is cleared and calculated_at set. Audited "Settlement recalculated" with
 * the figures old and new (paisa + formatted).
 *
 * Also the shared lock-and-check for every draft edit (lockDraft) and the store step (store): employee row FOR UPDATE,
 * then the resignation row, then the settlement row FOR UPDATE; never PayrollLock on its own settlement row.
 * Refusals [settlement]: SettlementAccess::OWN / PRIVILEGED, FROZEN; no rights = 403.
 */
class RecalculateSettlement
{
    public const FROZEN = 'This settlement is approved and can no longer change.';

    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, FinalSettlement $settlement): FinalSettlement
    {
        return DB::transaction(function () use ($by, $settlement): FinalSettlement {
            [$employee, $resignation, $locked] = self::lockDraft($by, $settlement);

            $old = SettlementResult::storedFigures($locked);
            $result = self::store($locked);

            Audit::record('exit', 'Settlement recalculated', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'month' => $locked->month,
                'old' => SettlementResult::audited($old),
                'attributes' => SettlementResult::audited($result->figures),
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }

    /**
     * Locks employee -> resignation -> settlement (FOR UPDATE) and refuses anything but a draft this user may edit.
     * Call inside the write transaction.
     *
     * @return array{0: Employee, 1: Resignation, 2: FinalSettlement}
     *
     * @throws AuthorizationException|ValidationException
     */
    public static function lockDraft(User $by, FinalSettlement $settlement): array
    {
        $employee = ExitGuards::lockEmployee((int) $settlement->employee_id);
        $resignation = ExitGuards::lockResignation((int) $settlement->resignation_id, $employee);
        $locked = ExitGuards::lockSettlement((int) $resignation->id);

        if ($locked === null || (int) $locked->id !== (int) $settlement->id) {
            throw new AuthorizationException;
        }

        $locked->setRelation('resignation', $resignation);
        self::authorize($by, $resignation);

        if ($locked->status !== 'draft') {
            ExitGuards::refuse(self::FROZEN, 'settlement');
        }

        return [$employee, $resignation, $locked];
    }

    /**
     * The named refusals (own, an Admin / HR person's) as [settlement] messages, anything else 403.
     *
     * @throws AuthorizationException|ValidationException
     */
    public static function authorize(User $by, Resignation $resignation): void
    {
        $refusal = SettlementAccess::editRefusal($by, $resignation);

        if ($refusal !== null) {
            ExitGuards::refuse($refusal, 'settlement');
        }

        if (! SettlementAccess::mayEditFor($by, $resignation)) {
            throw new AuthorizationException;
        }
    }

    /** Recalculates the locked draft and saves the result (figures, breakdown, stale false, calculated_at). */
    public static function store(FinalSettlement $locked): SettlementResult
    {
        $result = SettlementCalculator::calculate($locked);

        $locked->forceFill([
            ...$result->attributes(),
            'stale' => false,
            'calculated_at' => now(),
        ])->save();

        return $result;
    }
}
