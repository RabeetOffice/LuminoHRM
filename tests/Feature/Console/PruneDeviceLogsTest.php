<?php

use App\Models\DeviceLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Date;

function pdLog(string $summary, bool $accepted, int $daysAgo, int $extraMinutes = 0): DeviceLog
{
    $log = DeviceLog::create([
        'serial_no' => $accepted ? 'SN1' : null, 'method' => 'GET', 'path' => 'iclock/cdata', 'query' => '',
        'body' => null, 'ip' => '203.0.113.9', 'scheme' => 'http', 'accepted' => $accepted, 'summary' => $summary,
    ]);
    $log->forceFill(['created_at' => Date::now()->subDays($daysAgo)->subMinutes($extraMinutes)])->save();

    return $log;
}

it('deletes device logs older than 7 days, accepted and rejected alike', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 03:00:00', 'Asia/Karachi'));
    pdLog('old accepted', true, 7, 1);
    pdLog('old rejected', false, 30);
    pdLog('recent accepted', true, 6);
    pdLog('recent rejected', false, 0);

    $this->artisan('hrm:prune-device-logs')->expectsOutputToContain('Deleted 2')->assertSuccessful();

    expect(DeviceLog::query()->orderBy('id')->pluck('summary')->all())->toBe(['recent accepted', 'recent rejected']);
});

it('is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'hrm:prune-device-logs'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toMatch('/^\d+ \d+ \* \* \*$/')
        ->and($event->withoutOverlapping)->toBeTrue();
});
