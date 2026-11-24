<?php

namespace App\Casts;

use Carbon\CarbonImmutable;

/**
 * The value App\Casts\NaiveDate returns: a CarbonImmutable that serialises to JSON as its bare "Y-m-d" date, so
 * $model->only(['date']) or a raw $row->date in an Inertia prop never becomes a UTC instant (the previous day).
 * Carbon's immutable operations (addDay, startOfMonth, ...) return this same class. Everything else is plain Carbon.
 */
class NaiveDateValue extends CarbonImmutable
{
    public function jsonSerialize(): mixed
    {
        return $this->format('Y-m-d');
    }
}
