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
 * HR adds a line to a settlement draft and the draft is recalculated (design 5.5 "HR lines").
 *
 *   $save->handle($hr, $settlement, ['kind' => 'bonus', 'label' => 'Year-end bonus', 'amount' => 10000, 'is_taxable' => true]);
 *
 * $data: kind (bonus = addition, notice_shortfall = deduction, other = `type` addition | deduction), label (1 to 100
 * characters), amount in whole rupees (1 to 10,000,000), is_taxable (additions only; default true). At most 20 lines.
 * Locks as RecalculateSettlement::lockDraft. Audited "Settlement line added" (line, figures old and new).
 */
class SaveSettlementLine
{
    public const TOO_MANY = 'At most 20 lines.';

    public const NO_LABEL = 'Enter a label.';

    public const BAD_AMOUNT = 'Enter an amount between 1 and 10,000,000 rupees.';

    public const BAD_KIND = 'Choose a kind of line.';

    public const MAX_RUPEES = 10_000_000;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AuthorizationException|ValidationException
     */
    public function handle(User $by, FinalSettlement $settlement, array $data): FinalSettlementLine
    {
        [$kind, $type, $label, $amount, $taxable] = self::clean($data);

        return DB::transaction(function () use ($by, $settlement, $kind, $type, $label, $amount, $taxable): FinalSettlementLine {
            [$employee, $resignation, $locked] = RecalculateSettlement::lockDraft($by, $settlement);

            if (FinalSettlementLine::query()->where('final_settlement_id', $locked->id)->count() >= (int) config('hrm.exit.max_lines', 20)) {
                ExitGuards::refuse(self::TOO_MANY, 'line');
            }

            $old = SettlementResult::storedFigures($locked);

            $line = FinalSettlementLine::query()->create([
                'final_settlement_id' => $locked->id,
                'kind' => $kind,
                'type' => $type,
                'label' => $label,
                'amount' => $amount,
                'is_taxable' => $taxable,
                'created_by' => $by->id,
            ]);

            $result = RecalculateSettlement::store($locked);

            Audit::record('exit', 'Settlement line added', $resignation, [
                ...ExitGuards::context($resignation, $employee),
                'settlement' => $locked->id,
                'line' => ['id' => $line->id, 'kind' => $kind, 'type' => $type, 'label' => $label, 'amount' => $amount,
                    'amount_formatted' => Money::format($amount), 'is_taxable' => $taxable],
                'old' => SettlementResult::audited($old),
                'attributes' => SettlementResult::audited($result->figures),
            ], $by);

            return $line;
        }, attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string, 2: string, 3: int, 4: bool} kind, type, label, amount (paisa), taxable
     *
     * @throws ValidationException
     */
    private static function clean(array $data): array
    {
        $kind = (string) ($data['kind'] ?? '');

        if (! in_array($kind, (array) config('hrm.exit.line_kinds'), true)) {
            ExitGuards::refuse(self::BAD_KIND, 'kind');
        }

        $type = match ($kind) {
            'bonus' => 'addition',
            'notice_shortfall' => 'deduction',
            default => in_array($data['type'] ?? null, ['addition', 'deduction'], true) ? (string) $data['type'] : 'addition',
        };

        $label = trim((string) ($data['label'] ?? ''));

        if ($label === '' || mb_strlen($label) > 100) {
            ExitGuards::refuse(self::NO_LABEL, 'label');
        }

        $rupees = $data['amount'] ?? null;

        if (! is_int($rupees) && ! (is_string($rupees) && preg_match('/^\d{1,8}$/', $rupees) === 1)) {
            ExitGuards::refuse(self::BAD_AMOUNT, 'amount');
        }

        $rupees = (int) $rupees;

        if ($rupees < 1 || $rupees > self::MAX_RUPEES) {
            ExitGuards::refuse(self::BAD_AMOUNT, 'amount');
        }

        $taxable = $type === 'addition' && (bool) ($data['is_taxable'] ?? true);

        return [$kind, $type, $label, Money::rupees($rupees), $taxable];
    }
}
