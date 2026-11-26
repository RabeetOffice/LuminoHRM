<?php

namespace Database\Factories;

use App\Models\ReviewCycle;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open monthly cycle. Each call takes the next month (from January 2020), so cycles never share a key or overlap;
 * pass period_type, period_key, period_start, period_end and due_on to choose.
 *
 * @extends Factory<ReviewCycle>
 */
class ReviewCycleFactory extends Factory
{
    private static int $sequence = 0;

    public function definition(): array
    {
        $month = CarbonImmutable::create(2020, 1, 1)->addMonths(self::$sequence++);

        return [
            'name' => $month->format('F Y'),
            'period_type' => 'monthly',
            'period_key' => $month->format('Y-m'),
            'period_start' => $month->format('Y-m-d'),
            'period_end' => $month->endOfMonth()->format('Y-m-d'),
            'due_on' => $month->endOfMonth()->addDays(14)->format('Y-m-d'),
            'status' => 'open',
            'self_review_enabled' => true,
            'opened_by' => User::factory(),
        ];
    }
}
