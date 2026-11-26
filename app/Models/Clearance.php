<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ClearanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an exit's clearance checklist. Created at final approval (the three built-in items, then each custom item
 * of Settings > Exit as it stood then). Ticked when cleared_at is set; cleared_by null with cleared_at set = ticked
 * automatically (the "assets" item, stored when the settlement is approved).
 *
 * @property int $id
 * @property int $resignation_id
 * @property string $kind
 * @property string $label
 * @property int $sort_order
 * @property int|null $cleared_by
 * @property CarbonImmutable|null $cleared_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class Clearance extends Model
{
    /** @use HasFactory<ClearanceFactory> */
    use HasFactory;

    protected $fillable = ['resignation_id', 'kind', 'label', 'sort_order', 'cleared_by', 'cleared_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'cleared_at' => 'datetime'];
    }

    /** @return BelongsTo<Resignation, $this> */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by');
    }

    public function isCleared(): bool
    {
        return $this->cleared_at !== null;
    }
}
