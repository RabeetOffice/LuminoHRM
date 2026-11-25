<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Whether a payroll month is locked (its payroll has been run and approved), so nothing may add or remove leave days
 * (or other pay-affecting records) dated in it. Payroll arrives in Phase 5: until then no month is locked, but every
 * place that adds or removes leave days on dates already calls this hook (applying for a past date, final approval,
 * cancelling, holiday refunds, exit trimming), so Phase 5 only has to fill in isLocked().
 *
 *   PayrollLock::isLocked('2026-11');                              // false (this phase)
 *   PayrollLock::firstLocked(['2026-11-24', '2026-12-01']);        // the first locked "Y-m", or null
 */
final class PayrollLock
{
    /**
     * @param  string  $month  "Y-m"
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function isLocked(string $month): bool
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new InvalidArgumentException("Not a payroll month [{$month}].");
        }

        return false;
    }

    /**
     * The first "Y-m" (in the order given) among these dates whose payroll month is locked, or null when none is.
     *
     * @param  iterable<string>  $dates  "Y-m-d"
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function firstLocked(iterable $dates): ?string
    {
        $seen = [];

        foreach ($dates as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException("Not a date [{$date}].");
            }

            $month = substr($date, 0, 7);

            if (isset($seen[$month])) {
                continue;
            }

            $seen[$month] = true;

            if (self::isLocked($month)) {
                return $month;
            }
        }

        return null;
    }
}
