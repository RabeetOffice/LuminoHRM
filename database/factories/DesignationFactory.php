<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Designation>
 */
class DesignationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Designation '.fake()->unique()->numerify('######'),
            'department_id' => Department::factory(),
        ];
    }
}
