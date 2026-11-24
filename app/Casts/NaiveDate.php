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
 * A calendar date (an attendance day, a holiday) stored as exactly "Y-m-d".
 *
 * Unlike Laravel's `date` cast, which writes "Y-m-d 00:00:00" (so on SQLite where('date', '2026-11-24') would miss the
 * row), this always stores the bare date, so queries can compare with a plain "Y-m-d" string on every driver.
 *
 *   - set: "Y-m-d" (a longer "Y-m-d H:i:s" is cut to its date part) or a DateTimeInterface, which is first converted to
 *     the company zone (AttendanceClock::zone()), so now() on a server in another zone gives the company's date. A
 *     value read from this cast (or from AttendanceClock) is already in that zone: no change.
 *   - get: a NaiveDateValue (a CarbonImmutable) at 00:00 of that date, labelled with the company zone.
 *   - toArray(), and JSON of the value itself (only(), Inertia props): "Y-m-d".
 *
 * Query a date column with Model::onDate() / betweenDates() (App\Models\Concerns\HasNaiveDateScopes) or a "Y-m-d"
 * string, never where('date', $carbon): Laravel binds a Carbon as "Y-m-d H:i:s", which SQLite compares as text.
 *
 * @implements CastsAttributes<NaiveDateValue|null, DateTimeInterface|string|null>
 */
class NaiveDate implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?NaiveDateValue
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = NaiveDateValue::createFromFormat('!Y-m-d', substr((string) $value, 0, 10), app(AttendanceClock::class)->zone());

        if (! $parsed instanceof NaiveDateValue) {
            throw new InvalidArgumentException("Unreadable date [{$value}].");
        }

        return $parsed;
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => self::normalise($value)];
    }

    /** The stored "Y-m-d", read from $attributes (Eloquent passes an already serialised ISO instant as $value). */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        $raw = $attributes[$key] ?? null;

        return $raw === null || $raw === '' ? null : substr((string) $raw, 0, 10);
    }

    public static function normalise(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone(app(AttendanceClock::class)->zone())->format('Y-m-d');
        }

        if (is_string($value)) {
            $date = substr(trim($value), 0, 10);
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));

            // A longer value must be a date-time (the date part of "Y-m-d H:i:s"), never arbitrary trailing text.
            $rest = substr(trim($value), 10);

            if ($parsed !== false && $parsed->format('Y-m-d') === $date && ($rest === '' || NaiveDateTime::normalise(trim($value)) !== null)) {
                return $date;
            }
        }

        throw new InvalidArgumentException('A date must be "Y-m-d" or a DateTimeInterface; got '.get_debug_type($value).'.');
    }
}
