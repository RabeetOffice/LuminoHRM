<?php

namespace Database\Factories;

use App\Models\FinalSettlement;
use App\Models\Resignation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft settlement of 2026-12 for a new approved exit, every figure zero, with an empty breakdown carrying the
 * settlement key (see the Phase 6c plan: the stored breakdown contract). employee_id follows the resignation.
 *
 * @extends Factory<FinalSettlement>
 */
class FinalSettlementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resignation_id' => Resignation::factory()->approved(),
            'employee_id' => fn (array $attrs) => Resignation::query()->whereKey($attrs['resignation_id'])->value('employee_id'),
            'month' => '2026-12',
            'status' => 'draft',
            'breakdown' => [
                'settlement' => [
                    'last_working_day' => '2026-12-10',
                    'exit_type' => 'resignation',
                    'encashment' => null,
                    'lines' => [],
                    'loans' => [],
                    'loan_shortfall' => 0,
                    'owed_by_employee' => 0,
                ],
            ],
            'stale' => false,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => ['status' => 'approved', 'approved_at' => '2026-12-11 10:00:00']);
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'approved_at' => '2026-12-11 10:00:00', 'paid_on' => '2026-12-12']);
    }
}
