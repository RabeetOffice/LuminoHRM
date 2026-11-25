<?php

namespace App\Support\Payroll;

/**
 * One line of a breakdown's earnings or deductions (paisa): $key is basic | allowance | overtime | addition |
 * attendance | fixed | adjustment | loan; $extra carries the line's own fields (adjustment_id, loan_id, due ...).
 */
final readonly class PayLine
{
    /** @param  array<string, mixed>  $extra */
    public function __construct(
        public string $key,
        public string $label,
        public int $amount,
        public bool $taxable = false,
        public array $extra = [],
    ) {}

    /**
     * {"key", "label", "amount", "taxable", ...extra}, as stored in the breakdown JSON.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'amount' => $this->amount,
            'taxable' => $this->taxable,
            ...$this->extra,
        ];
    }
}
