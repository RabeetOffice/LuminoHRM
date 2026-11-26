<?php

namespace App\Models;

use Database\Factories\PayrollItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One person's line in a payroll run (unique run + employee): the figures (paisa) and the frozen breakdown
 * (version 1, see the Phase 5 plan) written from a PayResult (PayResult::toItemAttributes()), HR's inputs (exclusion,
 * tax override, one-off adjustments), the bank details snapshot taken at approval and the payslip file.
 *
 * After approval the stored breakdown is the only source for screens, payslips, reports and the bank sheet: it is
 * never recomputed. Salary data: only payroll.view users, and the employee for their own approved line.
 *
 * Frozen once its run is approved or paid (safety net, LogicException): an existing line of such a run cannot be
 * changed or deleted, except the payslip file columns (PAYSLIP_COLUMNS, written after approval). The run's status is
 * read from the database, not from a loaded relation. So the approval copies the bank snapshot onto the lines BEFORE
 * it sets the run's status to approved. Creating a line is not guarded here (the actions refuse generating a locked
 * month; test fixtures may add lines to an approved run).
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $employee_id
 * @property bool $excluded
 * @property string|null $excluded_reason
 * @property int $days_paid
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
 * @property bool $tax_overridden
 * @property int|null $tax_override
 * @property string|null $tax_override_reason
 * @property array<string, mixed>|null $breakdown
 * @property list<array{key: string, message: string}> $warnings
 * @property list<string> $blocking
 * @property string|null $bank_name
 * @property string|null $account_title
 * @property string|null $account_number
 * @property string|null $payslip_path
 * @property Carbon|null $payslip_generated_at
 * @property Carbon|null $payslip_emailed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PayrollItem extends Model
{
    /** @use HasFactory<PayrollItemFactory> */
    use HasFactory;

    /** The money columns (paisa), in display order. */
    public const FIGURES = [
        'basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
        'other_deductions', 'loan_deduction', 'taxable_pay', 'tax', 'net',
    ];

    /** The only columns that may change on a line of an approved or paid run. */
    public const PAYSLIP_COLUMNS = ['payslip_path', 'payslip_generated_at', 'payslip_emailed_at', 'updated_at'];

    protected $fillable = [
        'payroll_run_id', 'employee_id', 'excluded', 'excluded_reason', 'days_paid',
        'basic', 'allowances', 'overtime', 'additions', 'gross', 'attendance_deduction', 'fixed_deductions',
        'other_deductions', 'loan_deduction', 'taxable_pay', 'tax', 'net',
        'tax_overridden', 'tax_override', 'tax_override_reason', 'breakdown', 'warnings', 'blocking',
        'bank_name', 'account_title', 'account_number', 'payslip_path', 'payslip_generated_at', 'payslip_emailed_at',
    ];

    protected $attributes = ['excluded' => false, 'tax_overridden' => false, 'warnings' => '[]', 'blocking' => '[]'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'excluded' => 'boolean',
            'days_paid' => 'integer',
            'basic' => 'integer',
            'allowances' => 'integer',
            'overtime' => 'integer',
            'additions' => 'integer',
            'gross' => 'integer',
            'attendance_deduction' => 'integer',
            'fixed_deductions' => 'integer',
            'other_deductions' => 'integer',
            'loan_deduction' => 'integer',
            'taxable_pay' => 'integer',
            'tax' => 'integer',
            'net' => 'integer',
            'tax_overridden' => 'boolean',
            'tax_override' => 'integer',
            'breakdown' => 'array',
            'warnings' => 'array',
            'blocking' => 'array',
            'payslip_generated_at' => 'datetime',
            'payslip_emailed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PayrollItem $item): void {
            if ($item->exists && array_diff(array_keys($item->getDirty()), self::PAYSLIP_COLUMNS) !== []) {
                $item->refuseWhenRunLocked('changed');
            }
        });

        static::deleting(function (PayrollItem $item): void {
            $item->refuseWhenRunLocked('deleted');
        });
    }

    private function refuseWhenRunLocked(string $what): void
    {
        $status = PayrollRun::query()->whereKey($this->getOriginal('payroll_run_id'))->value('status');

        if (in_array($status, (array) config('hrm.payroll.locked_statuses'), true)) {
            throw new LogicException("A line of a payroll run that is {$status} cannot be {$what}.");
        }
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<PayrollAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }

    /** @return HasMany<LoanRepayment, $this> */
    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }
}
