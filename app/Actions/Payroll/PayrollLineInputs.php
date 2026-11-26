<?php

namespace App\Actions\Payroll;

use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use App\Support\Payroll\PayrollRoster;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * HR's inputs on one line of a draft payroll run (design 5.6 "Review"): one-off additions and deductions, a tax
 * override, excluding / including the line. A loan skip is LoanActions::skip followed by
 * GeneratePayrollRun::recalculate for that employee (Task 8's controller calls both).
 *
 *   $inputs->addAdjustment($hr, $item, 'Bonus', 'addition', 10000, true);   // whole rupees
 *   $inputs->removeAdjustment($hr, $adjustment);
 *   $inputs->overrideTax($hr, $item, 1500, 'Exemption certificate on file.');   // whole rupees; null clears
 *   $inputs->exclude($hr, $item, 'On unpaid sabbatical this month.');
 *   $inputs->include($hr, $item);
 *
 * Amounts are entered as whole rupees and stored in paisa: an adjustment 1..99,999,999, a tax override 0..99,999,999.
 * Labels 2-100 characters, reasons 5-255 (trimmed). Each input: one transaction (3 tries) whose first statement locks
 * the run row FOR UPDATE (a line input updates the line in place, never inserts one, so it takes no employee lock);
 * a run that is not a draft → [run] "This payroll is approved and cannot change."; a line no longer in the run → [run];
 * a person no longer in the month's roster (PayrollRoster) → [run] "This person is no longer in this payroll —
 * recalculate first." (the line is not deleted here);
 * not PayrollAccess::mayEditSalary for the line's person → AuthorizationException (403: HR never edits their own line or
 * an Admin's / HR person's); then the change, the line recalculated (GeneratePayrollRun::recalculateLocked, which keeps
 * the run's headcount and totals current) and the audit entry, in the same transaction.
 *
 * Audited (area payroll, subject the run; item, employee, employee_name): "Payroll adjustment added|removed",
 * "Payroll tax overridden", "Payroll tax override cleared", "Payroll line excluded|included". A change that changes
 * nothing (clearing no override, including a line that is not excluded) writes nothing.
 */
class PayrollLineInputs
{
    public const MAX_RUPEES = 99_999_999;

    public const NOT_IN_ROSTER_MESSAGE = 'This person is no longer in this payroll — recalculate first.';

    public function __construct(private readonly GeneratePayrollRun $generator) {}

    public function addAdjustment(User $by, PayrollItem $item, string $label, string $type, int $amount, bool $taxable): PayrollAdjustment
    {
        $label = trim($label);
        $errors = [];
        if (mb_strlen($label) < 2 || mb_strlen($label) > 100) {
            $errors['label'] = 'Enter a label of 2 to 100 characters.';
        }
        if (! in_array($type, (array) config('hrm.payroll.adjustment_types'), true)) {
            $errors['type'] = 'Choose an addition or a deduction.';
        }
        if ($amount < 1 || $amount > self::MAX_RUPEES) {
            $errors['amount'] = 'Enter whole rupees from 1 to 99,999,999.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($by, $item, $label, $type, $amount, $taxable): PayrollAdjustment {
            [$run, $line] = $this->lockLine($by, $item);

            $adjustment = new PayrollAdjustment;
            $adjustment->forceFill([
                'payroll_item_id' => $line->id,
                'label' => $label,
                'type' => $type,
                'amount' => Money::rupees($amount),
                'is_taxable' => $type === 'addition' && $taxable,
                'created_by' => $by->id,
            ])->save();

            $this->generator->recalculateLocked($run, $line->employee_id);

            Audit::record('payroll', 'Payroll adjustment added', $run, [
                ...self::lineProperties($run, $line),
                'adjustment' => $adjustment->id,
                'label' => $label,
                'type' => $type,
                'amount' => $adjustment->amount,
                'amount_formatted' => Money::format($adjustment->amount),
                'taxable' => $adjustment->is_taxable,
            ], $by);

            return $adjustment;
        }, 3);
    }

    public function removeAdjustment(User $by, PayrollAdjustment $adjustment): void
    {
        $item = PayrollItem::query()->find($adjustment->payroll_item_id)
            ?? throw (new ModelNotFoundException)->setModel(PayrollItem::class, [$adjustment->payroll_item_id]);

        DB::transaction(function () use ($by, $item, $adjustment): void {
            [$run, $line] = $this->lockLine($by, $item);

            $current = PayrollAdjustment::query()
                ->whereKey($adjustment->id)
                ->where('payroll_item_id', $line->id)
                ->first();
            if ($current === null) {
                throw ValidationException::withMessages(['adjustment' => 'This item was already removed.']);
            }

            $current->delete();
            $this->generator->recalculateLocked($run, $line->employee_id);

            Audit::record('payroll', 'Payroll adjustment removed', $run, [
                ...self::lineProperties($run, $line),
                'adjustment' => $current->id,
                'label' => $current->label,
                'type' => $current->type,
                'amount' => $current->amount,
                'amount_formatted' => Money::format($current->amount),
                'taxable' => $current->is_taxable,
            ], $by);
        }, 3);
    }

    /** $amount whole rupees (0..99,999,999) with a reason of 5-255 characters, or null to clear the override. */
    public function overrideTax(User $by, PayrollItem $item, ?int $amount, ?string $reason): void
    {
        $reason = trim((string) $reason);
        if ($amount !== null) {
            $errors = [];
            if ($amount < 0 || $amount > self::MAX_RUPEES) {
                $errors['amount'] = 'Enter whole rupees from 0 to 99,999,999.';
            }
            if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
                $errors['reason'] = 'Give a reason of 5 to 255 characters.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        DB::transaction(function () use ($by, $item, $amount, $reason): void {
            [$run, $line] = $this->lockLine($by, $item);

            if ($amount === null && ! $line->tax_overridden) {
                return;
            }

            $old = ['tax' => $line->tax, 'overridden' => $line->tax_overridden, 'override' => $line->tax_override, 'reason' => $line->tax_override_reason];

            $line->forceFill($amount === null
                ? ['tax_overridden' => false, 'tax_override' => null, 'tax_override_reason' => null]
                : ['tax_overridden' => true, 'tax_override' => Money::rupees($amount), 'tax_override_reason' => $reason],
            )->save();

            $this->generator->recalculateLocked($run, $line->employee_id);
            $fresh = $line->fresh() ?? $line;

            Audit::record('payroll', $amount === null ? 'Payroll tax override cleared' : 'Payroll tax overridden', $run, [
                ...self::lineProperties($run, $line),
                'old' => [...$old, 'tax_formatted' => Money::format($old['tax'])],
                'attributes' => [
                    'tax' => $fresh->tax,
                    'overridden' => $fresh->tax_overridden,
                    'override' => $fresh->tax_override,
                    'reason' => $fresh->tax_override_reason,
                    'tax_formatted' => Money::format($fresh->tax),
                ],
            ], $by);
        }, 3);
    }

    public function exclude(User $by, PayrollItem $item, string $reason): void
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 5 to 255 characters.']);
        }

        DB::transaction(function () use ($by, $item, $reason): void {
            [$run, $line] = $this->lockLine($by, $item);

            $old = ['excluded' => $line->excluded, 'reason' => $line->excluded_reason, 'net' => $line->net];
            $line->forceFill(['excluded' => true, 'excluded_reason' => $reason])->save();
            $this->generator->recalculateLocked($run, $line->employee_id);

            Audit::record('payroll', 'Payroll line excluded', $run, [
                ...self::lineProperties($run, $line),
                'reason' => $reason,
                'old' => [...$old, 'net_formatted' => Money::format($old['net'])],
            ], $by);
        }, 3);
    }

    public function include(User $by, PayrollItem $item): void
    {
        DB::transaction(function () use ($by, $item): void {
            [$run, $line] = $this->lockLine($by, $item);

            if (! $line->excluded) {
                return;
            }

            $oldReason = $line->excluded_reason;
            $line->forceFill(['excluded' => false, 'excluded_reason' => null])->save();
            $this->generator->recalculateLocked($run, $line->employee_id);
            $fresh = $line->fresh() ?? $line;

            Audit::record('payroll', 'Payroll line included', $run, [
                ...self::lineProperties($run, $line),
                'old' => ['excluded' => true, 'reason' => $oldReason],
                'attributes' => ['excluded' => false, 'net' => $fresh->net, 'net_formatted' => Money::format($fresh->net)],
            ], $by);
        }, 3);
    }

    /**
     * The run row FOR UPDATE (first statement), refused unless draft; the line read again under it; the edit rule.
     *
     * @return array{0: PayrollRun, 1: PayrollItem}
     */
    private function lockLine(User $by, PayrollItem $item): array
    {
        $run = GeneratePayrollRun::lockDraft((int) $item->payroll_run_id);

        $line = PayrollItem::query()
            ->whereKey($item->id)
            ->where('payroll_run_id', $run->id)
            ->with('employee.user')
            ->first();
        if ($line === null) {
            throw ValidationException::withMessages(['run' => 'This line is no longer in the payroll. Reload the page.']);
        }

        if (! PayrollAccess::mayEditSalary($by, $line->employee)) {
            throw new AuthorizationException;
        }

        // Someone who has left the roster since the last calculation (a last working day set, say): the line would
        // be deleted by the recalculation below, so the input is refused instead and recalculating is left to HR.
        if (! PayrollRoster::forMonth($run->month)->included->contains('id', (int) $line->employee_id)) {
            throw ValidationException::withMessages(['run' => self::NOT_IN_ROSTER_MESSAGE]);
        }

        return [$run, $line];
    }

    /** @return array{item: int, employee: int, employee_name: string, month: string} */
    private static function lineProperties(PayrollRun $run, PayrollItem $line): array
    {
        return [
            'item' => (int) $line->id,
            'employee' => (int) $line->employee_id,
            'employee_name' => $line->employee->full_name,
            'month' => $run->month,
        ];
    }
}
