<?php

namespace Database\Factories;

use App\Models\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A holiday on a unique date in 2026 to 2028.
 *
 * @extends Factory<Holiday>
 */
class HolidayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Holiday '.fake()->numerify('###'),
            'date' => fake()->unique()->dateTimeBetween('2026-01-01', '2028-12-31')->format('Y-m-d'),
        ];
    }
}
