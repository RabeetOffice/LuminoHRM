<?php

namespace Database\Factories;

use App\Models\Clearance;
use App\Models\Resignation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An unticked "Dues cleared" item of a new resignation. Give each item of one exit its own sort_order.
 *
 * @extends Factory<Clearance>
 */
class ClearanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'resignation_id' => Resignation::factory(),
            'kind' => 'dues',
            'label' => 'Dues cleared',
            'sort_order' => 2,
            'cleared_by' => null,
            'cleared_at' => null,
        ];
    }
}
