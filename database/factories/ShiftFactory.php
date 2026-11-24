<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A valid, non-default shift (09:00 to 18:00, 15 minutes' grace, half day under 4 hours, Saturday and Sunday off).
 * The default shift ("General") already exists from the data migration; use Shift::makeDefault() to change it.
 *
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Shift '.fake()->unique()->numerify('######'),
            'start_time' => '09:00',
            'end_time' => '18:00',
            'grace_minutes' => 15,
            'half_day_hours' => 4,
            'weekly_offs' => ['saturday', 'sunday'],
            'is_default' => false,
        ];
    }
}
