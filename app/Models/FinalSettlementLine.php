<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FinalSettlementLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One HR line of a settlement draft: a bonus (addition), a notice shortfall (deduction) or another addition or
 * deduction. Amount in paisa (entered in whole rupees); only an addition can be taxable.
 *
 * @property int $id
 * @property int $final_settlement_id
 * @property string $kind
 * @property string $type addition | deduction
 * @property string $label
 * @property int $amount
 * @property bool $is_taxable
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FinalSettlementLine extends Model
{
    /** @use HasFactory<FinalSettlementLineFactory> */
    use HasFactory;

    protected $fillable = ['final_settlement_id', 'kind', 'type', 'label', 'amount', 'is_taxable', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer', 'is_taxable' => 'boolean'];
    }

    /** @return BelongsTo<FinalSettlement, $this> */
    public function settlement(): BelongsTo
    {
        return $this->belongsTo(FinalSettlement::class, 'final_settlement_id');
    }
}
