<?php

use App\Models\Device;
use App\Models\DeviceCommand;
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

it('expires commands still waiting, or sent without a reply, after 7 days', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 03:00:00', 'Asia/Karachi'));
    $device = Device::factory()->create();
    $make = function (string $status, int $daysAgo, ?int $sentDaysAgo = null) use ($device): DeviceCommand {
        $command = DeviceCommand::create(['device_id' => $device->id, 'command' => "INFO {$status} {$daysAgo}", 'status' => $status]);
        $command->forceFill([
            'created_at' => Date::now()->subDays($daysAgo)->subMinute(),
            'sent_at' => $sentDaysAgo === null ? null : Date::now()->subDays($sentDaysAgo)->subMinute(),
        ])->save();

        return $command;
    };

    $oldQueued = $make('queued', 7);
    $oldSent = $make('sent', 9, 7);
    $recentQueued = $make('queued', 6);
    $recentSent = $make('sent', 9, 6); // queued long ago, but sent recently: still waiting for the reply
    $oldDone = $make('done', 30, 30);

    $this->artisan('hrm:prune-device-logs')->expectsOutputToContain('Expired 2')->assertSuccessful();

    expect($oldQueued->fresh()->status)->toBe('failed')
        ->and($oldQueued->fresh()->result)->toContain('Expired')
        ->and($oldSent->fresh()->status)->toBe('failed')
        ->and($recentQueued->fresh()->status)->toBe('queued')
        ->and($recentSent->fresh()->status)->toBe('sent')
        ->and($oldDone->fresh()->status)->toBe('done');

    // An expired command is never handed out.
    test()->call('GET', "/iclock/getrequest?SN={$device->serial_no}");
    expect($recentQueued->fresh()->status)->toBe('sent')->and($oldQueued->fresh()->status)->toBe('failed');
});

it('is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'hrm:prune-device-logs'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toMatch('/^\d+ \d+ \* \* \*$/')
        ->and($event->withoutOverlapping)->toBeTrue();
});
