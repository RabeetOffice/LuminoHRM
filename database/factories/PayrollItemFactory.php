<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A line of a new draft run for a new employee: a full 30-day month of basic Rs 60,000 and nothing else. Amounts in
 * paisa; no breakdown.
 *
 * @extends Factory<PayrollItem>
 */
class PayrollItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'payroll_run_id' => PayrollRun::factory(),
            'employee_id' => Employee::factory(),
            'days_paid' => 30,
            'basic' => 6_000_000,
            'gross' => 6_000_000,
            'taxable_pay' => 6_000_000,
            'net' => 6_000_000,
        ];
    }
}
