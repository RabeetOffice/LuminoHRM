<?php

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An available asset in good condition with a unique code (AST-9xxxx, clear of the codes AssetCode hands out in tests)
 * and a unique serial number.
 *
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'asset_code' => 'AST-9'.fake()->unique()->numerify('####'),
            'name' => 'Laptop '.fake()->unique()->numerify('####'),
            'asset_category_id' => AssetCategory::factory(),
            'serial_number' => 'SN'.fake()->unique()->numerify('########'),
            'purchase_date' => null,
            'cost' => null,
            'condition' => 'good',
            'status' => 'available',
            'notes' => null,
        ];
    }
}
