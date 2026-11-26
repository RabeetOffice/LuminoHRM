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
use LogicException;

/**
 * The payroll of one calendar month (unique month): draft -> approved (= locked, App\Support\PayrollLock) -> paid.
 * Totals in paisa. An approved or paid run is never edited, re-opened or deleted; only a draft is deleted (its lines
 * go with it). Enforced here as a safety net (LogicException): the status only moves draft -> approved -> paid, and
 * an approved or paid run cannot be deleted. PayrollItem freezes the lines of such a run.
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

    /** The one status each status may move to. */
    private const FORWARD = ['draft' => 'approved', 'approved' => 'paid'];

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

            // Status moves only forward: draft -> approved -> paid. Never back, never draft straight to paid.
            if ($run->exists && $run->isDirty('status')) {
                $from = (string) $run->getOriginal('status');

                if ((self::FORWARD[$from] ?? null) !== $run->status) {
                    throw new LogicException("A payroll run cannot go from [{$from}] to [{$run->status}].");
                }
            }
        });

        static::deleting(function (PayrollRun $run): void {
            if (in_array($run->getOriginal('status'), (array) config('hrm.payroll.locked_statuses'), true)) {
                throw new LogicException("A payroll run that is {$run->getOriginal('status')} is never deleted.");
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

    /**
     * A digest of the figures someone reviewed: sha256 of one line per payroll line, in employee id order,
     * "employee_id:excluded:days_paid:gross:attendance_deduction:fixed_deductions:other_deductions:loan_deduction:tax:net".
     * The run page sends it back on approve; ApprovePayrollRun compares it after recalculating. One query.
     */
    public function fingerprint(): string
    {
        $lines = $this->items()
            ->orderBy('employee_id')
            ->toBase()
            ->get(['employee_id', 'excluded', 'days_paid', 'gross', 'attendance_deduction', 'fixed_deductions',
                'other_deductions', 'loan_deduction', 'tax', 'net'])
            ->map(fn (object $i): string => implode(':', [
                (int) $i->employee_id,
                (int) (bool) $i->excluded,
                (int) $i->days_paid,
                (int) $i->gross,
                (int) $i->attendance_deduction,
                (int) $i->fixed_deductions,
                (int) $i->other_deductions,
                (int) $i->loan_deduction,
                (int) $i->tax,
                (int) $i->net,
            ]))
            ->all();

        return hash('sha256', implode("\n", $lines));
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
