<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\OpeningBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tax year 2026-27 to date for a new employee: Rs 12,000 tax deducted on Rs 240,000 taxable income. Amounts in paisa.
 *
 * @extends Factory<OpeningBalance>
 */
class OpeningBalanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'tax_year' => 2026,
            'tax_deducted' => 1_200_000,
            'taxable_income' => 24_000_000,
            'note' => null,
        ];
    }
}
