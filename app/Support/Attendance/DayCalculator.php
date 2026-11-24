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
 * Arithmetic is on whole minutes, rounded down from the seconds the machine records:
 *   - clock-in = first punch of the date, clock-out = last (only with two or more distinct punches); others are ignored;
 *   - late       when the whole minutes from shift start to clock-in are more than the grace; late minutes are counted
 *                from the shift start (16 for 09:16 with a 09:00 start and 15 minutes grace), 0 when not late;
 *   - early      whole minutes from clock-out to shift end, 0 when clock-out is at or after the end;
 *   - worked     whole minutes from clock-in to clock-out, 0 with one punch;
 *   - half day   two punches and worked minutes below half-day hours x 60 (beats late; late minutes are still kept);
 *   - overtime   whole minutes from shift end to clock-out (on a weekend or holiday: all worked minutes), kept only
 *                when at least overtimeMinMinutes, otherwise 0;
 *   - one punch  present or late by its time; needs attention only once the day is closed;
 *   - no punches absent once the day is closed; null (nothing to record yet) while it is open.
 *
 * Weekly off / holiday: status weekend / holiday, times recorded if there are punches, no late or early, worked minutes
 * count as overtime (same minimum). Exempt or on leave on a working day: present / on_leave with no late, early or
 * overtime and no attention flag; times and worked minutes are still recorded.
 */
final class DayCalculator
{
    private const PUNCH_FORMAT = 'Y-m-d H:i:s';

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

        if ($in->graceMinutes < 0 || $in->overtimeMinMinutes < 0) {
            throw new InvalidArgumentException('Grace and overtime minimum cannot be negative.');
        }

        if ($in->halfDayHours <= 0) {
            throw new InvalidArgumentException('Half-day hours must be more than 0.');
        }

        $punches = $this->punches($in, $zone);
        $first = $punches[0] ?? null;
        $last = count($punches) > 1 ? $punches[count($punches) - 1] : null;

        $clockIn = $first?->format(self::PUNCH_FORMAT);
        $clockOut = $last?->format(self::PUNCH_FORMAT);
        $worked = $first !== null && $last !== null ? $this->minutes($first, $last) : 0;
        $onePunch = $first !== null && $last === null;

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
                needsAttention: $onePunch && $in->closed,
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

        $lateBy = $first > $start ? $this->minutes($start, $first) : 0;
        $late = $lateBy > $in->graceMinutes ? $lateBy : 0;

        $early = 0;
        $overtime = 0;

        if ($last !== null) {
            $early = $last < $end ? $this->minutes($last, $end) : 0;
            $after = $last > $end ? $this->minutes($end, $last) : 0;
            $overtime = $after >= $in->overtimeMinMinutes ? $after : 0;
        }

        $status = match (true) {
            $last !== null && $worked < $in->halfDayHours * 60 => 'half_day',
            $late > 0 => 'late',
            default => 'present',
        };

        return new DayResult(
            status: $status,
            clockIn: $clockIn,
            clockOut: $clockOut,
            workedMinutes: $worked,
            lateMinutes: $late,
            earlyMinutes: $early,
            overtimeMinutes: $overtime,
            needsAttention: $onePunch && $in->closed,
        );
    }

    /**
     * The input's punches on its own date, parsed, de-duplicated and sorted.
     *
     * @return list<DateTimeImmutable>
     */
    private function punches(DayInput $in, DateTimeZone $zone): array
    {
        $byValue = [];

        foreach ($in->punches as $raw) {
            $value = substr(trim($raw), 0, 19);
            $parsed = DateTimeImmutable::createFromFormat('!'.self::PUNCH_FORMAT, $value, $zone);

            if ($parsed === false || $parsed->format(self::PUNCH_FORMAT) !== $value) {
                throw new InvalidArgumentException("Not a punch time [{$raw}].");
            }

            if (substr($value, 0, 10) === $in->date) {
                $byValue[$value] = $parsed;
            }
        }

        ksort($byValue, SORT_STRING); // "Y-m-d H:i:s" strings sort in time order

        return array_values($byValue);
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
