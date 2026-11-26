<?php

namespace App\Actions\Exits;

use App\Models\FinalSettlement;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\SettlementResult;
use App\Support\Payroll\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR overrides the tax of a settlement draft (whole rupees, 0 to 10,000,000, with a reason up to 255 characters), or
 * clears the override ($rupees null). The draft is recalculated. Locks as RecalculateSettlement::lockDraft. Audited
 * "Settlement tax overridden" (old and new override, figures old and new).
 */
class OverrideSettlementTax
{
    public const NO_REASON = 'Enter a reason for the tax override.';

    public const BAD_AMOUNT = 'Enter a tax amount between 0 and 10,000,000 rupees.';

    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, FinalSettlement $settlement, ?int $rupees, ?string $reason): FinalSettlement
    {
        $reason = $reason === null ? null : trim($reason);

        if ($rupees !== null) {
            if ($rupees < 0 || $rupees > SaveSettlementLine::MAX_RUPEES) {
                ExitGuards::refuse(self::BAD_AMOUNT, 'amount');
            }

            if ($reason === null || $reason === '' || mb_strlen($reason) > 255) {
                ExitGuards::refuse(self::NO_REASON, 'reason');
            }
        }

        return DB::transaction(function () use ($by, $settlement, $rupees, $reason): FinalSettlement {
            [$employee, $resignation, $locked] = RecalculateSettlement::lockDraft($by, $settlement);

            $oldOverride = $locked->tax_overridden ? (int) $locked->tax_override : null;
            $oldReason = $locked->tax_overridden ? $locked->tax_override_reason : null;
            $old = SettlementResult::storedFigures($locked);

            $locked->forceFill([
                'tax_overridden' => $rupees !== null,
                'tax_override' => $rupees === null ? null : Money::rupees($rupees),
                'tax_override_reason' => $rupees === null ? null : $reason,
            ])->save();

            $result = RecalculateSettlement::store($locked);

            Audit::record('exit', 'Settlement tax overridden', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'old' => [
                    'tax_override' => $oldOverride,
                    'tax_override_formatted' => $oldOverride === null ? null : Money::format($oldOverride),
                    'reason' => $oldReason,
                    ...SettlementResult::audited($old),
                ],
                'attributes' => [
                    'tax_override' => $rupees === null ? null : Money::rupees($rupees),
                    'tax_override_formatted' => $rupees === null ? null : Money::format(Money::rupees($rupees)),
                    'reason' => $rupees === null ? null : $reason,
                    ...SettlementResult::audited($result->figures),
                ],
            ], $by);

            return $locked->refresh();
        }, attempts: 3);
    }
}
