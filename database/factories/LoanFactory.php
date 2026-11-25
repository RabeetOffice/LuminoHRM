<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Loan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active loan of Rs 50,000 recovered at Rs 5,000 a month from 2026-11, for a new employee. Amounts in paisa.
 *
 * @extends Factory<Loan>
 */
class LoanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'loan',
            'amount' => 5_000_000,
            'installment' => 500_000,
            'start_month' => '2026-11',
            'opening_repaid' => 0,
            'status' => 'active',
            'note' => null,
        ];
    }
}
