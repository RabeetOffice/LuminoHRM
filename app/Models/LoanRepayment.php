<?php

namespace App\Models;

use Database\Factories\LoanRepaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One repayment of a loan (paisa): written by a payroll approval (payroll_item_id set; one per loan per line) or
 * recorded by HR as a manual (cash) repayment (payroll_item_id null, with a note). A repayment in a locked month never
 * changes.
 *
 * @property int $id
 * @property int $loan_id
 * @property int|null $payroll_item_id
 * @property int $amount
 * @property string $month "Y-m"
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoanRepayment extends Model
{
    /** @use HasFactory<LoanRepaymentFactory> */
    use HasFactory;

    protected $fillable = ['loan_id', 'payroll_item_id', 'amount', 'month', 'note', 'created_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
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
