<?php

namespace App\Support\Payroll;

/**
 * The overtime settings of the month (attendance.overtime_*): off unless $enabled. Mode multiple: hourly pay (monthly
 * gross / basis days / $hoursPerDay) x $multiplierPercent / 100; mode fixed: $fixedRatePaisa an hour.
 */
final readonly class OvertimeRule
{
    /** @param  string  $mode  multiple | fixed */
    public function __construct(
        public bool $enabled,
        public string $mode,
        public int $multiplierPercent,
        public int $fixedRatePaisa,
        public int $hoursPerDay,
    ) {}

    /** @return array{enabled: bool, mode: string, multiplier_percent: int, fixed_rate: int, hours_per_day: int} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'mode' => $this->mode,
            'multiplier_percent' => $this->multiplierPercent,
            'fixed_rate' => $this->fixedRatePaisa,
            'hours_per_day' => $this->hoursPerDay,
        ];
    }
}
