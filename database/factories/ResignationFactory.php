<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Resignation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A pending resignation of a new employee: submitted 2026-11-10, last working day 2026-12-10, 30 days notice required.
 * The open_employee_id guard is kept by the model.
 *
 * @extends Factory<Resignation>
 */
class ResignationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'type' => 'resignation',
            'submitted_on' => '2026-11-10',
            'last_working_day' => '2026-12-10',
            'reason' => 'Moving to another city.',
            'notice_period_days' => 30,
            'status' => 'pending',
            'manager_skipped' => false,
            'own_decision' => false,
        ];
    }

    /** At the HR step (the manager step was skipped). */
    public function managerApproved(): static
    {
        return $this->state(fn () => ['status' => 'manager_approved', 'manager_skipped' => true]);
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'manager_skipped' => true,
            'final_decided_at' => '2026-11-11 10:00:00',
        ]);
    }

    public function ended(string $status = 'rejected'): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
