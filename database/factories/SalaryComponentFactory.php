<?php

namespace Database\Factories;

use App\Models\SalaryComponent;
use App\Models\SalaryProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A taxable allowance "House rent" of Rs 24,000 a month on a new profile. Amounts in paisa.
 *
 * @extends Factory<SalaryComponent>
 */
class SalaryComponentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'salary_profile_id' => SalaryProfile::factory(),
            'name' => 'House rent',
            'type' => 'allowance',
            'amount' => 2_400_000,
            'is_taxable' => true,
            'sort_order' => 0,
        ];
    }

    /** A fixed deduction (never taxable). */
    public function deduction(string $name = 'Provident fund', int $amount = 300_000): static
    {
        return $this->state(['name' => $name, 'type' => 'deduction', 'amount' => $amount, 'is_taxable' => false]);
    }
}
