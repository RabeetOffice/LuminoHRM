<?php

namespace Database\Factories;

use App\Models\AttendanceCorrection;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending request to set 2026-11-24 to 09:00 - 18:00.
 *
 * @extends Factory<AttendanceCorrection>
 */
class AttendanceCorrectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => '2026-11-24',
            'requested_in' => '09:00',
            'requested_out' => '18:00',
            'reason' => 'Forgot to punch.',
            'status' => 'pending',
            'manager_id' => null,
            'manager_decided_at' => null,
            'hr_id' => null,
            'hr_decided_at' => null,
            'decision_note' => null,
        ];
    }
}
