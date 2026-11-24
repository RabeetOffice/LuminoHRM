<?php

namespace App\Models;

use LogicException;
use Spatie\Activitylog\Models\Activity;

/**
 * An audit entry. Append-only from the app: the package inserts rows through this model,
 * and updating or deleting one through Eloquent throws. (Bulk query deletes bypass model events,
 * which is why nothing in the app issues them and the package's clean action is replaced.)
 */
class ActivityEntry extends Activity
{
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Audit log entries cannot be deleted.'));
    }
}
