<?php

namespace App\Support\Payroll;

/**
 * An active loan with something remaining whose first deduction month is on or before the run month (paisa). A list
 * of these is oldest first (by start_month, then id): that is the order installments are reduced in when pay is short.
 * $skipped: HR skipped this month (nothing deducted).
 */
final readonly class LoanDue
{
    /** @param  string  $type  loan | advance */
    public function __construct(
        public int $loanId,
        public string $type,
        public int $remaining,
        public int $installment,
        public bool $skipped,
    ) {}

    /** @return array{loan_id: int, type: string, remaining: int, installment: int, skipped: bool} */
    public function toArray(): array
    {
        return [
            'loan_id' => $this->loanId,
            'type' => $this->type,
            'remaining' => $this->remaining,
            'installment' => $this->installment,
            'skipped' => $this->skipped,
        ];
    }
}
