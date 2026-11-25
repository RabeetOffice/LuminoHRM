<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending one-day request for 2026-12-01 (a Tuesday) of a new paid type, for a new employee. No leave days: those
 * are written at final approval (or by the test helper approvedLeave()).
 *
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'leave_type_id' => LeaveType::factory(),
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-01',
            'is_half_day' => false,
            'half' => null,
            'days' => 1,
            'reason' => 'Family matter.',
            'status' => 'pending',
        ];
    }
}
