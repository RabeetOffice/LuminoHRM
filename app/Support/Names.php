<?php

namespace App\Support;

/**
 * Shared handling of short display names (departments, designations).
 *
 * Uniqueness is decided here, in PHP and SQL, never by the database collation:
 * the tests run on SQLite and production on MariaDB, which compare case
 * differently.
 */
class Names
{
    /** Trim and collapse every run of whitespace (including tabs and non-breaking spaces) to one space. */
    public static function normalise(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** The lower-cased form used for comparing two names. */
    public static function key(string $value): string
    {
        return mb_strtolower((string) self::normalise($value));
    }
}
