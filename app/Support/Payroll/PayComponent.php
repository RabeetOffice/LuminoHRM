<?php

namespace App\Support\Payroll;

/**
 * One allowance or fixed deduction of a salary profile, as the calculator sees it: its monthly amount (paisa) and
 * whether it is taxable (always false for a deduction).
 */
final readonly class PayComponent
{
    public function __construct(
        public string $name,
        public int $amount,
        public bool $taxable,
    ) {}

    /** @return array{name: string, amount: int, taxable: bool} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'amount' => $this->amount, 'taxable' => $this->taxable];
    }
}
