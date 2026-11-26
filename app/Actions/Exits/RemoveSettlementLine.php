<?php

namespace App\Actions\Exits;

use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Exits\SettlementResult;
use App\Support\Payroll\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR removes a line from a settlement draft; the draft is recalculated. Locks as RecalculateSettlement::lockDraft,
 * then the line row. Audited "Settlement line removed" (the line, figures old and new).
 */
class RemoveSettlementLine
{
    /** @throws AuthorizationException|ValidationException */
    public function handle(User $by, FinalSettlementLine $line): void
    {
        $settlement = FinalSettlement::query()->findOrFail($line->final_settlement_id);

        DB::transaction(function () use ($by, $settlement, $line): void {
            [$employee, $resignation, $locked] = RecalculateSettlement::lockDraft($by, $settlement);

            $row = FinalSettlementLine::query()->where('final_settlement_id', $locked->id)->whereKey($line->id)->lockForUpdate()->first();

            if ($row === null) {
                return; // already removed
            }

            $old = SettlementResult::storedFigures($locked);
            $row->delete();
            $result = RecalculateSettlement::store($locked);

            Audit::record('exit', 'Settlement line removed', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'line' => ['id' => $row->id, 'kind' => $row->kind, 'type' => $row->type, 'label' => $row->label,
                    'amount' => (int) $row->amount, 'amount_formatted' => Money::format((int) $row->amount), 'is_taxable' => (bool) $row->is_taxable],
                'old' => SettlementResult::audited($old),
                'attributes' => SettlementResult::audited($result->figures),
            ], $by);
        }, attempts: 3);
    }
}
