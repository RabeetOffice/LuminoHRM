<?php

namespace App\Models;

use Database\Factories\LoanSkipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A month in which a loan's installment is not deducted (HR, with a reason). Unique per loan and month.
 *
 * @property int $id
 * @property int $loan_id
 * @property string $month "Y-m"
 * @property string $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class LoanSkip extends Model
{
    /** @use HasFactory<LoanSkipFactory> */
    use HasFactory;

    protected $fillable = ['loan_id', 'month', 'reason', 'created_by'];

    /** @return BelongsTo<Loan, $this> */
    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
