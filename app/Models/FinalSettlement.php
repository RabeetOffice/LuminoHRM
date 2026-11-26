<?php

namespace App\Models;

use App\Casts\NaiveDate;
use Carbon\CarbonImmutable;
use Database\Factories\FinalSettlementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The final settlement of one exit: the pay of the person's final month through the payroll calculator, leave
 * encashment, HR lines and loan recovery. Money is integer paisa; net is signed (below zero = owed by the employee).
 * Draft until approved; an approved or paid settlement is frozen (and freezes the person's final month, PayrollLock).
 *
 * @property int $id
 * @property int $resignation_id
 * @property int $employee_id
 * @property string $month "Y-m" of the last working day
 * @property string $status draft | approved | paid
 * @property int $basic
 * @property int $allowances
 * @property int $overtime
 * @property int $additions
 * @property int $gross
 * @property int $attendance_deduction
 * @property int $fixed_deductions
 * @property int $other_deductions
 * @property int $loan_deduction
 * @property int $taxable_pay
 * @property int $tax
 * @property int $net
 * @property int $encashment_days in halves
 * @property int $encashment_amount
 * @property int $loan_shortfall
 * @property bool $tax_overridden
 * @property int|null $tax_override
 * @property string|null $tax_override_reason
 * @property array<string, mixed>|null $breakdown
 * @property bool $stale
 * @property CarbonImmutable|null $calculated_at
 * @property int|null $prepared_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property bool $own_settlement
 * @property CarbonImmutable|null $paid_on
 * @property int|null $paid_by
 * @property array<string, mixed>|null $summary
 * @property string|null $statement_path
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class FinalSettlement extends Model
{
    /** @use HasFactory<FinalSettlementFactory> */
    use HasFactory;

    /** The payroll-line figures, all paisa (net signed). */
    public const FIGURES = [
        'basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
        'other_deductions', 'loan_deduction', 'taxable_pay', 'tax', 'net',
    ];

    protected $fillable = [
        'resignation_id', 'employee_id', 'month', 'status', ...self::FIGURES, 'encashment_days', 'encashment_amount',
        'loan_shortfall', 'tax_overridden', 'tax_override', 'tax_override_reason', 'breakdown', 'stale', 'calculated_at',
        'prepared_by', 'approved_by', 'approved_at', 'own_settlement', 'paid_on', 'paid_by', 'summary', 'statement_path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        $casts = [
            'encashment_days' => 'integer',
            'encashment_amount' => 'integer',
            'loan_shortfall' => 'integer',
            'tax_overridden' => 'boolean',
            'tax_override' => 'integer',
            'breakdown' => 'array',
            'summary' => 'array',
            'stale' => 'boolean',
            'own_settlement' => 'boolean',
            'calculated_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_on' => NaiveDate::class,
        ];

        foreach (self::FIGURES as $figure) {
            $casts[$figure] = 'integer';
        }

        return $casts;
    }

    /** @return BelongsTo<Resignation, $this> */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<FinalSettlementLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(FinalSettlementLine::class)->orderBy('id');
    }

    /** @return HasMany<LoanRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    /** Approved or paid: the figures never change and the person's final month is frozen. */
    public function isFrozen(): bool
    {
        return in_array($this->status, (array) config('hrm.exit.frozen_statuses'), true);
    }
}
