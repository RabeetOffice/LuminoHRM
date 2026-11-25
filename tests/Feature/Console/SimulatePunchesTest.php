<?php

use App\Console\Commands\SimulatePunches;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceLog;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Support\Settings;
use Carbon\CarbonImmutable;

beforeEach(function () {
    config(['hrm.devices.allowed_serials' => []]);
    // Tuesday 24 Nov 2026, 22:00 in the company zone: every shift of the day is over.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 22:00:00', 'Asia/Karachi'));
});

/** $n working employees with machine user IDs 201, 202, ... */
function spStaff(int $n): array
{
    return collect(range(1, $n))
        ->map(fn (int $i) => Employee::factory()->create(['device_user_id' => (string) (200 + $i), 'joining_date' => '2026-01-01']))
        ->all();
}

it('refuses to run outside the local and testing environments', function () {
    spStaff(2);
    $this->app['env'] = 'production';

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23'])
        ->expectsOutputToContain('only runs when APP_ENV is local or testing')
        ->assertFailed();

    expect(DevicePunch::count())->toBe(0)
        ->and(Device::count())->toBe(0)
        ->and(DeviceLog::count())->toBe(0);
});

it('sends punches through the real /iclock endpoint as a local simulator device', function () {
    spStaff(3);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--late' => 0, '--absent' => 0, '--missing-out' => 0, '--seed' => 1])
        ->expectsOutputToContain('Endpoint replied: OK: 6')
        ->assertSuccessful();

    $device = Device::query()->sole();
    expect($device->serial_no)->toBe(SimulatePunches::SIMULATOR_SERIAL)
        ->and($device->name)->toBe(SimulatePunches::SIMULATOR_NAME)
        ->and(DevicePunch::count())->toBe(6)
        ->and(DevicePunch::whereNull('employee_id')->count())->toBe(0)
        // The whole path ran: the request was logged like a machine's.
        ->and(DeviceLog::query()->where('serial_no', SimulatePunches::SIMULATOR_SERIAL)->where('accepted', true)->count())->toBe(1)
        // Pending days were marked for the rebuild.
        ->and(Attendance::query()->onDate('2026-11-23')->where('needs_rebuild', true)->count())->toBe(3);
});

it('is repeatable with the same seed', function () {
    spStaff(4);
    $args = ['--date' => '2026-11-23', '--late' => 50, '--absent' => 20, '--missing-out' => 20, '--seed' => 42];

    $this->artisan('hrm:simulate-punches', $args)->assertSuccessful();
    $first = DevicePunch::query()->orderBy('id')->get()->map(fn ($p) => $p->device_user_id.' '.$p->getRawOriginal('punch_time'))->all();

    DevicePunch::query()->delete();
    Attendance::query()->delete();

    $this->artisan('hrm:simulate-punches', $args)->assertSuccessful();
    $second = DevicePunch::query()->orderBy('id')->get()->map(fn ($p) => $p->device_user_id.' '.$p->getRawOriginal('punch_time'))->all();

    expect($second)->toBe($first)->and($first)->not->toBeEmpty();
});

it('produces late days and present days that the rebuild works out correctly', function () {
    [$a, $b] = spStaff(2);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--late' => 100, '--absent' => 0, '--missing-out' => 0, '--employees' => (string) $a->id])
        ->assertSuccessful();
    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--late' => 0, '--absent' => 0, '--missing-out' => 0, '--employees' => (string) $b->id])
        ->assertSuccessful();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    $dayA = Attendance::query()->where('employee_id', $a->id)->onDate('2026-11-23')->sole();
    $dayB = Attendance::query()->where('employee_id', $b->id)->onDate('2026-11-23')->sole();

    expect($dayA->status)->toBe('late')
        ->and($dayA->late_minutes)->toBeGreaterThan(15)
        ->and($dayB->status)->toBe('present')
        ->and($dayB->late_minutes)->toBe(0)
        ->and($dayB->clock_out)->not->toBeNull();
});

