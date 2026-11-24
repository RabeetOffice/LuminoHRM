<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * A time of day in a TIME column, seen by the app as "H:i" (never with a date attached).
 *
 *   - set: "H:i" or "H:i:s" (00:00 to 23:59); stored as "H:i:00".
 *   - get / toArray(): "H:i".
 *
 * @implements CastsAttributes<string|null, mixed>
 */
class ClockTime implements CastsAttributes
{
    public const PATTERN = '/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 5);
    }

    /** @return array<string, string|null> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [$key => null];
        }

        if (! is_string($value) || preg_match(self::PATTERN, trim($value), $m) !== 1) {
            throw new InvalidArgumentException("The time for [{$key}] must be \"H:i\"; got ".get_debug_type($value).'.');
        }

        return [$key => "{$m[1]}:{$m[2]}:00"];
    }

    /** Minutes after midnight of an "H:i" or "H:i:s" value. */
    public static function minutes(string $time): int
    {
        if (preg_match(self::PATTERN, trim($time), $m) !== 1) {
            throw new InvalidArgumentException("Not a time of day [{$time}].");
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
