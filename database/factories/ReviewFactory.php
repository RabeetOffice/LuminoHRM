<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A review waiting on the manager, with no score rows (reviewRow() in tests/Pest.php adds the KPI copies).
 *
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_cycle_id' => ReviewCycle::factory(),
            'employee_id' => Employee::factory(),
            'reviewer_id' => null,
            'status' => 'manager_review',
        ];
    }
}
