<?php

namespace App\Support\Attendance;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * The day rules, pure: one DayInput in, one DayResult out (or null). No database, no settings, no clock: everything it
 * needs is in the input, so the same input always gives the same result.
 *
 * Status precedence: holiday > weekend > on_leave (full day) > half-day leave > (exempt: present) > punches
 * (half_day > late > present) > absent. (A manual day never reaches the calculator: the rebuild skips it.)
 *
 * The day's punches:
 *   - the punches given ARE the day's (the rebuild groups them by punch_date, which the day cutoff decided when each
 *     punch was stored); they are not filtered again, so a later cutoff change never makes stored punches vanish. A
 *     punch at 00:05 after a late night is this day's clock-out, with worked minutes counted across midnight;
 *   - equal punches count once; then, in time order, punches form a cluster while each is less than
 *     duplicatePunchMinutes after the previous RAW punch (09:00, 09:03, 09:06 with 5 minutes is one cluster). Each
 *     cluster counts as one punch: its first, except that with two or more clusters the last cluster gives its LAST
 *     punch, so the clock-in is the first tap of the first cluster and the clock-out the final tap of the last one;
 *   - two or more kept punches: clock-in = first, clock-out = last, the ones between are ignored;
 *   - exactly two kept punches less than 60 minutes apart on a working day: no real clock-out; decided by the
 *     single-punch rule on the first punch (never a half day). Machine punches only (closePairRule): times a person
 *     entered by hand are taken literally;
 *   - one kept punch: before the shift midpoint it is the clock-in (the late rule applies); at or after the midpoint it
 *     is the clock-out (never late). Either way the status is present / late and, once closed, needs attention. A
 *     single time a person entered (singleRole 'in' / 'out') keeps the role they gave it instead of the midpoint rule.
 *     On a half-leave working day the midpoint is that of the half actually worked: 'am' leave -> midpoint of [shift
 *     midpoint, end] (15:45 for 09:00-18:00), 'pm' leave -> midpoint of [start, shift midpoint] (11:15), so an am-leave
 *     person arriving at 13:45 is clocked in, not out.
 *
 * Arithmetic is on whole minutes, rounded down from the seconds the machine records:
 *   - late       when the WHOLE minutes from shift start to clock-in are more than the grace, so 09:15:59 is on time
 *                with a 09:00 start and 15 minutes grace and 09:16:00 is late; late minutes are counted from the shift
 *                start (16 for 09:16), 0 when not late, and 0 on a half day;
 *   - early      whole minutes from clock-out to shift end, 0 when clock-out is at or after the end; closed days only;
 *   - worked     whole minutes from clock-in to clock-out, 0 with one punch (computed across midnight when the
 *                clock-out is after it);
 *   - half day   closed day, two or more kept punches and worked minutes below half-day hours x 60;
 *   - overtime   whole minutes from max(shift end, clock-in) to clock-out, so never more than worked (on a weekend or
 *                holiday: all worked minutes), kept only
 *                when at least overtimeMinMinutes, otherwise 0;
 *   - attention  closed working day with one kept punch (including the close pair above);
 *   - no punches absent once the day is closed; null (nothing to record yet) while it is open.
 *
 * An open day (not closed yet) is never a half day and has no early minutes: its status is present or late from the
 * first punch only. Half day and early minutes are decided when the day is closed.
 *
 * Weekly off / holiday: status weekend / holiday, times recorded if there are punches, no late or early, worked minutes
 * count as overtime (same minimum), never flagged for attention. Exempt or on leave on a working day: present /
 * on_leave with no late, early or overtime and no attention flag; times and worked minutes are still recorded. Full
 * leave with punches stays on_leave (HR cancels the leave if the person really worked); it is recorded on an open day
 * too, since approved leave is known in advance. onLeave and leaveHalf both set: onLeave.
 *
 * Half-day leave (leaveHalf 'am' / 'pm', on a working day; ignored on a holiday or weekly off, result leaveHalf null):
 *   - the worked half must reach the half-day hours; lateness and early leaving are never counted (late 0, early 0);
 *     overtime by the normal rule; leaveHalf is carried into the result;
 *   - exempt: present;
 *   - open day: no punches -> null (waits like any open day); punches -> present;
 *   - closed day: two or more kept punches (after the double-tap and close-pair rules) and worked minutes at least
 *     halfDayHours x 60 -> present; fewer minutes, or no punches -> absent; a single kept punch -> absent and needs
 *     attention.
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

        if ($in->singleRole !== null && $in->singleRole !== 'in' && $in->singleRole !== 'out') {
            throw new InvalidArgumentException("Not a single-time role [{$in->singleRole}].");
        }

        if ($in->leaveHalf !== null && $in->leaveHalf !== 'am' && $in->leaveHalf !== 'pm') {
            throw new InvalidArgumentException("Not a leave half [{$in->leaveHalf}].");
        }

        // The cutoff is still validated (a caller passing garbage is a bug), but it no longer filters: the punches given
        // are this day's, decided by punch_date at ingestion. A later cutoff change must not make stored punches vanish.
        $this->at($in->date, $in->dayCutoff, $zone);

        $kept = $this->reduce($this->punches($in, $zone), $in->duplicatePunchMinutes);

        // Weekly off or holiday (holiday wins the status when both apply).
        $weekday = strtolower($day->format('l'));
        $offDay = in_array($weekday, array_map(fn (string $d) => strtolower(trim($d)), $in->weeklyOffs), true);
        $workingDay = ! $in->isHoliday && ! $offDay && ! $in->onLeave && ! $in->isExempt;

        // Exactly two kept punches less than an hour apart on a working day: no real clock-out (a slow double tap or a
        // forgotten punch). The day is decided by the single-punch rule on the first punch, never as a half day.
        $closePair = $in->closePairRule && $workingDay && count($kept) === 2
            && $kept[1]->getTimestamp() - $kept[0]->getTimestamp() < self::CLOSE_PAIR_SECONDS;

        if ($closePair) {
            $kept = [$kept[0]];
        }

        $first = $kept[0] ?? null;
        $last = count($kept) > 1 ? $kept[count($kept) - 1] : null;
        $onePunch = $first !== null && $last === null;

        // Half-day leave applies on a working day only (a holiday, weekly off or full leave day ignores it).
        $half = ($in->isHoliday || $offDay || $in->onLeave) ? null : $in->leaveHalf;

        // One punch at or after the midpoint is a clock-out (the clock-in is missing). The midpoint is the shift's, or on
        // a half-leave day the midpoint of the half actually worked: first half on leave ('am') -> the midpoint of
        // [shift midpoint, end]; second half on leave ('pm') -> the midpoint of [start, shift midpoint]. A time a person
        // entered alone keeps the role they gave it (singleRole), whatever the midpoint.
        $midpoint = $start->modify('+'.intdiv($end->getTimestamp() - $start->getTimestamp(), 2).' seconds');
        $midpoint = match ($half) {
            'am' => $midpoint->modify('+'.intdiv($end->getTimestamp() - $midpoint->getTimestamp(), 2).' seconds'),
            'pm' => $start->modify('+'.intdiv($midpoint->getTimestamp() - $start->getTimestamp(), 2).' seconds'),
            default => $midpoint,
        };
        $onlyOut = $onePunch && match ($in->singleRole) {
            'in' => false,
            'out' => true,
            default => $first >= $midpoint,
        };

        $clockIn = $onlyOut ? null : $first?->format(self::PUNCH_FORMAT);
        $clockOut = $onlyOut ? $first->format(self::PUNCH_FORMAT) : $last?->format(self::PUNCH_FORMAT);
        $worked = $first !== null && $last !== null ? $this->minutes($first, $last) : 0;

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

        // Half-day leave on a working day (a full leave day wins). The status describes the worked half.
        if ($half !== null) {
            return $this->halfLeave($in, $half, $first, $last, $onePunch, $clockIn, $clockOut, $worked, $end);
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
            // Overtime runs from the shift end, or from the clock-in when that is later: never more than worked.
            $overtimeFrom = $first > $end ? $first : $end;
            $after = $last > $overtimeFrom ? $this->minutes($overtimeFrom, $last) : 0;
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
            needsAttention: $in->closed && $onePunch,
        );
    }

    /**
     * A working day with half-day leave: the worked half needs the half-day hours. Never late, never early; overtime by
     * the normal rule. Null on an open day without punches (it waits).
     */
    private function halfLeave(
        DayInput $in,
        string $half,
        ?DateTimeImmutable $first,
        ?DateTimeImmutable $last,
        bool $onePunch,
        ?string $clockIn,
        ?string $clockOut,
        int $worked,
        DateTimeImmutable $end,
    ): ?DayResult {
        if ($first === null && ! $in->closed) {
            return null;
        }

        if ($in->isExempt) {
            return new DayResult('present', $clockIn, $clockOut, $worked, 0, 0, 0, false, $half);
        }

        $overtime = 0;

        if ($first !== null && $last !== null) {
            $overtimeFrom = $first > $end ? $first : $end;
            $after = $last > $overtimeFrom ? $this->minutes($overtimeFrom, $last) : 0;
            $overtime = $after >= $in->overtimeMinMinutes ? $after : 0;
        }

        $enough = $last !== null && $worked >= $in->halfDayHours * 60;

        return new DayResult(
            // An open day with punches is present (the worked half is decided when the day is closed).
            status: ! $in->closed || $enough ? 'present' : 'absent',
            clockIn: $clockIn,
            clockOut: $clockOut,
            workedMinutes: $worked,
            lateMinutes: 0,
            earlyMinutes: 0,
            overtimeMinutes: $overtime,
            needsAttention: $in->closed && $onePunch,
            leaveHalf: $half,
        );
    }

    /**
     * The input's punches, parsed, de-duplicated and sorted. All of them: the caller grouped them by attendance day.
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

            $byValue[$value] = $parsed;
        }

        ksort($byValue, SORT_STRING); // "Y-m-d H:i:s" strings sort in time order

        return array_values($byValue);
    }

    /**
     * Collapses double taps. Punches form a cluster while each is less than $minutes after the previous RAW punch (so
     * a chain of taps is one cluster). One punch is kept per cluster: its first, except that the last cluster keeps its
     * LAST punch when there are two or more clusters (the clock-out is the final tap: 17:58 + 18:01 gives 18:01).
     *
     * @param  list<DateTimeImmutable>  $sorted
     * @return list<DateTimeImmutable>
     */
    private function reduce(array $sorted, int $minutes): array
    {
        /** @var list<list<DateTimeImmutable>> $clusters */
        $clusters = [];
        $previous = null;

        foreach ($sorted as $punch) {
            if ($previous !== null && $punch->getTimestamp() - $previous->getTimestamp() < $minutes * 60) {
                $clusters[count($clusters) - 1][] = $punch;
            } else {
                $clusters[] = [$punch];
            }

            $previous = $punch;
        }

        $kept = [];
        $lastIndex = count($clusters) - 1;

        foreach ($clusters as $i => $cluster) {
            $kept[] = $i === $lastIndex && $i > 0 ? $cluster[count($cluster) - 1] : $cluster[0];
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
