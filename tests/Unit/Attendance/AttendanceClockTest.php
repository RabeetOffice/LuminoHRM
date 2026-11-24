<?php

use App\Support\Attendance\AttendanceClock;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Runs the app as a server whose own clock zone is $zone (config and PHP default both). */
function acServerZone(string $zone): void
{
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
}

function acClock(): AttendanceClock
{
    return app(AttendanceClock::class);
}

it('uses the company timezone setting, Asia/Karachi by default', function () {
    expect(acClock()->zone()->getName())->toBe('Asia/Karachi');

    Settings::set('company', ['timezone' => 'Asia/Dubai']);

    expect(acClock()->zone()->getName())->toBe('Asia/Dubai');
});

it('falls back to the app timezone when the setting is unusable', function (mixed $value) {
    acServerZone('Europe/London');
    Settings::set('company', ['timezone' => $value]);

    expect(acClock()->zone()->getName())->toBe('Europe/London');
})->with(['Mars/Olympus_Mons', '', '   ']);

it('falls back to UTC when the app timezone is unusable too', function () {
    Settings::set('company', ['timezone' => 'Nowhere/Land']);
    config(['app.timezone' => 'Not/AZone']);

    expect(acClock()->zone()->getName())->toBe('UTC');
});

it('gives the company wall-clock day with the server in UTC, just before and after local midnight', function () {
    acServerZone('UTC');

    // 18:59:59 UTC is 23:59:59 in Karachi (UTC+5): still the 24th there.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 18:59:59', 'UTC'));

    expect(acClock()->now()->format('Y-m-d H:i:s'))->toBe('2026-11-24 23:59:59')
        ->and(acClock()->now()->getTimezone()->getName())->toBe('Asia/Karachi')
        ->and(acClock()->today()->format('Y-m-d H:i:s'))->toBe('2026-11-24 00:00:00')
        ->and(now()->format('Y-m-d'))->toBe('2026-11-24'); // the server's own date agrees here...

    // 19:00:00 UTC is 00:00:00 on the 25th in Karachi, while the server (UTC) is still on the 24th.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 19:00:00', 'UTC'));

    expect(acClock()->now()->format('Y-m-d H:i:s'))->toBe('2026-11-25 00:00:00')
        ->and(acClock()->today()->format('Y-m-d'))->toBe('2026-11-25')
        ->and(now()->format('Y-m-d'))->toBe('2026-11-24'); // ...and no longer: only the clock knows the company day

    $this->travelTo(CarbonImmutable::parse('2026-11-24 19:01:00', 'UTC'));
    expect(acClock()->now()->format('H:i'))->toBe('00:01');
});

it('gives the same company day whatever zone the server runs in', function (string $serverZone) {
    acServerZone($serverZone);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 19:30:00', 'UTC'));

    expect(acClock()->today()->format('Y-m-d'))->toBe('2026-11-25')
        ->and(acClock()->now()->format('H:i'))->toBe('00:30');
})->with(['UTC', 'Asia/Karachi', 'America/New_York', 'Pacific/Kiritimati']);

it('honours Carbon::setTestNow as well as travelTo', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-01 23:30:00', 'Asia/Karachi'));

    expect(acClock()->now()->format('Y-m-d H:i'))->toBe('2026-03-01 23:30');

    Carbon::setTestNow();
});

it('returns immutable values', function () {
    $now = acClock()->now();
    $now->addDay();

    expect($now)->toBeInstanceOf(CarbonImmutable::class)
        ->and(acClock()->today())->toBeInstanceOf(CarbonImmutable::class)
        ->and(acClock()->now()->format('Y-m-d'))->toBe($now->format('Y-m-d'));
});

it('gives the UTC offset in hours for the machine handshake', function (string $zone, int|float $hours) {
    Settings::set('company', ['timezone' => $zone]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'UTC'));

    expect(acClock()->utcOffsetHours())->toBe($hours);
})->with([
    ['Asia/Karachi', 5],
    ['Asia/Kolkata', 5.5],
    ['Asia/Kathmandu', 5.75],
    ['UTC', 0],
    ['America/St_Johns', -3.5],
    ['America/New_York', -5],
]);

it('reads the date part of a naive value without shifting it', function () {
    acServerZone('UTC');

    expect(acClock()->toDateString('2026-11-24 23:59:59'))->toBe('2026-11-24')
        ->and(acClock()->toDateString('2026-11-25 00:00:01'))->toBe('2026-11-25')
        ->and(acClock()->toDateString('2026-11-24 23:59'))->toBe('2026-11-24')
        ->and(acClock()->toDateString('2026-11-24'))->toBe('2026-11-24')
        // A DateTimeInterface gives its own wall-clock date, never converted to the company zone.
        ->and(acClock()->toDateString(new DateTimeImmutable('2026-11-24 23:59:59', new DateTimeZone('UTC'))))->toBe('2026-11-24')
        ->and(acClock()->toDateString(CarbonImmutable::parse('2026-11-24 00:30:00', 'America/New_York')))->toBe('2026-11-24');
});

it('refuses a value that is not a naive date or date-time', function (string $value) {
    acClock()->toDateString($value);
})->with(['24/11/2026', '2026-02-30 10:00:00', '2026-11-24T10:00:00Z', 'tomorrow', ''])
    ->throws(InvalidArgumentException::class);

it('keeps no state between calls: a changed setting is seen at once', function () {
    $clock = acClock();
    $this->travelTo(CarbonImmutable::parse('2026-11-24 20:00:00', 'UTC'));

    expect($clock->today()->format('Y-m-d'))->toBe('2026-11-25');

    Settings::set('company', ['timezone' => 'America/New_York']);

    expect($clock->today()->format('Y-m-d'))->toBe('2026-11-24')
        ->and($clock->utcOffsetHours())->toBe(-5);
});

it('puts a punch before the day cutoff on the previous day', function (string $punch, string $day) {
    expect(acClock()->attendanceDateFor($punch))->toBe($day);
})->with([
    'after midnight, before 04:00' => ['2026-11-25 00:05:00', '2026-11-24'],
    'one second before the cutoff' => ['2026-11-25 03:59:59', '2026-11-24'],
    'at the cutoff' => ['2026-11-25 04:00:00', '2026-11-25'],
    'a normal clock-in' => ['2026-11-25 09:00:00', '2026-11-25'],
    'late evening' => ['2026-11-25 23:59:59', '2026-11-25'],
    'across a month end' => ['2026-12-01 01:00:00', '2026-11-30'],
]);

it('follows the day cutoff setting, and falls back to 04:00 when it is unusable', function () {
    Settings::set('attendance', ['day_cutoff_time' => '00:00']);
    expect(acClock()->attendanceDateFor('2026-11-25 00:05:00'))->toBe('2026-11-25');

    Settings::set('attendance', ['day_cutoff_time' => '06:30']);
    expect(acClock()->attendanceDateFor('2026-11-25 06:29:00'))->toBe('2026-11-24');

    Settings::set('attendance', ['day_cutoff_time' => 'soon']);
    expect(acClock()->dayCutoff())->toBe('04:00');
});

it('rejects a punch that is not a naive date-time', function () {
    acClock()->attendanceDateFor('2026-11-25');
})->throws(InvalidArgumentException::class);
