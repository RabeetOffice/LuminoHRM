<?php

namespace App\Casts;

use Carbon\CarbonImmutable;

/**
 * The value App\Casts\NaiveDateTime returns: a CarbonImmutable that serialises to JSON as its wall-clock
 * "Y-m-d H:i:s", so $model->only(['clock_in']) or a raw value in an Inertia prop never becomes a UTC instant.
 * Carbon's immutable operations (addMinutes, startOfDay, ...) return this same class. Everything else is plain Carbon.
 */
class NaiveDateTimeValue extends CarbonImmutable
{
    public function jsonSerialize(): mixed
    {
        return $this->format(NaiveDateTime::FORMAT);
    }
}
