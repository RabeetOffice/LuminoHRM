<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeContact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeContact>
 */
class EmployeeContactFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'address' => fake()->streetAddress().', Lahore',
            'emergency_name' => fake()->name(),
            'emergency_phone' => '0300'.fake()->numerify('#######'),
        ];
    }
}
