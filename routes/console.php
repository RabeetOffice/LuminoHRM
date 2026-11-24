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
