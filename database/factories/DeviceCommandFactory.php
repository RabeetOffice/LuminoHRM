<?php

namespace Database\Factories;

use App\Models\Device;
use App\Models\DeviceCommand;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceCommand>
 */
class DeviceCommandFactory extends Factory
{
    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'command' => 'INFO',
            'status' => 'queued',
            'sent_at' => null,
            'result' => null,
            'created_by' => null,
        ];
    }
}
