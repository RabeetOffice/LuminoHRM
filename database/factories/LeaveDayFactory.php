<?php

namespace Database\Factories;

use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A full leave day on 2026-12-01 of a new request; the employee and type follow the request.
 *
 * @extends Factory<LeaveDay>
 */
class LeaveDayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'leave_request_id' => LeaveRequest::factory(),
            'employee_id' => fn (array $a) => LeaveRequest::query()->whereKey($a['leave_request_id'])->value('employee_id'),
            'leave_type_id' => fn (array $a) => LeaveRequest::query()->whereKey($a['leave_request_id'])->value('leave_type_id'),
            'date' => '2026-12-01',
            'amount' => 1,
            'half' => 'full',
        ];
    }
}
