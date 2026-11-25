<?php

namespace App\Support\Leave;

/**
 * The result of counting a leave request's days (LeaveDays::count / LeaveDays::plan): the counted days in date order,
 * their total, or why nothing can be counted.
 *
 *   $plan->days      list of ['date' => 'Y-m-d', 'amount' => 1.0 | 0.5, 'half' => 'full' | 'am' | 'pm']
 *   $plan->total     sum of the amounts (0.0 when refused)
 *   $plan->refusal   null, or the reason nothing can be counted (copy for the person; LeaveDays::count returns the key
 *                    LeavePlan::OUTSIDE_EMPLOYMENT instead of copy for the employment window, LeaveDays::plan turns it
 *                    into "These dates are outside {name}'s employment.")
 *   $plan->byYear()  [2026 => 2.0, 2027 => 3.0]: days per calendar (leave) year, years ascending
 *
 * Amounts are multiples of 0.5. Totals are summed as whole half days (integers) and divided by two once, so adding any
 * number of halves never drifts (LeaveDays::halves / LeaveDays::fromHalves).
 */
final readonly class LeavePlan
{
    /** The refusal key LeaveDays::count returns for a date outside the employment window. */
    public const OUTSIDE_EMPLOYMENT = 'outside_employment';

    /** Sum of the amounts; 0.0 when refused. */
    public float $total;

    /**
     * @param  list<array{date: string, amount: float, half: 'full'|'am'|'pm'}>  $days  date order
     * @param  string|null  $refusal  why nothing can be counted (then $days is empty)
     */
    public function __construct(
        public array $days = [],
        public ?string $refusal = null,
    ) {
        $halves = 0;

        foreach ($days as $day) {
            $halves += LeaveDays::halves($day['amount']);
        }

        $this->total = LeaveDays::fromHalves($halves);
    }

    /** A plan that counts nothing, for the given reason. */
    public static function refused(string $refusal): self
    {
        return new self([], $refusal);
    }

    /** True when nothing can be counted ($refusal is set). */
    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }

    /**
     * Days per calendar year, years ascending: a request across 31 Dec → 1 Jan counts against each year separately.
     *
     * @return array<int, float>
     */
    public function byYear(): array
    {
        $halves = [];

        foreach ($this->days as $day) {
            $year = (int) substr($day['date'], 0, 4);
            $halves[$year] = ($halves[$year] ?? 0) + LeaveDays::halves($day['amount']);
        }

        ksort($halves);

        return array_map(fn (int $h): float => LeaveDays::fromHalves($h), $halves);
    }

    /**
     * The counted dates, in order.
     *
     * @return list<string>
     */
    public function dates(): array
    {
        return array_map(fn (array $day): string => $day['date'], $this->days);
    }
}
