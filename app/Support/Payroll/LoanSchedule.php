<?php

namespace App\Support\Payroll;

use InvalidArgumentException;

/**
 * Projects how a loan's remainder is recovered, month by month (paisa). Pure, no I/O.
 *
 * From $fromMonth on, every month that is not skipped deducts min(installment, what is still left); the last
 * installment takes the remainder, so the schedule never recovers more than $remaining. A skipped month deducts nothing
 * and pushes the rest of the schedule one month later. At most $maxMonths calendar months are walked (skipped months
 * included), so a projection always ends.
 *
 *   LoanSchedule::project(1200000, 500000, '2026-11', ['2026-12']);
 *   // [['month' => '2026-11', 'amount' => 500000], ['month' => '2027-01', 'amount' => 500000],
 *   //  ['month' => '2027-02', 'amount' => 200000]]
 *
 *   LoanSchedule::nextMonth(1200000, 500000, '2026-11', ['2026-11']);   // '2026-12'
 */
final class LoanSchedule
{
    /**
     * @param  int  $remaining  what is still to be recovered (paisa); 0 or less gives an empty schedule
     * @param  int  $installment  the monthly installment (paisa); must be positive
     * @param  string  $fromMonth  "Y-m": the first month that may deduct
     * @param  array<int, string>  $skippedMonths  "Y-m" months that deduct nothing
     * @return list<array{month: string, amount: int}>
     */
    public static function project(int $remaining, int $installment, string $fromMonth, array $skippedMonths, int $maxMonths = 120): array
    {
        if ($installment <= 0) {
            throw new InvalidArgumentException('The installment must be positive.');
        }

        if ($maxMonths < 0) {
            throw new InvalidArgumentException('The month limit cannot be negative.');
        }

        [$year, $month] = self::split($fromMonth);
        $skipped = array_flip(array_map('strval', $skippedMonths));
        $schedule = [];
        $left = $remaining;

        for ($i = 0; $i < $maxMonths && $left > 0; $i++) {
            $key = sprintf('%04d-%02d', $year, $month);

            if (! isset($skipped[$key])) {
                $amount = min($installment, $left);
                $schedule[] = ['month' => $key, 'amount' => $amount];
                $left -= $amount;
            }

            $month++;
            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $schedule;
    }

    /**
     * The first month of the projection (the next deduction), or null when nothing is left to recover.
     *
     * @param  array<int, string>  $skippedMonths
     */
    public static function nextMonth(int $remaining, int $installment, string $fromMonth, array $skippedMonths, int $maxMonths = 120): ?string
    {
        if ($remaining <= 0) {
            return null;
        }

        return self::project($remaining, $installment, $fromMonth, $skippedMonths, $maxMonths)[0]['month'] ?? null;
    }

    /** "Y-m" of the month after $month. */
    public static function addMonth(string $month): string
    {
        [$year, $m] = self::split($month);

        return $m === 12 ? sprintf('%04d-01', $year + 1) : sprintf('%04d-%02d', $year, $m + 1);
    }

    /** @return array{0: int, 1: int} */
    private static function split(string $month): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            throw new InvalidArgumentException("Not a month [{$month}].");
        }

        return [(int) $m[1], (int) $m[2]];
    }
}
