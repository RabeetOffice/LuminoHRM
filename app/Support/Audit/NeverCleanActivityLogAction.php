<?php

namespace App\Support\Audit;

use Spatie\Activitylog\Actions\CleanActivityLogAction;

/**
 * Replaces the package's clean action so `activitylog:clean` can never remove an audit entry.
 */
class NeverCleanActivityLogAction extends CleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        return 0;
    }
}
