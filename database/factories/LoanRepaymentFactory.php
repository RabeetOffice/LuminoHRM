<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\LoanRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A manual (cash) repayment of Rs 5,000 in 2026-11 on a new loan. Amounts in paisa.
 *
 * @extends Factory<LoanRepayment>
 */
class LoanRepaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'payroll_item_id' => null,
            'amount' => 500_000,
            'month' => '2026-11',
            'note' => 'Cash',
        ];
    }
}
