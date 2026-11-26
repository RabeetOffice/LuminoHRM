<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open assignment of a new asset to a new employee, handed over on 2026-11-20 in good condition.
 *
 * @extends Factory<AssetAssignment>
 */
class AssetAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'employee_id' => Employee::factory(),
            'assigned_on' => '2026-11-20',
            'returned_on' => null,
            'condition_out' => 'good',
            'return_condition' => null,
            'note' => null,
            'return_note' => null,
        ];
    }

    /** Returned on $date in the given condition. */
    public function returned(string $date = '2026-11-25', string $condition = 'good'): static
    {
        return $this->state(fn () => ['returned_on' => $date, 'return_condition' => $condition]);
    }
}
