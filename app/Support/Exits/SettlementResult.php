<?php

namespace App\Support\Exits;

use App\Models\FinalSettlement;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayResult;

/**
 * One settlement as SettlementCalculator worked it out. No logic beyond shaping.
 *
 *   $settlement->forceFill($result->attributes())->save();
 *
 * figures: the payroll-line money columns of final_settlements (FinalSettlement::FIGURES, net signed) plus
 * encashment_days (halves), encashment_amount and loan_shortfall, all paisa except the halves. breakdown: exactly
 * PayResult::breakdown (its totals equal the figures) plus the `settlement` key (plan "Stored settlement breakdown").
 * loanRecovered: loan id => paisa this settlement recovers (0 included); loanRemaining: loan id => what was left.
 */
final readonly class SettlementResult
{
    /** The columns compared at approval ("Figures changed since the last calculation"). */
    public const COMPARED = [...FinalSettlement::FIGURES, 'encashment_days', 'encashment_amount', 'loan_shortfall'];

    /**
     * @param  array<string, int>  $figures
     * @param  array<string, mixed>  $breakdown
     * @param  array<int, int>  $loanRecovered
     * @param  array<int, int>  $loanRemaining
     * @param  list<array{key: string, message: string}>  $warnings
     * @param  list<string>  $blocking
     */
    public function __construct(
        public array $figures,
        public array $breakdown,
        public array $loanRecovered,
        public array $loanRemaining,
        public array $warnings,
        public array $blocking,
        public PayResult $pay,
    ) {}

    /** @return array<string, mixed> the final_settlements columns this result fills */
    public function attributes(): array
    {
        return [...$this->figures, 'breakdown' => $this->breakdown];
    }

    /**
     * The figures for an audit entry: paisa plus a formatted copy of each money figure.
     *
     * @param  array<string, int|null>  $figures
     * @return array<string, int|string|null>
     */
    public static function audited(array $figures): array
    {
        $out = [];

        foreach (self::COMPARED as $key) {
            $value = $figures[$key] ?? null;
            $out[$key] = $value === null ? null : (int) $value;

            if ($key !== 'encashment_days') {
                $out[$key.'_formatted'] = $value === null ? null : Money::format((int) $value);
            }
        }

        return $out;
    }

    /**
     * The compared figures as stored on a settlement row.
     *
     * @return array<string, int>
     */
    public static function storedFigures(FinalSettlement $settlement): array
    {
        $out = [];

        foreach (self::COMPARED as $key) {
            $out[$key] = (int) $settlement->getAttribute($key);
        }

        return $out;
    }
}
