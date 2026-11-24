<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeBankAccount>
 */
class EmployeeBankAccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'bank_name' => fake()->randomElement(['Meezan Bank', 'HBL', 'UBL', 'Allied Bank']),
            'account_title' => fake()->name(),
            'account_number' => fake()->numerify('################'),
        ];
    }
}
