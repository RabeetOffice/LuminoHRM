<?php

namespace App\Support\Exits;

use App\Models\Resignation;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Notice given against the notice required. Calendar days from the day the exit was submitted to the last working day,
 * both included, so a resignation that ends the same day it is submitted gives 1 day. No money is involved: an
 * automatic deduction is not built (HR adds a "Notice shortfall" line to the settlement if they want one).
 */
final class ExitNotice
{
    /** Days of notice given: submitted_on to last_working_day, both "Y-m-d", both included. Never below 0. */
    public static function given(string $submittedOn, string $lastDay): int
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $submittedOn);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $lastDay);
        } catch (Throwable) {
            return 0;
        }

        if ($to < $from) {
            return 0;
        }

        return (int) $from->diffInDays($to) + 1;
    }

    /** Days the notice falls short of what was required (0 when it is enough). */
    public static function shortBy(int $required, int $given): int
    {
        return max(0, $required - $given);
    }

    /** "30 days notice" or "12 days short of notice (30 required)" ("1 day" in the singular). */
    public static function label(Resignation $resignation): string
    {
        $given = self::given($resignation->submitted_on->format('Y-m-d'), $resignation->last_working_day->format('Y-m-d'));
        $required = (int) $resignation->notice_period_days;
        $short = self::shortBy($required, $given);

        if ($short === 0) {
            return $given.' '.($given === 1 ? 'day' : 'days').' notice';
        }

        return $short.' '.($short === 1 ? 'day' : 'days')." short of notice ({$required} required)";
    }
}
