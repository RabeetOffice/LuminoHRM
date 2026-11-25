<?php

namespace Database\Factories;

use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active paid type with a unique name, 10 days a year, no carry-forward, colour sky. The four defaults (Annual,
 * Sick, Casual, Unpaid) are seeded by a migration: use the test helper leaveType('Annual') to get one of them.
 * is_system is not mass assignable on the model; a factory writes past mass assignment, so the definition may set it.
 *
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Leave '.fake()->unique()->numerify('####'),
            'yearly_quota' => 10,
            'is_paid' => true,
            'carry_forward' => false,
            'carry_forward_cap' => 0,
            'is_active' => true,
            'is_system' => false,
            'colour' => 'sky',
            'sort_order' => 10,
        ];
    }

    public function unpaid(): static
    {
        return $this->state(['is_paid' => false, 'yearly_quota' => 0]);
    }
}
