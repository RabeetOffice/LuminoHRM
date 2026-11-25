<?php

namespace App\Console\Commands;

use App\Http\Controllers\Settings\DeviceController;
use App\Models\DeviceCommand;
use App\Models\DeviceLog;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;

/**
 * device_logs keeps every /iclock request (accepted and rejected) for 7 days, which is what Settings > ZKTeco Device
 * shows as the sync log. Older rows are deleted a batch at a time so a large backlog never holds a long lock.
 *
 * Also expires machine commands left queued, or sent without a reply, for longer than the same 7 days (status failed).
 */
class PruneDeviceLogs extends Command
{
    protected $signature = 'hrm:prune-device-logs';

    protected $description = 'Delete machine request logs (device_logs) older than 7 days and expire unanswered machine commands';

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

        $expired = $this->expireCommands($cutoff);
        $this->info("Expired {$expired} machine command(s) with no reply after ".DeviceController::LOG_DAYS.' days.');

        return self::SUCCESS;
    }

    /**
     * Commands still queued (created before $cutoff) or sent without a reply (sent before $cutoff) are marked failed,
     * with an "Expired" result, so they are never handed out late and the screen stops showing them as waiting.
     * No new status: 'failed' is one of config('hrm.attendance.command_statuses').
     */
    private function expireCommands(CarbonInterface $cutoff): int
    {
        return DeviceCommand::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q->where('status', 'queued')->where('created_at', '<', $cutoff))
                ->orWhere(fn (Builder $q) => $q->where('status', 'sent')->where('sent_at', '<', $cutoff)))
            ->update([
                'status' => 'failed',
                'result' => 'Expired: no reply from the machine within '.DeviceController::LOG_DAYS.' days.',
                'updated_at' => Date::now(),
            ]);
    }
}
