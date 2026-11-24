<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A present day on 2026-11-24, 09:00 to 18:00 (540 minutes), for a new employee. Pass employee_id and date together
 * for an existing employee (one record per employee per day).
 *
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'date' => '2026-11-24',
            'shift_id' => null,
            'clock_in' => fn (array $a) => substr((string) $a['date'], 0, 10).' 09:00:00',
            'clock_out' => fn (array $a) => substr((string) $a['date'], 0, 10).' 18:00:00',
            'worked_minutes' => 540,
            'late_minutes' => 0,
            'early_minutes' => 0,
            'overtime_minutes' => 0,
            'status' => 'present',
            'is_manual' => false,
            'needs_attention' => false,
            'needs_rebuild' => false,
            'closed' => false,
            'note' => null,
        ];
    }
}
