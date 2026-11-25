<?php

namespace App\Models;

use Database\Factories\LoanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * A loan or advance, paid out outside the system and recovered through payroll from start_month on. Money in paisa.
 *
 * Remaining is derived, never stored: amount - opening_repaid (repaid before go-live) - the sum of repayments. Load the
 * sum for a list with Loan::query()->withRemaining() (one aggregate column, repayments_sum_amount) so remaining() runs
 * no query per row. "Cleared" is an active loan with nothing remaining; a cancelled loan wrote its remainder off.
 *
 *   Loan::query()->withRemaining()->where('employee_id', $id)->get()->map->remaining();
 *
 * @property int $id
 * @property int $employee_id
 * @property string $type loan | advance
 * @property int $amount
 * @property int $installment
 * @property string $start_month "Y-m"
 * @property int $opening_repaid
 * @property string $status active | cancelled
 * @property string|null $note
 * @property int|null $created_by
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Loan extends Model
{
    /** @use HasFactory<LoanFactory> */
    use HasFactory;

    protected $fillable = [
        'employee_id', 'type', 'amount', 'installment', 'start_month', 'opening_repaid', 'status', 'note',
        'created_by', 'cancelled_by', 'cancelled_at', 'cancel_reason',
    ];

    protected $attributes = ['opening_repaid' => 0, 'status' => 'active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'installment' => 'integer',
            'opening_repaid' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Loan $loan): void {
            if (! in_array($loan->type, (array) config('hrm.payroll.loan_types'), true)) {
                throw new InvalidArgumentException("Unknown loan type [{$loan->type}].");
            }

            if (! in_array($loan->status, (array) config('hrm.payroll.loan_statuses'), true)) {
                throw new InvalidArgumentException("Unknown loan status [{$loan->status}].");
            }

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $loan->start_month) !== 1) {
                throw new InvalidArgumentException("Not a month [{$loan->start_month}].");
            }
        });
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<LoanRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    /** @return HasMany<LoanSkip, $this> */
    public function skips(): HasMany
    {
        return $this->hasMany(LoanSkip::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Adds repayments_sum_amount (the sum of the repayments) so remaining() needs no query.
     *
     * @param  Builder<Loan>  $query
     */
    public function scopeWithRemaining(Builder $query): void
    {
        $query->withSum('repayments', 'amount');
    }

    /**
     * What is still to be recovered (paisa): amount - opening_repaid - the repayments, never below zero. Uses a loaded
     * repayments_sum_amount (withRemaining()) or loaded repayments when present, else one query.
     */
    public function remaining(): int
    {
        if (array_key_exists('repayments_sum_amount', $this->attributes)) {
            $repaid = (int) $this->attributes['repayments_sum_amount'];
        } elseif ($this->relationLoaded('repayments')) {
            $repaid = (int) $this->repayments->sum('amount');
        } else {
            $repaid = (int) $this->repayments()->sum('amount');
        }

        return max(0, $this->amount - $this->opening_repaid - $repaid);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** An active loan with nothing left to recover. */
    public function isCleared(): bool
    {
        return $this->isActive() && $this->remaining() === 0;
    }
}
