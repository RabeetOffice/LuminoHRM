<?php

namespace Database\Factories;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Valid, unique employees: codes from a sequence (skipping any already taken), 13-digit CNICs, and a designation
 * that belongs to the employee's department. Pass department_id to keep the designation in that department.
 *
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        do {
            $n = ++self::$sequence;
            $code = sprintf('LD-%04d', $n);
        } while (Employee::query()->where('employee_code', $code)->exists());

        return [
            'employee_code' => $code,
            'device_user_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'photo_path' => null,
            'email' => "employee{$n}.".fake()->unique()->numerify('######').'@example.test',
            'phone' => null,
            'dob' => null,
            'gender' => null,
            'cnic' => '42201'.str_pad((string) $n, 8, '0', STR_PAD_LEFT),
            'department_id' => Department::factory(),
            // Resolved after department_id, so the pair belongs together.
            'designation_id' => fn (array $attributes) => Designation::factory()->create(['department_id' => $attributes['department_id']])->id,
            'manager_id' => null,
            'joining_date' => fake()->dateTimeBetween('-5 years', '-1 month')->format('Y-m-d'),
            'probation_end_date' => null,
            'employment_type' => 'full_time',
            'status' => 'active',
            'last_working_day' => null,
        ];
    }
}
