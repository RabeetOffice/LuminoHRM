<?php

namespace App\Models;

use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kind of asset (Laptop, Phone, ...). Written through App\Actions\Assets\SaveAssetCategory, which audits each change and
 * refuses to delete a category that assets still use.
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'sort_order'];

    protected $attributes = ['sort_order' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    /** @return HasMany<Asset, $this> */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
