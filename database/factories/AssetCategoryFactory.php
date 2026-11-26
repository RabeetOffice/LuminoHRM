<?php

namespace Database\Factories;

use App\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A category with a unique name.
 *
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Category '.fake()->unique()->numerify('####'),
            'sort_order' => 0,
        ];
    }
}
