<?php

namespace App\Support\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The day rules, pure: one DayInput in, one DayResult out (or null). No database, no settings, no clock: everything it
 * needs is in the input, so the same input always gives the same result.
 *
 * Status precedence: holiday > weekend > on_leave > (exempt: present) > punches (half_day > late > present) > absent.
 * (A manual day never reaches the calculator: the rebuild skips it.)
 *
 * The day's punches:
 *   - the day runs from dayCutoff on `date` to dayCutoff on the next calendar day (04:00 by default), so a punch at
 *     00:05 after a late night is this day's clock-out; punches outside that window are ignored;
 *   - equal punches count once; then, in time order, a punch less than duplicatePunchMinutes after the previously
 *     KEPT punch is a double tap and is dropped (09:00, 09:04, 09:08 with 5 minutes keeps 09:00 and 09:08);
 *   - two or more kept punches: clock-in = first, clock-out = last, the ones between are ignored;
 *   - one kept punch: before the shift midpoint it is the clock-in (the late rule applies); at or after the midpoint it
 *     is the clock-out (never late). Either way the status is present / late and, once closed, needs attention.
 *
 * Arithmetic is on whole minutes, rounded down from the seconds the machine records:
 *   - late       when the WHOLE minutes from shift start to clock-in are more than the grace, so 09:15:59 is on time
 *                with a 09:00 start and 15 minutes grace and 09:16:00 is late; late minutes are counted from the shift
 *                start (16 for 09:16), 0 when not late, and 0 on a half day;
 *   - early      whole minutes from clock-out to shift end, 0 when clock-out is at or after the end; closed days only;
 *   - worked     whole minutes from clock-in to clock-out, 0 with one punch (computed across midnight when the
 *                clock-out is after it);
 *   - half day   closed day, two or more kept punches and worked minutes below half-day hours x 60;
 *   - overtime   whole minutes from shift end to clock-out (on a weekend or holiday: all worked minutes), kept only
 *                when at least overtimeMinMinutes, otherwise 0;
 *   - attention  closed working day with exactly one kept punch, or exactly two kept punches less than 60 minutes
 *                apart (a double tap the window did not catch, or a forgotten punch);
 *   - no punches absent once the day is closed; null (nothing to record yet) while it is open.
 *
 * An open day (not closed yet) is never a half day and has no early minutes: its status is present or late from the
 * first punch only. Half day and early minutes are decided when the day is closed.
 *
 * Weekly off / holiday: status weekend / holiday, times recorded if there are punches, no late or early, worked minutes
 * count as overtime (same minimum), never flagged for attention. Exempt or on leave on a working day: present /
 * on_leave with no late, early or overtime and no attention flag; times and worked minutes are still recorded.
 */
final class DayCalculator
{
    private const PUNCH_FORMAT = 'Y-m-d H:i:s';

    /** Two kept punches closer than this (seconds) on a closed working day are flagged for attention. */
    private const CLOSE_PAIR_SECONDS = 3600;

    public function calculate(DayInput $in): ?DayResult
    {
        $zone = new DateTimeZone('UTC'); // a label only: every value here is a naive wall-clock time

        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $in->date, $zone);
        if ($day === false || $day->format('Y-m-d') !== $in->date) {
            throw new InvalidArgumentException("Not a date [{$in->date}].");
        }

        $start = $this->at($in->date, $in->shiftStart, $zone);
        $end = $this->at($in->date, $in->shiftEnd, $zone);

        if ($end <= $start) {
            throw new InvalidArgumentException('A shift must end after it starts on the same day.');
        }

        if ($in->graceMinutes < 0 || $in->overtimeMinMinutes < 0 || $in->duplicatePunchMinutes < 0) {
            throw new InvalidArgumentException('Grace, overtime minimum and duplicate window cannot be negative.');
        }

        if ($in->halfDayHours <= 0) {
            throw new InvalidArgumentException('Half-day hours must be more than 0.');
        }

        $windowStart = $this->at($in->date, $in->dayCutoff, $zone);
        $windowEnd = $windowStart->modify('+1 day');

        $kept = $this->reduce($this->punches($in, $windowStart, $windowEnd, $zone), $in->duplicatePunchMinutes);
        $first = $kept[0] ?? null;
        $last = count($kept) > 1 ? $kept[count($kept) - 1] : null;
        $onePunch = $first !== null && $last === null;
        $closePair = isset($kept[1]) && count($kept) === 2
            && $kept[1]->getTimestamp() - $kept[0]->getTimestamp() < self::CLOSE_PAIR_SECONDS;

        // One punch at or after the shift midpoint is a clock-out (the clock-in is missing).
        $midpoint = $start->modify('+'.intdiv($end->getTimestamp() - $start->getTimestamp(), 2).' seconds');
        $onlyOut = $onePunch && $first >= $midpoint;

