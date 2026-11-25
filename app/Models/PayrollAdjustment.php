<?php

namespace App\Models;

use Database\Factories\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A one-off item HR added to one payroll line: an addition (taxable by default) or a deduction (never taxable), in
 * paisa. Kept across recalculation; deleted with its line (only draft lines are ever deleted).
 *
 * @property int $id
 * @property int $payroll_item_id
 * @property string $label
 * @property string $type addition | deduction
 * @property int $amount
 * @property bool $is_taxable
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayrollAdjustment extends Model
{
    /** @use HasFactory<PayrollAdjustmentFactory> */
    use HasFactory;

    protected $fillable = ['payroll_item_id', 'label', 'type', 'amount', 'is_taxable', 'created_by'];

    protected $attributes = ['is_taxable' => false];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'is_taxable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PayrollAdjustment $adjustment): void {
            if (! in_array($adjustment->type, (array) config('hrm.payroll.adjustment_types'), true)) {
                throw new InvalidArgumentException("Unknown adjustment type [{$adjustment->type}].");
            }

            if ($adjustment->type === 'deduction') {
                $adjustment->is_taxable = false;
            }
        });
    }

    /** @return BelongsTo<PayrollItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
