<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Scheduler. Production runs `artisan schedule:run` from cron every minute (deploy/hostinger/cron.sh).
| Later phases add their jobs here. The audit log is permanent: activitylog:clean is deliberately NOT scheduled.
*/

// Queue drain: set-password emails and other queued work. A fresh process each minute, so current mail settings apply.
// The 10 minute expiry stops a crashed run from blocking the queue for good.
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3'])
    ->everyMinute()
    ->withoutOverlapping(10);

// Failed jobs keep their (encrypted) payloads, which can hold password-reset tokens: drop them after a week.
Schedule::command('queue:prune-failed', ['--hours=168'])
    ->dailyAt('03:30')
    ->timezone(config('app.timezone'));

Schedule::command('queue:prune-batches')
    ->dailyAt('03:35')
    ->timezone(config('app.timezone'))
    ->when(fn () => Schema::hasTable('job_batches'));

// Nightly database backup at 02:00 in the app timezone (the scheduler would otherwise use its own default).
Schedule::command('hrm:backup-database')
    ->dailyAt('02:00')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60);

// Catch-up: dailyAt(02:00) fires only if schedule:run happens to run in that exact minute, and a long queue run can hold
// the cron lock past it. Hourly at :20 this does nothing unless it is 02:00 or later and today has no good backup yet.
Schedule::command('hrm:backup-database', ['--if-missing'])
    ->hourlyAt(20)
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(60);

// Employee import uploads (storage/app/private/imports): a preview is valid for 60 minutes, then its file is deleted.
Schedule::command('hrm:prune-imports')
    ->hourly()
    ->withoutOverlapping(10);

// Attendance (Phase 3). Punch ingestion only marks days; this decides them, within about a minute of the punch.
Schedule::command('hrm:attendance-rebuild')
    ->everyMinute()
    ->withoutOverlapping(10);

// Nightly close. The company zone is a runtime setting, so it is not baked into the cron expression: the command runs
// hourly at :30 and closes the 7 attendance days before the current one. A day is closed by the first run after the
// next morning's day cutoff (04:30 with the 04:00 cutoff); every later run finds nothing to do for it (idempotent)
// unless an earlier run was missed. Each run then backfills a bounded number of older days (up to 62 days back, from
// the attendance start date) that still miss records; the next run continues.
Schedule::command('hrm:attendance-close')
    ->hourlyAt(30)
    ->withoutOverlapping(30);

// Machine request log (device_logs, accepted and rejected rows): kept for 7 days, the sync log window in Settings.
Schedule::command('hrm:prune-device-logs')
    ->dailyAt('03:40')
    ->timezone(config('app.timezone'))
    ->withoutOverlapping(30);

// Leave balances (Phase 4): creates the missing balance rows of the current leave year for everyone employed in it
// (idempotent, so a missed 1 January is caught up on the next run; existing rows are never changed).
Schedule::command('hrm:leave-allocate')
    ->hourlyAt(40)
    ->withoutOverlapping(30);
