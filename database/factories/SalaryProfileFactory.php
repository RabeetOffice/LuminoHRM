<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\SalaryProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A profile in force from 2026-01-01 for a new employee: basic Rs 60,000, no tax, no components. Amounts in paisa.
 * For the usual test profile with allowances, a deduction and 5% tax use the salaryProfile() Pest helper.
 *
 * @extends Factory<SalaryProfile>
 */
class SalaryProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'effective_from' => '2026-01-01',
            'basic' => 6_000_000,
            'tax_mode' => 'none',
            'tax_value' => 0,
            'tax_base' => null,
            'note' => null,
        ];
    }
}
