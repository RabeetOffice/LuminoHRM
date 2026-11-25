<?php

namespace App\Console\Commands;

use App\Http\Controllers\Settings\DeviceController;
use App\Models\DeviceLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

/**
 * device_logs keeps every /iclock request (accepted and rejected) for 7 days, which is what Settings > ZKTeco Device
 * shows as the sync log. Older rows are deleted a batch at a time so a large backlog never holds a long lock.
 */
class PruneDeviceLogs extends Command
{
    protected $signature = 'hrm:prune-device-logs';

    protected $description = 'Delete machine request logs (device_logs) older than 7 days';

    private const BATCH = 5000;

    public function handle(): int
    {
        $cutoff = Date::now()->subDays(DeviceController::LOG_DAYS);
        $deleted = 0;

        do {
            $ids = DeviceLog::query()->where('created_at', '<', $cutoff)->orderBy('id')->limit(self::BATCH)->pluck('id');

            if ($ids->isNotEmpty()) {
                $deleted += DeviceLog::query()->whereIn('id', $ids->all())->delete();
            }
        } while ($ids->count() === self::BATCH);

        $this->info("Deleted {$deleted} machine request log row(s) older than ".DeviceController::LOG_DAYS.' days.');

        return self::SUCCESS;
    }
}
