<?php

namespace Database\Factories;

use App\Models\Review;
use App\Models\ReviewScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unrated KPI line of weight 20 with no source KPI (kpi_id null).
 *
 * @extends Factory<ReviewScore>
 */
class ReviewScoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'review_id' => Review::factory(),
            'kpi_id' => null,
            'name' => 'KPI line '.fake()->unique()->numerify('####'),
            'description' => null,
            'weight' => 20,
            'sort_order' => 0,
        ];
    }
}
