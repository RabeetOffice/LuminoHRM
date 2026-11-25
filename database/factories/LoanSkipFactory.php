<?php

namespace Database\Factories;

use App\Models\Loan;
use App\Models\LoanSkip;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * November 2026 skipped on a new loan.
 *
 * @extends Factory<LoanSkip>
 */
class LoanSkipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'month' => '2026-11',
            'reason' => 'Family wedding',
        ];
    }
}