it('leaves absent people without punches, and the close records them absent once attendance has started', function () {
    [$a] = spStaff(1);
    Settings::set('attendance', ['start_date' => '2026-11-01']);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 100])->assertSuccessful();
    expect(DevicePunch::count())->toBe(0);

    $this->artisan('hrm:attendance-close', ['date' => '2026-11-23'])->assertSuccessful();
    expect(Attendance::query()->where('employee_id', $a->id)->onDate('2026-11-23')->sole()->status)->toBe('absent');
});

it('leaves out the clock-out for people who forget to punch out', function () {
    [$a] = spStaff(1);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 0, '--missing-out' => 100])->assertSuccessful();

    expect(DevicePunch::where('employee_id', $a->id)->count())->toBe(1);
});

it('never touches employees outside --employees', function () {
    [$a, $b] = spStaff(2);
    $c = Employee::factory()->create(['device_user_id' => null, 'joining_date' => '2026-01-01']);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 0, '--employees' => $a->employee_code, '--assign-ids' => true])
        ->assertSuccessful();

    expect(DevicePunch::where('employee_id', $a->id)->count())->toBeGreaterThan(0)
        ->and(DevicePunch::where('employee_id', $b->id)->count())->toBe(0)
        ->and($c->fresh()->device_user_id)->toBeNull();
});

it('assigns machine user ids on request and skips people without one otherwise', function () {
    $e = Employee::factory()->create(['device_user_id' => null, 'joining_date' => '2026-01-01']);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 0])
        ->expectsOutputToContain('no machine user ID')
        ->assertSuccessful();
    expect(DevicePunch::count())->toBe(0);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 0, '--assign-ids' => true])->assertSuccessful();
    expect($e->fresh()->device_user_id)->not->toBeNull()
        ->and(DevicePunch::where('employee_id', $e->id)->count())->toBeGreaterThan(0);
});

it('sends no punches on a weekly off day or a holiday', function () {
    spStaff(2);

    // 22 Nov 2026 is a Sunday (the default shift has Saturday and Sunday off).
    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-22', '--absent' => 0])->assertSuccessful();
    Holiday::query()->create(['name' => 'Test holiday', 'date' => '2026-11-23']);
    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--absent' => 0])->assertSuccessful();

    expect(DevicePunch::count())->toBe(0);
});

it('uses the device given by --serial, and refuses an inactive or unknown one', function () {
    spStaff(1);
    $real = Device::factory()->create(['serial_no' => 'SNREAL', 'last_stamp' => '500']);
    Device::factory()->create(['serial_no' => 'SNOFF', 'is_active' => false]);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--serial' => 'SNOFF'])->assertFailed();
    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--serial' => 'NOPE'])->assertFailed();
    expect(DevicePunch::count())->toBe(0);

    $this->artisan('hrm:simulate-punches', ['--date' => '2026-11-23', '--serial' => 'SNREAL', '--absent' => 0])->assertSuccessful();
    expect(DevicePunch::where('device_id', $real->id)->count())->toBeGreaterThan(0)
        // A simulation never moves the stamp a real machine resumes from.
        ->and($real->fresh()->last_stamp)->toBe('500')
        ->and(Device::count())->toBe(2);
});

it('defaults to the current attendance day and sends nothing later than now', function () {
    spStaff(3);
    // 11:00 on Tuesday 24 Nov: people are in, nobody has left.
    $this->travelTo(CarbonImmutable::parse('2026-11-24 11:00:00', 'Asia/Karachi'));

    $this->artisan('hrm:simulate-punches', ['--absent' => 0, '--late' => 0])->assertSuccessful();

    expect(DevicePunch::count())->toBe(3)
        ->and(DevicePunch::query()->pluck('punch_date')->unique()->all())->toBe(['2026-11-24']);
});

it('refuses bad options', function (array $options) {
    spStaff(1);

    $this->artisan('hrm:simulate-punches', $options)->assertFailed();

    expect(DevicePunch::count())->toBe(0);
})->with([
    'future date' => [['--date' => '2026-11-25']],
    'impossible date' => [['--date' => '2026-02-30']],
    'percent over 100' => [['--late' => 150]],
    'negative percent' => [['--absent' => -1]],
    'unknown employee' => [['--employees' => '999999']],
]);
