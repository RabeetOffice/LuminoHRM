<?php

namespace App\Support\Payroll;

/**
 * A one-off item on a payroll line (App\Models\PayrollAdjustment) as the calculator sees it: an addition or a deduction
 * of $amount paisa; only an addition can be taxable.
 */
final readonly class PayAdjustment
{
    /** @param  string  $type  addition | deduction */
    public function __construct(
        public int $id,
        public string $label,
        public string $type,
        public int $amount,
        public bool $taxable,
    ) {}

    /** @return array{id: int, label: string, type: string, amount: int, taxable: bool} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'type' => $this->type,
            'amount' => $this->amount,
            'taxable' => $this->taxable,
        ];
    }
}
