<?php

namespace App\Support\Performance;

use App\Models\ReviewCycle;
use App\Support\Settings;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The calendar periods a review cycle can cover: quarters (Jan to Mar, Apr to Jun, Jul to Sep, Oct to Dec) or whole months.
 * Pure: "Y-m-d" strings in, "Y-m-d" strings out, no clock and no database (type() reads the setting). The caller passes
 * "today" (AttendanceClock::attendanceToday()).
 *
 *   ReviewPeriods::find('quarterly', '2026-Q3', '2026-11-26');
 *   // ['key' => '2026-Q3', 'label' => 'Q3 2026 (Jul–Sep)', 'start' => '2026-07-01', 'end' => '2026-09-30']
 *   ReviewPeriods::find('monthly', '2026-11', '2026-11-26');
 *   // ['key' => '2026-11', 'label' => 'November 2026', 'start' => '2026-11-01', 'end' => '2026-11-30']
 *
 * A period can be chosen when it has started (start on or before today), ended or not, and is one of the current and the
 * last eight periods of its type (OFFERED_BACK); the server accepts only a key it would offer, and rebuilds the dates itself.
 */
class ReviewPeriods
{
    /** How many periods before the current one are offered. */
    public const OFFERED_BACK = 8;

    /** The setting: 'quarterly' or 'monthly' (anything else reads as 'quarterly'). */
    public static function type(): string
    {
        return Settings::get('performance.review_period') === 'monthly' ? 'monthly' : 'quarterly';
    }

    /**
     * The current and the last eight periods of $type, newest first, without those overlapping a cycle in $takenCycles
     * (ReviewCycle models, or arrays with 'start' and 'end' as "Y-m-d").
     *
     * @param  iterable<ReviewCycle|array{start: string, end: string}>  $takenCycles
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    public static function offered(string $type, string $today, iterable $takenCycles): array
    {
        $taken = [];

        foreach ($takenCycles as $cycle) {
            $taken[] = $cycle instanceof ReviewCycle
                ? [$cycle->period_start->format('Y-m-d'), $cycle->period_end->format('Y-m-d')]
                : [(string) $cycle['start'], (string) $cycle['end']];
        }

        $offered = [];

        foreach (self::window($type, $today) as $period) {
            foreach ($taken as [$start, $end]) {
                if (self::overlaps($period['start'], $period['end'], $start, $end)) {
                    continue 2;
                }
            }

            $offered[] = $period;
        }

        return $offered;
    }

    /**
     * The period of $key when it is a well-formed key of $type that would be offered today (not in the future, not older
     * than the window); null otherwise. Overlap with an existing cycle is not checked here.
     *
     * @return array{key: string, label: string, start: string, end: string}|null
     */
    public static function find(string $type, string $key, string $today): ?array
    {
        $period = self::parse($type, $key);

        if ($period === null) {
            return null;
        }

        foreach (self::window($type, $today) as $candidate) {
            if ($candidate['key'] === $period['key']) {
                return $period;
            }
        }

        return null;
    }

    /**
     * The period of a well-formed $key of $type, with no date rule applied (a future or an old key parses too); null for
     * a key that is not one of $type's shape.
     *
     * @return array{key: string, label: string, start: string, end: string}|null
     */
    public static function parse(string $type, string $key): ?array
    {
        if ($type === 'quarterly' && preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) === 1) {
            return self::quarter((int) $m[1], (int) $m[2]);
        }

        if ($type === 'monthly' && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $key, $m) === 1) {
            return self::month((int) $m[1], (int) $m[2]);
        }

        return null;
    }

    /** Whether $key's period starts after $today (a well-formed key only). */
    public static function isFuture(string $type, string $key, string $today): bool
    {
        $period = self::parse($type, $key);

        return $period !== null && $period['start'] > $today;
    }

    /** The default due date: the period end plus config('hrm.performance.default_due_days') days. */
    public static function dueDefault(string $periodEnd): string
    {
        $days = (int) config('hrm.performance.default_due_days', 14);

        return self::day($periodEnd)->modify("+{$days} days")->format('Y-m-d');
    }

    /** Whether the two inclusive date ranges share at least one day. */
    public static function overlaps(string $start, string $end, string $otherStart, string $otherEnd): bool
    {
        return $start <= $otherEnd && $otherStart <= $end;
    }

    /**
     * The current period of $type and the eight before it, newest first.
     *
     * @return list<array{key: string, label: string, start: string, end: string}>
     */
    private static function window(string $type, string $today): array
    {
        $day = self::day($today);
        $year = (int) $day->format('Y');
        $month = (int) $day->format('n');

        $periods = [];

        if ($type === 'quarterly') {
            $quarter = intdiv($month - 1, 3) + 1;

            for ($i = 0; $i <= self::OFFERED_BACK; $i++) {
                $periods[] = self::quarter($year, $quarter);

                if (--$quarter === 0) {
                    $quarter = 4;
                    $year--;
                }
            }

            return $periods;
        }

        if ($type === 'monthly') {
            for ($i = 0; $i <= self::OFFERED_BACK; $i++) {
                $periods[] = self::month($year, $month);

                if (--$month === 0) {
                    $month = 12;
                    $year--;
                }
            }
        }

        return $periods;
    }

    /** @return array{key: string, label: string, start: string, end: string} */
    private static function quarter(int $year, int $quarter): array
    {
        $first = ($quarter - 1) * 3 + 1;
        $start = self::firstOfMonth($year, $first);
        $last = self::firstOfMonth($year, $first + 2);

        return [
            'key' => sprintf('%04d-Q%d', $year, $quarter),
            'label' => sprintf('Q%d %d (%s–%s)', $quarter, $year, $start->format('M'), $last->format('M')),
            'start' => $start->format('Y-m-d'),
            'end' => $last->format('Y-m-t'),
        ];
    }

    /** @return array{key: string, label: string, start: string, end: string} */
    private static function month(int $year, int $month): array
    {
        $start = self::firstOfMonth($year, $month);

        return [
            'key' => sprintf('%04d-%02d', $year, $month),
            'label' => $start->format('F Y'),
            'start' => $start->format('Y-m-d'),
            'end' => $start->format('Y-m-t'),
        ];
    }

    private static function firstOfMonth(int $year, int $month): DateTimeImmutable
    {
        return self::day(sprintf('%04d-%02d-01', $year, $month));
    }

    /** A "Y-m-d" as a date at midnight UTC: used for calendar arithmetic only, never shifted. */
    private static function day(string $ymd): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $ymd) {
            throw new \InvalidArgumentException("Not a date [{$ymd}].");
        }

        return $parsed;
    }
}
