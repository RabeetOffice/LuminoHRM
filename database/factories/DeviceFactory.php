<?php

namespace Database\Factories;

use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Front door',
            'serial_no' => 'TEST'.fake()->unique()->numerify('##########'),
            'is_active' => true,
            'last_seen_at' => null,
            'last_stamp' => null,
            'last_punch_at' => null,
        ];
    }
}
