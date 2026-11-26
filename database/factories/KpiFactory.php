<?php

namespace Database\Factories;

use App\Models\Designation;
use App\Models\Employee;
use App\Models\Kpi;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An active KPI of weight 20 for a new designation. Use forEmployee() for a personal KPI, or pass both ids explicitly
 * (designation_id => null) to choose.
 *
 * @extends Factory<Kpi>
 */
class KpiFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'KPI '.fake()->unique()->numerify('####'),
            'description' => null,
            'weight' => 20,
            'is_active' => true,
            'designation_id' => Designation::factory(),
            'employee_id' => null,
        ];
    }

    public function forEmployee(?Employee $employee = null): static
    {
        return $this->state(fn () => [
            'designation_id' => null,
            'employee_id' => $employee !== null ? $employee->id : Employee::factory(),
        ]);
    }
}
