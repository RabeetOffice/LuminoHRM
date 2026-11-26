<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Models\Concerns\HasNaiveDateScopes;
use Carbon\CarbonImmutable;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * A company asset in the register. Cost is integer paisa (nullable). purchase_date is a naive "Y-m-d" (App\Casts\NaiveDate;
 * query it with onDate() / betweenDates()). status is one of config('hrm.assets.statuses'), condition one of
 * config('hrm.assets.conditions'). Not audited by the model: the actions in App\Actions\Assets write their own entries.
 *
 * An asset with any assignment row (open or returned) can never be deleted: its history is kept.
 *
 * @property int $id
 * @property string $asset_code
 * @property string $name
 * @property int $asset_category_id
 * @property string|null $serial_number
 * @property CarbonImmutable|null $purchase_date
 * @property int|null $cost paisa
 * @property string $condition
 * @property string $status
 * @property string|null $notes
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use HasFactory;

    use HasNaiveDateScopes;

    protected $fillable = [
        'asset_code', 'name', 'asset_category_id', 'serial_number', 'purchase_date', 'cost', 'condition', 'status', 'notes', 'created_by',
    ];

    protected $attributes = ['status' => 'available'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_date' => NaiveDate::class,
            'cost' => 'integer',
        ];
    }

    protected function naiveDateColumn(): string
    {
        return 'purchase_date';
    }

    protected static function booted(): void
    {
        static::deleting(function (Asset $asset): void {
            if (AssetAssignment::query()->where('asset_id', $asset->getKey())->exists()) {
                throw new LogicException('An asset with assignment history cannot be deleted.');
            }
        });
    }

    /** @return BelongsTo<AssetCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /** @return HasMany<AssetAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(AssetAssignment::class);
    }

    /**
     * The assignment that is still open (not returned yet), if any.
     *
     * @return HasOne<AssetAssignment, $this>
     */
    public function openAssignment(): HasOne
    {
        return $this->hasOne(AssetAssignment::class)->whereNull('returned_on');
    }
}
