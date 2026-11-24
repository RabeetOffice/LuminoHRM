<?php

namespace App\Casts;

use App\Support\Attendance\AttendanceClock;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A wall-clock date and time with no zone of its own (a punch as the machine recorded it), stored in a DATETIME column
 * as exactly the "Y-m-d H:i:s" that was written. A stored or read value is never shifted:
 *
 *   - set: a string must be "Y-m-d H:i:s" (or "Y-m-d H:i", seconds become :00) and is stored character for character;
 *     a DateTimeInterface is first converted to the company zone (AttendanceClock::zone()) and stored as its wall clock
 *     there, so now() on a server in another zone stores the company's time. A value read from this cast (or from
 *     AttendanceClock) is already in that zone, so it is stored unchanged.
 *   - get: a NaiveDateTimeValue (a CarbonImmutable) whose wall-clock fields are the stored ones, labelled with the company zone
 *     (AttendanceClock::zone()) so it compares correctly with AttendanceClock::now(). The label is never used to shift
 *     the value: ->format('Y-m-d H:i:s') always gives back the stored string, whatever config('app.timezone') is.
 *   - toArray(), and JSON of the value itself (only(), Inertia props): the stored "Y-m-d H:i:s" string (not an ISO
 *     instant in UTC).
 *
 * Never call ->setTimezone() on a value read from this cast: its wall clock is the fact, the label only lets it compare
 * with AttendanceClock::now(). Compute durations from two cast values (both carry the same label).
 *
 * The raw column value (getRawOriginal / getAttributes) is always the exact stored string.
 *
 * @implements CastsAttributes<NaiveDateTimeValue|null, DateTimeInterface|string|null>
 */
class NaiveDateTime implements CastsAttributes, SerializesCastableAttributes
{
    public const FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?NaiveDateTimeValue
    {
        if ($value === null || $value === '') {
            return null;
        }

        return self::parse((string) $value, app(AttendanceClock::class)->zone());
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => self::normalise($value)];
    }

    /**
     * Eloquent hands this method a value it has already turned into an ISO instant (serializeDate), so the stored
     * string is read from $attributes instead.
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $raw = $attributes[$key] ?? null;

        return $raw === null || $raw === '' ? null : substr((string) $raw, 0, 19);
    }

    /** The "Y-m-d H:i:s" string to store for $value, or null. Throws on anything else. */
    public static function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(app(AttendanceClock::class)->zone())->format(self::FORMAT);
        }

        if (is_string($value)) {
            $value = trim($value);

            foreach (['!Y-m-d H:i:s', '!Y-m-d H:i'] as $format) {
                $parsed = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone('UTC'));

                if ($parsed !== false && $parsed->format(substr($format, 1)) === $value) {
                    return $parsed->format(self::FORMAT);
                }
            }
        }

        throw new InvalidArgumentException('A naive date-time must be "Y-m-d H:i:s" or a DateTimeInterface; got '.get_debug_type($value).'.');
    }

    /** Builds the value from a stored string, keeping its wall-clock fields exactly. */
    public static function parse(string $stored, \DateTimeZone $zone): NaiveDateTimeValue
    {
        // Some drivers return fractional seconds ("2026-11-24 09:00:00.000000"); only the first 19 characters count.
        $wall = substr($stored, 0, 19);
        $parsed = NaiveDateTimeValue::createFromFormat('!'.self::FORMAT, $wall, $zone);

        if (! $parsed instanceof NaiveDateTimeValue) {
            throw new InvalidArgumentException("Unreadable naive date-time [{$stored}].");
        }

        // A wall-clock time that does not exist in the zone (a daylight-saving gap) would be moved by PHP; keep the
        // stored fields instead, labelled UTC. Never happens for Asia/Karachi (no DST). Such a value written back would
        // be converted to the company zone by normalise(); daylight saving is out of scope for Phase 3.
        if ($parsed->format(self::FORMAT) !== $wall) {
            $parsed = NaiveDateTimeValue::createFromFormat('!'.self::FORMAT, $wall, 'UTC');

            if (! $parsed instanceof NaiveDateTimeValue) {
                throw new InvalidArgumentException("Unreadable naive date-time [{$stored}].");
            }
        }

        return $parsed;
    }
}
