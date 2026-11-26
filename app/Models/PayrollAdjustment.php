<?php

namespace App\Models;

use Database\Factories\PayrollAdjustmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * A one-off item HR added to one payroll line: an addition (taxable by default: is_taxable defaults to true and the
 * run screen shows the box ticked; HR may untick it) or a deduction (never taxable: forced false on save), in paisa.
 * Kept across recalculation; deleted with its line (only draft lines are ever deleted). Created, changed or removed
 * only while its run is a draft (LogicException otherwise); the bulk deletes with a draft line bypass this on purpose.
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

    protected $attributes = ['is_taxable' => true];

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

            $adjustment->refuseWhenRunLocked($adjustment->exists ? 'changed' : 'added');
        });

        static::deleting(function (PayrollAdjustment $adjustment): void {
            $adjustment->refuseWhenRunLocked('removed');
        });
    }

    /** Only a draft run's line takes adjustments: refuse when the line (now, or before a move) belongs to an approved or paid run. */
    private function refuseWhenRunLocked(string $what): void
    {
        $itemIds = array_values(array_unique(array_filter([$this->payroll_item_id, $this->getOriginal('payroll_item_id')])));

        $locked = PayrollRun::query()
            ->whereIn('id', PayrollItem::query()->whereIn('id', $itemIds)->select('payroll_run_id'))
            ->whereIn('status', (array) config('hrm.payroll.locked_statuses'))
            ->value('status');

        if ($locked !== null) {
            throw new LogicException("An adjustment cannot be {$what} on a line of a payroll run that is {$locked}.");
        }
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
