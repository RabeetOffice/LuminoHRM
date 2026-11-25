<?php

namespace App\Support\Payroll;

use InvalidArgumentException;

/**
 * Tax-year maths. A tax year is named by its start year and starts in month $startMonth
 * (settings('payroll.tax_year_start_month'), default 7: Pakistan's July to June). A month belongs to the tax year that
 * started on or before it, so with July: 2026-07 .. 2027-06 is tax year 2026, shown "2026-27". Pure, no I/O.
 *
 *   TaxYear::of('2026-11');      // 2026
 *   TaxYear::of('2026-06');      // 2025
 *   TaxYear::months(2026);       // ['2026-07', ..., '2027-06']
 *   TaxYear::label(2026);        // "2026-27"
 *   TaxYear::parse('2026-27');   // 2026
 */
final class TaxYear
{
    /** The start year of the tax year holding $month ("Y-m"). */
    public static function of(string $month, int $startMonth = 7): int
    {
        self::assertStart($startMonth);
        [$year, $m] = self::split($month);

        return $m >= $startMonth ? $year : $year - 1;
    }

    /**
     * The twelve months of tax year $year, in order.
     *
     * @return list<string>
     */
    public static function months(int $year, int $startMonth = 7): array
    {
        self::assertStart($startMonth);
        self::assertYear($year);

        $months = [];
        for ($i = 0; $i < 12; $i++) {
            $index = $startMonth - 1 + $i;
            $months[] = sprintf('%04d-%02d', $year + intdiv($index, 12), $index % 12 + 1);
        }

        return $months;
    }

    /** "2026-27" for a tax year spanning two calendar years; "2026" when the tax year is the calendar year. */
    public static function label(int $year, int $startMonth = 7): string
    {
        self::assertStart($startMonth);
        self::assertYear($year);

        return $startMonth === 1 ? (string) $year : sprintf('%04d-%02d', $year, ($year + 1) % 100);
    }

    /** The start year named by a label as label() writes it ("2026-27"; "2026" when $startMonth is 1), else null. */
    public static function parse(string $label, int $startMonth = 7): ?int
    {
        self::assertStart($startMonth);
        $label = trim($label);

        if ($startMonth === 1) {
            return preg_match('/^\d{4}$/', $label) === 1 && (int) $label >= 1900 ? (int) $label : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})$/', $label, $m) !== 1) {
            return null;
        }

        $year = (int) $m[1];

        return $year >= 1900 && $year <= 9998 && self::label($year, $startMonth) === $label ? $year : null;
    }

    /** @return array{0: int, 1: int} */
    private static function split(string $month): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            throw new InvalidArgumentException("Not a month [{$month}].");
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private static function assertStart(int $startMonth): void
    {
        if ($startMonth < 1 || $startMonth > 12) {
            throw new InvalidArgumentException("Not a start month [{$startMonth}].");
        }
    }

    private static function assertYear(int $year): void
    {
        if ($year < 1900 || $year > 9998) {
            throw new InvalidArgumentException("Not a tax year [{$year}].");
        }
    }
}
