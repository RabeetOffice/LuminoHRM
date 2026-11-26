<?php

namespace App\Support\Payroll;

use InvalidArgumentException;

/**
 * Money is integer paisa (1 rupee = 100 paisa) everywhere: never a float, never round(), never a decimal cast.
 * Percentages are integer basis points (500 = 5.00%).
 *
 *   Money::div(6_000_000 * 22, 31);   // 4_258_100: Rs 42,580.645 rounded half up to Rs 42,581
 *   Money::rupees(60_000);            // 6_000_000 (an entered whole-rupee amount)
 *   Money::format(6_493_000);         // "Rs 64,930"
 *   Money::bp('5.25');                // 525
 *
 * div() is the one rounding rule (plan Global Constraints): numerator / denominator rounded half up to a WHOLE RUPEE
 * (a multiple of 100 paisa). Every computed amount goes through it exactly once.
 *
 * Ceiling (overflow): PHP ints are 64-bit (max ~9.22 x 10^18). Callers keep amounts at or below 10^12 paisa
 * (Rs 10 billion) and multiply them by at most 10^6 (days, minutes, percent, basis points), so a numerator stays at or
 * below 10^18. div() itself never overflows for any int numerator and any denominator up to MAX_DENOMINATOR. A product
 * that does overflow becomes a float in PHP and is refused by the int parameter (TypeError), never silently rounded.
 */
final class Money
{
    /** Largest amount (paisa) callers may multiply before dividing: Rs 10,000,000,000. */
    public const MAX_AMOUNT = 1_000_000_000_000;

    /** Largest multiplier callers may apply to an amount before dividing. */
    public const MAX_MULTIPLIER = 1_000_000;

    /** Largest denominator div() accepts (d x 100 x 2 must stay an int). */
    public const MAX_DENOMINATOR = 10_000_000_000_000;

    /**
     * $numerator / $denominator in paisa, rounded half up to a whole rupee (a multiple of 100).
     *
     * @throws InvalidArgumentException a negative numerator, or a denominator that is not positive or above MAX_DENOMINATOR
     */
    public static function div(int $numerator, int $denominator): int
    {
        if ($numerator < 0) {
            throw new InvalidArgumentException("Money::div needs a non-negative numerator [{$numerator}].");
        }

        if ($denominator <= 0 || $denominator > self::MAX_DENOMINATOR) {
            throw new InvalidArgumentException("Money::div needs a positive denominator up to 10^13 [{$denominator}].");
        }

        $unit = $denominator * 100;            // paisa per whole rupee of the quotient
        $rupees = intdiv($numerator, $unit);
        $remainder = $numerator - $rupees * $unit;

        return (2 * $remainder >= $unit ? $rupees + 1 : $rupees) * 100;
    }

    /** Whole rupees (an entered amount) in paisa. */
    public static function rupees(int $rupees): int
    {
        return $rupees * 100;
    }

    /**
     * "Rs 64,930" (whole rupees, thousands separated); "Rs 64,930.50" only when paisa appear; "-Rs 500" below zero.
     * The frontend's formatMoney() (resources/js/lib/payroll.ts) gives the same string.
     */
    public static function format(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $abs = abs($paisa);
        $rupees = intdiv($abs, 100);
        $cents = $abs % 100;

        $text = 'Rs '.number_format($rupees);

        if ($cents !== 0) {
            $text .= '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT);
        }

        return $sign.$text;
    }

    /**
     * A percentage as typed ("5", "5.25", "100") in basis points (500, 525, 10000): at most two decimals, 0 to 100.
     *
     * @throws InvalidArgumentException anything else (more decimals, above 100, a sign, spaces, empty)
     */
    public static function bp(string $percent): int
    {
        // \z, not $: $ also matches before a trailing newline ("5\n").
        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?\z/', $percent, $m) !== 1) {
            throw new InvalidArgumentException("Not a percentage with at most two decimals [{$percent}].");
        }

        $points = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        if ($points > 10_000) {
            throw new InvalidArgumentException("A percentage cannot be above 100 [{$percent}].");
        }

        return $points;
    }
}
