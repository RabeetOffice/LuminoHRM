<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A 2026 balance of 14 allocated days, no adjustment, nothing used before go-live, for a new employee and a new paid
 * type. Pass employee_id and leave_type_id for existing ones (one row per employee, type and year).
 *
 * @extends Factory<LeaveBalance>
 */
class LeaveBalanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'year' => 2026,
            'allocated' => 14,
            'adjustment' => 0,
            'opening_used' => 0,
        ];
    }
}
