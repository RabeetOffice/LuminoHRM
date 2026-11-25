<?php

namespace Database\Factories;

use App\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft run of a unique month between 2020 and 2025 with no lines and zero totals. Use the payrollRun() Pest helper
 * for a given month and status.
 *
 * @extends Factory<PayrollRun>
 */
class PayrollRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'month' => fake()->unique()->dateTimeBetween('2020-01-01', '2025-12-31')->format('Y-m'),
            'status' => 'draft',
            'generated_at' => now(),
        ];
    }

    /** Approved (locked). */
    public function approved(): static
    {
        return $this->state(['status' => 'approved', 'approved_at' => now()]);
    }

    /** Paid (locked). */
    public function paid(): static
    {
        return $this->state(['status' => 'paid', 'approved_at' => now(), 'paid_at' => now()]);
    }
}
