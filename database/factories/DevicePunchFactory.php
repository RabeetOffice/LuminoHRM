<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DevicePunch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A punch from an unknown machine user (employee_id null) on a new device. punch_date is derived by the model.
 * For a punch by an employee use the punch() test helper (tests/Pest.php).
 *
 * @extends Factory<DevicePunch>
 */
class DevicePunchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'device_user_id' => fake()->unique()->numerify('9#####'),
            'employee_id' => null,
            'punch_time' => sprintf('2026-11-24 09:%02d:%02d', fake()->numberBetween(0, 59), fake()->numberBetween(0, 59)),
            'verify_type' => '1',
            'status_code' => '0',
            'source' => 'device',
        ];
    }
}
