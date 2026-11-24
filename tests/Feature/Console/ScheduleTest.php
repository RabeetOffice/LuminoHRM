<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

function scheduled(string $needle): Event
{
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, $needle));
    expect($event)->not->toBeNull("nothing scheduled for $needle");

    return $event;
}

it('schedules the queue drain every minute and the backup nightly', function () {
    $events = collect(app(Schedule::class)->events());
    expect($events->first(fn ($e) => str_contains($e->command, 'queue:work'))->expression)->toBe('* * * * *');
    expect($events->first(fn ($e) => str_contains($e->command, 'hrm:backup-database'))->expression)->toBe('0 2 * * *');
});

it('drains the queue for at most 50 seconds, three tries, never overlapping, with an expiry', function () {
    $e = scheduled('queue:work');
    expect($e->command)->toContain('--stop-when-empty')->toContain('--max-time=50')->toContain('--tries=3');
    expect($e->withoutOverlapping)->toBeTrue()
        ->and($e->expiresAt)->toBeGreaterThan(0)
        ->and($e->expiresAt)->toBeLessThanOrEqual(60);
    expect($e->emailAddresses ?? [])->toBeEmpty();
});

it('runs the backup at 02:00 in the app timezone, not UTC', function () {
    $e = scheduled('hrm:backup-database');
    expect(config('app.timezone'))->toBe('Asia/Karachi');
    expect((string) $e->timezone)->toBe(config('app.timezone'));
    expect($e->withoutOverlapping)->toBeTrue();
});

it('prunes failed jobs after a week, daily, so encrypted payloads do not linger', function () {
    $e = scheduled('queue:prune-failed');
    expect($e->command)->toContain('--hours=168');
    expect($e->expression)->toMatch('/^\d+ \d+ \* \* \*$/');
});

it('prunes batches only when the batches table exists', function () {
    $e = scheduled('queue:prune-batches');
    expect($e->filtersPass(app()))->toBeTrue(); // the table exists in the test database
});

it('does not schedule the audit log cleanup: audit entries are permanent', function () {
    foreach (app(Schedule::class)->events() as $e) {
        expect($e->command)->not->toContain('activitylog:clean');
    }
});
