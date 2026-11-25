<?php

namespace App\Support\Attendance;

use App\Actions\Attendance\ApplyManualCorrection;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The one rule for "may this employee ask for a correction of this day". The store action, the Correction Requests
 * screen and My Attendance (to show the "Request correction" button only on eligible days) all use it.
 *
 *   $why = app(CorrectionEligibility::class)->reasonRefused($employee, '2026-11-20');   // null = may request
 *   $map = app(CorrectionEligibility::class)->refusals($employee, '2026-11-01', '2026-11-30'); // date => ?reason
 *
 * A day may be requested when it is:
 *   - a past attendance day (before AttendanceClock::attendanceToday()), no more than WINDOW_DAYS days back;
 *   - on or after the joining date (and on or before the last working day, when set);
 *   - on or after settings('attendance.start_date'), when set;
 *   - accepted by ApplyManualCorrection::employmentRefusal() (e.g. a leaver needs a last working day), so a request
 *     is never accepted that approval would always refuse (the wording here is the requester's own);
 *   - not already corrected by hand (is_manual);
 *   - without another open (pending or manager-approved) request of this employee.
 * "Own record only" is the caller's job: the request is always made for the signed-in user's own employee record.
 */
class CorrectionEligibility
{
    /** How many days back a correction may be requested. */
    public const WINDOW_DAYS = 31;

    /** Statuses of a request that is still open (one per employee per day). */
    public const OPEN_STATUSES = ['pending', 'manager_approved'];

    /** The longest range refusals() accepts. */
    public const MAX_RANGE_DAYS = 62;

    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly ApplyManualCorrection $manual,
    ) {}

    /** Why this day cannot be requested, or null when it can. */
    public function reasonRefused(Employee $employee, string $date): ?string
    {
        $date = self::validDate($date);

        if ($date === null) {
            return 'That is not a valid date.';
        }

        return $this->refusals($employee, $date, $date)[$date];
    }

    /**
     * reasonRefused() for every day from $from to $to (inclusive, "Y-m-d"), in two queries. The range may cover at
     * most MAX_RANGE_DAYS days (a month view needs 31); a longer one throws rather than being cut short silently.
     *
     *
     * @return array<string, string|null> date => reason (null = may request)
     *
     * @throws \InvalidArgumentException a date that is not "Y-m-d", or a range longer than MAX_RANGE_DAYS days
     */
    public function refusals(Employee $employee, string $from, string $to): array
    {
        $from = self::validDate($from) ?? throw new \InvalidArgumentException("Not a date [{$from}].");
        $to = self::validDate($to) ?? throw new \InvalidArgumentException("Not a date [{$to}].");

        if ($to >= $from && $to >= self::addDays($from, self::MAX_RANGE_DAYS)) {
            throw new \InvalidArgumentException('A range of at most '.self::MAX_RANGE_DAYS." days, not [{$from}..{$to}].");
        }

        $days = [];
        for ($d = $from; $d <= $to; $d = self::addDays($d, 1)) {
            $days[] = $d;
        }

        if ($days === []) {
            return [];
        }

        $last = end($days);

        $manual = Attendance::query()
            ->where('employee_id', $employee->id)
            ->betweenDates($from, $last)
            ->where('is_manual', true)
            ->pluck('date')
            ->map(fn (mixed $d): string => self::dateString($d))
            ->flip();

        $open = AttendanceCorrection::query()
            ->where('employee_id', $employee->id)
            ->betweenDates($from, $last)
            ->whereIn('status', self::OPEN_STATUSES)
            ->pluck('date')
            ->map(fn (mixed $d): string => self::dateString($d))
            ->flip();

        $result = [];
        foreach ($days as $day) {
            $result[$day] = $this->staticReason($employee, $day)
                ?? ($manual->has($day) ? 'This day has already been corrected by HR. Ask HR if it is still wrong.' : null)
                ?? ($open->has($day) ? 'There is already an open correction request for this day.' : null);
        }

        return $result;
    }

    /**
     * The requested times checked like a manual correction: "H:i" values; a clock-in at or after the day cutoff; a
     * clock-out after the clock-in, or after midnight before the cutoff. At least one time is required.
     *
     * @return array<string, string> field => message (empty when the times are usable)
     */
    public function timeErrors(?string $clockIn, ?string $clockOut): array
    {
        if ($clockIn === null && $clockOut === null) {
            return ['clock_in' => 'Enter the correct clock-in or clock-out time.'];
        }

        $cutoff = $this->clock->dayCutoff();

        if ($clockIn !== null && $clockIn < $cutoff) {
            return ['clock_in' => "The clock-in must be at or after the day cutoff ({$cutoff}). An earlier time belongs to the day before."];
        }

        if ($clockIn !== null && $clockOut !== null && $clockOut <= $clockIn && $clockOut >= $cutoff) {
            return ['clock_out' => "The clock-out must be after the clock-in. A clock-out after midnight must be before the day cutoff ({$cutoff})."];
        }

        return [];
    }

    /** The checks that need no query: the window, employment and the attendance start date. */
    private function staticReason(Employee $employee, string $date): ?string
    {
        $today = $this->clock->attendanceToday();

        if ($date >= $today) {
            return 'Only a past day can be corrected. Today can be corrected once it is over.';
        }

        if ($date < self::addDays($today, -self::WINDOW_DAYS)) {
            return 'A correction can be requested for the last '.self::WINDOW_DAYS.' days only. Ask HR for an older day.';
        }

        if ($date < $employee->joining_date->format('Y-m-d')) {
            return 'This day is before your joining date.';
        }

        $lastDay = $employee->last_working_day?->format('Y-m-d');
        if ($lastDay !== null && $date > $lastDay) {
            return 'This day is after your last working day.';
        }

        $start = settings('attendance.start_date');
        if (is_string($start) && self::validDate($start) !== null && $date < $start) {
            return 'Attendance was not yet recorded on this day.';
        }

        // The rest of the manual correction's employment check (approval runs it again), in the requester's words.
        if ($this->manual->employmentRefusal($employee, $date) !== null) {
            return 'Your employment record does not allow a correction of this day. Ask HR.';
        }

        return null;
    }

    public static function validDate(string $value): ?string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($value), new DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d') === trim($value) ? $parsed->format('Y-m-d') : null;
    }

    private static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC')))->modify(($days >= 0 ? '+' : '').$days.' day')->format('Y-m-d');
    }

    private static function dateString(mixed $value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }
}
