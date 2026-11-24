<?php

namespace App\Models\Concerns;

use App\Casts\NaiveDate;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Query scopes for a model whose `date` column uses App\Casts\NaiveDate (stored as a bare "Y-m-d").
 *
 * Laravel binds a Carbon as "Y-m-d H:i:s", so where('date', $carbon) finds nothing on SQLite and whereBetween('date',
 * [$from, $to]) with Carbons drops the first day. These scopes turn every value into the "Y-m-d" the cast would store
 * (NaiveDate::normalise: a DateTimeInterface is read in the company zone), so they behave the same on SQLite and MariaDB.
 *
 *   Attendance::query()->onDate($clock->today())->get();
 *   Holiday::query()->betweenDates($month->startOfMonth(), $month->endOfMonth())->get();   // both days included
 */
trait HasNaiveDateScopes
{
    /** @param  Builder<static>  $query */
    public function scopeOnDate(Builder $query, DateTimeInterface|string $date): void
    {
        $query->where($query->qualifyColumn('date'), self::naiveDate($date));
    }

    /** @param  Builder<static>  $query */
    public function scopeBetweenDates(Builder $query, DateTimeInterface|string $from, DateTimeInterface|string $to): void
    {
        $query->whereBetween($query->qualifyColumn('date'), [self::naiveDate($from), self::naiveDate($to)]);
    }

    private static function naiveDate(DateTimeInterface|string $value): string
    {
        $date = NaiveDate::normalise($value);

        if ($date === null) {
            throw new InvalidArgumentException('A date is required.');
        }

        return $date;
    }
}