        $clockIn = $onlyOut ? null : $first?->format(self::PUNCH_FORMAT);
        $clockOut = $onlyOut ? $first->format(self::PUNCH_FORMAT) : $last?->format(self::PUNCH_FORMAT);
        $worked = $first !== null && $last !== null ? $this->minutes($first, $last) : 0;

        // Weekly off or holiday (holiday wins the status when both apply).
        $weekday = strtolower($day->format('l'));
        $offDay = in_array($weekday, array_map(fn (string $d) => strtolower(trim($d)), $in->weeklyOffs), true);

        if ($in->isHoliday || $offDay) {
            return new DayResult(
                status: $in->isHoliday ? 'holiday' : 'weekend',
                clockIn: $clockIn,
                clockOut: $clockOut,
                workedMinutes: $worked,
                lateMinutes: 0,
                earlyMinutes: 0,
                overtimeMinutes: $worked >= $in->overtimeMinMinutes ? $worked : 0,
                needsAttention: false,
            );
        }

        // Approved leave is known in advance, so it is recorded on an open day too.
        // A working day with nothing on it otherwise: absent once it is over, nothing yet while it is open.
        if ($first === null && ! $in->closed && ! $in->onLeave) {
            return null;
        }

        if ($in->onLeave || $in->isExempt) {
            return new DayResult(
                status: $in->onLeave ? 'on_leave' : 'present',
                clockIn: $clockIn,
                clockOut: $clockOut,
                workedMinutes: $worked,
                lateMinutes: 0,
                earlyMinutes: 0,
                overtimeMinutes: 0,
                needsAttention: false,
            );
        }

        if ($first === null) {
            return new DayResult('absent', null, null, 0, 0, 0, 0, false);
        }

        if ($onlyOut) {
            return new DayResult('present', null, $clockOut, 0, 0, 0, 0, $in->closed);
        }

        // Whole minutes only: the seconds of the clock-in are dropped before comparing with the grace.
        $lateBy = $first > $start ? $this->minutes($start, $first) : 0;
        $late = $lateBy > $in->graceMinutes ? $lateBy : 0;

        $early = 0;
        $overtime = 0;

        if ($last !== null) {
            $early = $in->closed && $last < $end ? $this->minutes($last, $end) : 0;
            $after = $last > $end ? $this->minutes($end, $last) : 0;
            $overtime = $after >= $in->overtimeMinMinutes ? $after : 0;
        }

        $halfDay = $in->closed && $last !== null && $worked < $in->halfDayHours * 60;

        return new DayResult(
            status: match (true) {
                $halfDay => 'half_day',
                $late > 0 => 'late',
                default => 'present',
            },
            clockIn: $clockIn,
            clockOut: $clockOut,
            workedMinutes: $worked,
            lateMinutes: $halfDay ? 0 : $late,
            earlyMinutes: $early,
            overtimeMinutes: $overtime,
            needsAttention: $in->closed && ($onePunch || $closePair),
        );
    }

    /**
     * The input's punches inside [$from, $to), parsed, de-duplicated and sorted.
     *
     * @return list<DateTimeImmutable>
     */
    private function punches(DayInput $in, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $zone): array
    {
        $byValue = [];

        foreach ($in->punches as $raw) {
            $value = substr(trim($raw), 0, 19);
            $parsed = DateTimeImmutable::createFromFormat('!'.self::PUNCH_FORMAT, $value, $zone);

            if ($parsed === false || $parsed->format(self::PUNCH_FORMAT) !== $value) {
                throw new InvalidArgumentException("Not a punch time [{$raw}].");
            }

            if ($parsed >= $from && $parsed < $to) {
                $byValue[$value] = $parsed;
            }
        }

        ksort($byValue, SORT_STRING); // "Y-m-d H:i:s" strings sort in time order

        return array_values($byValue);
    }

    /**
     * Drops double taps: a punch less than $minutes after the previously kept punch.
     *
     * @param  list<DateTimeImmutable>  $sorted
     * @return list<DateTimeImmutable>
     */
    private function reduce(array $sorted, int $minutes): array
    {
        $kept = [];

        foreach ($sorted as $punch) {
            $previous = $kept === [] ? null : $kept[count($kept) - 1];

            if ($previous !== null && $punch->getTimestamp() - $previous->getTimestamp() < $minutes * 60) {
                continue;
            }

            $kept[] = $punch;
        }

        return $kept;
    }

    /** $time ("H:i" or "H:i:s") on $date. */
    private function at(string $date, string $time, DateTimeZone $zone): DateTimeImmutable
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($time), $m) !== 1) {
            throw new InvalidArgumentException("Not a time of day [{$time}].");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', "{$date} {$m[1]}:{$m[2]}:".($m[3] ?? '00'), $zone);

        if ($parsed === false) {
            throw new InvalidArgumentException("Not a time of day [{$time}].");
        }

        return $parsed;
    }

    /** Whole minutes from $from to $to (rounded down; $to is not before $from). */
    private function minutes(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
    }
}
