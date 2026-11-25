<?php

namespace Database\Factories;

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A taxable one-off addition "Bonus" of Rs 5,000 on a new line. Amounts in paisa.
 *
 * @extends Factory<PayrollAdjustment>
 */
class PayrollAdjustmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payroll_item_id' => PayrollItem::factory(),
            'label' => 'Bonus',
            'type' => 'addition',
            'amount' => 500_000,
            'is_taxable' => true,
        ];
    }
}
