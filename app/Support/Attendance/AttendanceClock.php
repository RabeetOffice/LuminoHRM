<?php

namespace App\Support\Attendance;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Exception;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The one source of "now" and "which calendar day is it" for attendance. Nothing in attendance uses raw now(), today()
 * or the browser's zone: it asks this class, resolved from the container (app(AttendanceClock::class)).
 *
 *   $clock = app(AttendanceClock::class);
 *   $clock->zone();                         // DateTimeZone of settings('company.timezone'), else config('app.timezone')
 *   $clock->now();                          // CarbonImmutable, wall-clock in that zone
 *   $clock->today();                        // CarbonImmutable at 00:00 of the current day in that zone
 *   $clock->utcOffsetHours();               // 5 for Asia/Karachi, 5.5 for Asia/Kolkata (machine handshake "TimeZone=")
 *   $clock->toDateString('2026-11-24 23:59:59');   // "2026-11-24": the date part of a naive value, never shifted
 *
 * It keeps no state: the setting is read on every call (Settings caches per request), and Laravel's test time travel
 * (travelTo, Carbon::setTestNow) is honoured because "now" comes from the Date facade.
 */
class AttendanceClock
{
    /** The default day cutoff, used when the setting is missing or not a valid "H:i". */
    public const DEFAULT_CUTOFF = '04:00';

    public function zone(): DateTimeZone
    {
        $name = settings('company.timezone');

        if (is_string($name) && trim($name) !== '') {
            try {
                return new DateTimeZone(trim($name));
            } catch (Exception) {
                // Unusable setting (hand-edited row): fall back below.
            }
        }

        $fallback = config('app.timezone');

        try {
            return new DateTimeZone(is_string($fallback) && $fallback !== '' ? $fallback : 'UTC');
        } catch (Exception) {
            return new DateTimeZone('UTC');
        }
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::instance(Date::now())->setTimezone($this->zone());
    }

    public function today(): CarbonImmutable
    {
        return $this->now()->startOfDay();
    }

    /** The zone's current offset from UTC in hours: an int when whole (5), otherwise a float (5.5, -3.5, 5.75). */
    public function utcOffsetHours(): int|float
    {
        $seconds = $this->now()->getOffset();

        return $seconds % 3600 === 0 ? intdiv($seconds, 3600) : $seconds / 3600;
    }

    /**
     * The "Y-m-d" date part of a naive value (a punch time as the machine recorded it), with no zone conversion:
     * a DateTimeInterface gives its own wall-clock date; a string must start with a valid "Y-m-d" and be a date or a
     * "Y-m-d H:i[:s]" date-time.
     */
    public function toDateString(DateTimeInterface|string $naive): string
    {
        if ($naive instanceof DateTimeInterface) {
            return $naive->format('Y-m-d');
        }

        $value = trim($naive);

        foreach (['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, new DateTimeZone('UTC'));

            if ($parsed !== false && $parsed->format(substr($format, 1)) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        throw new InvalidArgumentException("Not a naive date or date-time [{$naive}].");
    }

    /**
     * The attendance day ("Y-m-d") a punch belongs to: its own date, or the day before when its time is before the day
     * cutoff (settings('attendance.day_cutoff_time'), "04:00" by default), because a punch at 00:05 is the previous
     * day's clock-out after a late night. No zone conversion. Every place that derives a day from a punch uses this.
     *
     *   $clock->attendanceDateFor('2026-11-25 00:05:00');   // "2026-11-24" with the 04:00 cutoff
     *   $clock->attendanceDateFor('2026-11-25 04:00:00');   // "2026-11-25"
     */
    public function attendanceDateFor(string $naiveDateTime): string
    {
        return self::dayOf($naiveDateTime, $this->dayCutoff());
    }

    /** The day cutoff as "H:i" (settings('attendance.day_cutoff_time'); "04:00" when unset or unusable). */
    public function dayCutoff(): string
    {
        $cutoff = settings('attendance.day_cutoff_time');

        return is_string($cutoff) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $cutoff) === 1 ? $cutoff : self::DEFAULT_CUTOFF;
    }

    /** attendanceDateFor() with an explicit cutoff ("H:i"), for callers that read the setting once for many punches. */
    public static function dayOf(string $naiveDateTime, string $cutoff): string
    {
        $value = trim($naiveDateTime);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', substr($value, 0, 19), new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d H:i:s') !== substr($value, 0, 19)) {
            throw new InvalidArgumentException("Not a naive date-time [{$naiveDateTime}].");
        }

        // "H:i:s" strings compare in time order.
        return $parsed->format('H:i:s') < $cutoff.':00'
            ? $parsed->modify('-1 day')->format('Y-m-d')
            : $parsed->format('Y-m-d');
    }
}
