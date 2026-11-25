<?php

namespace App\Support\Payroll;

/**
 * The part of a pay window paid under one salary profile: dates $from..$to ("Y-m-d", inclusive, $days calendar days)
 * and that profile's MONTHLY figures (paisa), not yet prorated. The pay window is split at each profile's effective
 * date; segments are in date order and do not overlap.
 */
final readonly class ProfileSegment
{
    /**
     * @param  list<PayComponent>  $allowances
     * @param  list<PayComponent>  $deductions
     * @param  string  $taxMode  none | fixed | percentage
     * @param  int  $taxValue  paisa a month (fixed), basis points (percentage), 0 (none)
     * @param  string|null  $taxBase  gross_taxable | basic (percentage only)
     */
    public function __construct(
        public int $profileId,
        public string $from,
        public string $to,
        public int $days,
        public int $basic,
        public array $allowances,
        public array $deductions,
        public string $taxMode,
        public int $taxValue,
        public ?string $taxBase,
    ) {}

    /** Basic plus every allowance, a month (paisa). */
    public function gross(): int
    {
        $total = $this->basic;
        foreach ($this->allowances as $allowance) {
            $total += $allowance->amount;
        }

        return $total;
    }

    /** Basic plus the taxable allowances, a month (paisa). */
    public function taxableGross(): int
    {
        $total = $this->basic;
        foreach ($this->allowances as $allowance) {
            if ($allowance->taxable) {
                $total += $allowance->amount;
            }
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'profile_id' => $this->profileId,
            'from' => $this->from,
            'to' => $this->to,
            'days' => $this->days,
            'basic' => $this->basic,
            'allowances' => array_map(fn (PayComponent $c) => $c->toArray(), $this->allowances),
            'deductions' => array_map(fn (PayComponent $c) => $c->toArray(), $this->deductions),
            'tax_mode' => $this->taxMode,
            'tax_value' => $this->taxValue,
            'tax_base' => $this->taxBase,
        ];
    }
}
