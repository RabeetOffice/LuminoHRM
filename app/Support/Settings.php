<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Company-wide rules, stored in the `settings` table and read at runtime
 * (never baked into the config cache).
 *
 *   Settings::get('attendance.grace_minutes');       // saved value, else config('hrm.defaults'), else $default
 *   Settings::set('attendance', ['grace_minutes' => 10]);
 *   Settings::group('attendance');                    // every declared key, saved or default
 *
 * A key exists only if it is declared in config('hrm.defaults'). Values keep their JSON type.
 */
class Settings
{
    public static function get(string $dotted, mixed $default = null): mixed
    {
        [$group, $key] = array_pad(explode('.', $dotted, 2), 2, null);

        if ($group === null || $key === null) {
            return $default;
        }

        $declared = static::declared($group);

        if (! array_key_exists($key, $declared)) {
            return $default;
        }

        $saved = static::store()->all()[$group] ?? [];

        return array_key_exists($key, $saved) && static::sameType($declared[$key], $saved[$key])
            ? $saved[$key]
            : $declared[$key];
    }

    /**
     * Saves the declared keys in $values and ignores the rest. A string
     * from a form is cast to the type of the declared default (int, bool, string).
     *
     * @param  array<string, mixed>  $values
     */
    public static function set(string $group, array $values): void
    {
        $declared = static::declared($group);
        $accepted = array_intersect_key($values, $declared);

        if ($accepted === []) {
            return;
        }

        DB::transaction(function () use ($group, $accepted, $declared): void {
            foreach ($accepted as $key => $value) {
                Setting::query()->updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => json_encode(static::cast($declared[$key], $value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                );
            }
        });

        static::store()->forget();
    }

    /**
     * Every declared key of a group: the saved value where there is one, else the default.
     *
     * @return array<string, mixed>
     */
    public static function group(string $group): array
    {
        $saved = static::store()->all()[$group] ?? [];

        $declared = static::declared($group);

        foreach ($declared as $key => $default) {
            if (array_key_exists($key, $saved) && static::sameType($default, $saved[$key])) {
                $declared[$key] = $saved[$key];
            }
        }

        return $declared;
    }

    /** A saved value of the wrong type (hand-edited row) is ignored in favour of the default. */
    protected static function sameType(mixed $default, mixed $saved): bool
    {
        return match (true) {
            is_int($default) => is_int($saved),
            is_bool($default) => is_bool($saved),
            is_array($default) => is_array($saved),
            is_string($default) => is_string($saved),
            default => true,
        };
    }

    /** @return array<string, mixed> */
    protected static function declared(string $group): array
    {
        $declared = config("hrm.defaults.$group", []);

        return is_array($declared) ? $declared : [];
    }

    protected static function cast(mixed $default, mixed $value): mixed
    {
        return match (true) {
            is_int($default) => is_numeric($value) ? (int) $value : $value,
            is_bool($default) => is_bool($value) ? $value : (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value),
            is_string($default) => $value === null ? '' : $value,
            default => $value,
        };
    }

    protected static function store(): SettingsStore
    {
        return app(SettingsStore::class);
    }
}
