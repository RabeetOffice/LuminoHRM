<?php

namespace App\Support\Payroll;

/**
 * One person's month as worked out by PayCalculator (Task 2): every figure in paisa (each a whole rupee), the loan
 * deduction per loan, warnings, blocking keys and the breakdown JSON (version 1, see the Phase 5 plan). No logic here.
 *
 *   $item->fill($result->toItemAttributes())->save();
 */
final readonly class PayResult
{
    /**
     * @param  array<int, int>  $loanDeductions  loan id => amount deducted this month
     * @param  list<array{key: string, message: string}>  $warnings
     * @param  list<string>  $blocking  keys of the checks that block approval (e.g. "negative_net")
     * @param  array<string, mixed>  $breakdown
     */
    public function __construct(
        public int $daysPaid,
        public int $basic,
        public int $allowances,
        public int $overtime,
        public int $additions,
        public int $gross,
        public int $attendanceDeduction,
        public int $fixedDeductions,
        public int $otherDeductions,
        public int $loanDeduction,
        public int $taxablePay,
        public int $tax,
        public int $net,
        public array $loanDeductions,
        public array $warnings,
        public array $blocking,
        public array $breakdown,
    ) {}

    /**
     * The payroll_items columns this result fills: the figure columns (days_paid and the twelve money columns) plus
     * breakdown, warnings and blocking. HR's inputs (excluded, tax override), the bank snapshot and the payslip
     * columns are not touched.
     *
     * @return array<string, mixed>
     */
    public function toItemAttributes(): array
    {
        return [
            'days_paid' => $this->daysPaid,
            'basic' => $this->basic,
            'allowances' => $this->allowances,
            'overtime' => $this->overtime,
            'additions' => $this->additions,
            'gross' => $this->gross,
            'attendance_deduction' => $this->attendanceDeduction,
            'fixed_deductions' => $this->fixedDeductions,
            'other_deductions' => $this->otherDeductions,
            'loan_deduction' => $this->loanDeduction,
            'taxable_pay' => $this->taxablePay,
            'tax' => $this->tax,
            'net' => $this->net,
            'breakdown' => $this->breakdown,
            'warnings' => $this->warnings,
            'blocking' => $this->blocking,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...$this->toItemAttributes(),
            'loan_deductions' => $this->loanDeductions,
        ];
    }
}
