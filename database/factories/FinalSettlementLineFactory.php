<?php

namespace Database\Factories;

use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A taxable bonus of Rs 10,000 (paisa) on a new draft settlement.
 *
 * @extends Factory<FinalSettlementLine>
 */
class FinalSettlementLineFactory extends Factory
{
    public function definition(): array
    {
        return [
            'final_settlement_id' => FinalSettlement::factory(),
            'kind' => 'bonus',
            'type' => 'addition',
            'label' => 'Bonus',
            'amount' => 1_000_000,
            'is_taxable' => true,
        ];
    }
}
