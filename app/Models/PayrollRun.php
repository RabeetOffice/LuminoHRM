<?php

namespace App\Models;

use App\Casts\NaiveDate;
use App\Casts\NaiveDateValue;
use Database\Factories\PayrollRunFactory;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The payroll of one calendar month (unique month): draft -> approved (= locked, App\Support\PayrollLock) -> paid.
 * Totals in paisa. An approved or paid run is never edited, re-opened or deleted; only a draft is deleted (its lines
 * go with it).
 *
 * @property int $id
 * @property string $month "Y-m"
 * @property string $status draft | approved | paid
 * @property int $headcount
 * @property int $gross
 * @property int $deductions
 * @property int $tax
 * @property int $net
 * @property int|null $generated_by
 * @property Carbon|null $generated_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $paid_by
 * @property Carbon|null $paid_at
 * @property NaiveDateValue|null $paid_on
 * @property array<string, mixed>|null $summary
 * @property Carbon|null $calculated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayrollRun extends Model
{
    /** @use HasFactory<PayrollRunFactory> */
    use HasFactory;

    protected $fillable = [
        'month', 'status', 'headcount', 'gross', 'deductions', 'tax', 'net', 'generated_by', 'generated_at',
        'approved_by', 'approved_at', 'paid_by', 'paid_at', 'paid_on', 'summary', 'calculated_at',
    ];

    protected $attributes = ['status' => 'draft'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'headcount' => 'integer',
            'gross' => 'integer',
            'deductions' => 'integer',
            'tax' => 'integer',
            'net' => 'integer',
            'generated_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
            'paid_on' => NaiveDate::class,
            'summary' => 'array',
            'calculated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PayrollRun $run): void {
            if (! in_array($run->status, (array) config('hrm.payroll.run_statuses'), true)) {
                throw new InvalidArgumentException("Unknown payroll run status [{$run->status}].");
            }

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $run->month) !== 1) {
                throw new InvalidArgumentException("Not a month [{$run->month}].");
            }
        });
    }

    /** @return HasMany<PayrollItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /** Approved or paid: the month is frozen. */
    public function isLocked(): bool
    {
        return in_array($this->status, (array) config('hrm.payroll.locked_statuses'), true);
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /** "November 2026". */
    public function label(): string
    {
        return self::monthLabel($this->month);
    }

    /** "2026-11" -> "November 2026". */
    public static function monthLabel(string $month): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m', $month);

        if ($date === false || $date->format('Y-m') !== $month) {
            throw new InvalidArgumentException("Not a month [{$month}].");
        }

        return $date->format('F Y');
    }
}
