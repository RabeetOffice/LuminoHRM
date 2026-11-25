<?php

use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLog;
use App\Models\DevicePunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    config(['hrm.devices.allowed_serials' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Karachi'));
});

function dvIclock(string $method, string $path, string $sn, string $body = '')
{
    return test()->call($method, "/iclock/{$path}?SN={$sn}".($path === 'cdata' && $method === 'POST' ? '&table=ATTLOG' : ''), [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

// ---------------------------------------------------------------- access

it('lets only an Admin open and change device settings', function (string $role) {
    $user = userWithRole($role);
    $device = Device::factory()->create(['name' => 'Keep']);

    $this->actingAs($user)->get('/hrm-settings/devices')->assertForbidden();
    $this->actingAs($user)->post('/hrm-settings/devices', ['name' => 'X', 'serial_no' => 'NEW1'])->assertForbidden();
    $this->actingAs($user)->put("/hrm-settings/devices/{$device->id}", ['name' => 'Changed'])->assertForbidden();
    $this->actingAs($user)->patch("/hrm-settings/devices/{$device->id}/active", ['is_active' => false])->assertForbidden();
    $this->actingAs($user)->delete("/hrm-settings/devices/{$device->id}")->assertForbidden();
    $this->actingAs($user)->post("/hrm-settings/devices/{$device->id}/resend", ['from' => '2026-11-20', 'to' => '2026-11-21'])->assertForbidden();

    expect(Device::count())->toBe(1)
        ->and($device->fresh()->name)->toBe('Keep')
        ->and($device->fresh()->is_active)->toBeTrue()
        ->and(DeviceCommand::count())->toBe(0);
})->with(['hr', 'manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $this->get('/hrm-settings/devices')->assertRedirect('/login');
});

// ---------------------------------------------------------------- list and status

it('lists devices with online status, last contact, last punch and punches today', function () {
    $online = Device::factory()->create(['name' => 'A online', 'last_seen_at' => Date::now()->subMinutes(4)]);
    $offline = Device::factory()->create(['name' => 'B offline', 'last_seen_at' => Date::now()->subMinutes(6), 'last_punch_at' => '2026-11-24 09:15:42']);
    $inactive = Device::factory()->create(['name' => 'C inactive', 'is_active' => false, 'last_seen_at' => Date::now()]);
    $never = Device::factory()->create(['name' => 'D never']);
    punch('1', '2026-11-24 09:00:00', $online);
    punch('2', '2026-11-24 09:05:00', $online);
    punch('1', '2026-11-23 09:00:00', $online);
    punch('1', '2026-11-24 02:00:00', $offline); // counts for the 23rd

    $this->actingAs(userWithRole('admin'))->get('/hrm-settings/devices')->assertOk()
        ->assertInertia(fn ($p) => $p->component('hrm-settings/devices')
            ->has('devices', 4)
            ->where('devices.0.online', true)
            ->where('devices.0.punches_today', 2)
            ->where('devices.0.has_punches', true)
            ->where('devices.0.last_seen_at', '2026-11-24 11:56')
            ->where('devices.1.online', false)
            ->where('devices.1.punches_today', 0)
            ->where('devices.1.last_punch_at', '2026-11-24 09:15')
            ->where('devices.2.online', false)
            ->where('devices.2.is_active', false)
            ->where('devices.3.online', false)
            ->where('devices.3.last_seen_at', null)
            ->where('devices.3.has_punches', false)
            ->where('selected', $online->id)
            ->has('groups'));
});

it('counts online from the company clock even when the server runs in UTC', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    Device::factory()->create(['last_seen_at' => CarbonImmutable::parse('2026-11-24 06:58:00', 'UTC')]); // 11:58 Karachi

    $this->actingAs(userWithRole('admin'))->get('/hrm-settings/devices')
        ->assertInertia(fn ($p) => $p->where('devices.0.online', true)->where('devices.0.last_seen_at', '2026-11-24 11:58'));
});

it('lists unknown machine user ids with counts', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101', 'joining_date' => '2026-01-01']);
    punch($employee, '2026-11-23 09:00:00');
    punch('900', '2026-11-23 09:00:00');
    punch('900', '2026-11-23 18:00:00');
    punch('901', '2026-11-22 09:00:00');

    $this->actingAs(userWithRole('admin'))->get('/hrm-settings/devices')
        ->assertInertia(fn ($p) => $p
            ->has('unknown', 2)
            ->where('unknown.0.device_user_id', '900')
            ->where('unknown.0.punches', 2)
            ->where('unknown.0.last_punch_at', '2026-11-23 18:00')
            ->where('unknown.1.device_user_id', '901'));
});

it('shows a 7-day sync log of type and summary, never bodies or query strings', function () {
    $device = Device::factory()->create(['serial_no' => 'SNLOG']);
    dvIclock('GET', 'cdata', 'SNLOG');
    dvIclock('POST', 'cdata', 'SNLOG', "101\t2026-11-24 09:00:00\t0\t1\n");
    dvIclock('GET', 'getrequest', 'SNLOG');
    DeviceLog::create([
        'serial_no' => 'SNLOG', 'method' => 'GET', 'path' => 'iclock/cdata', 'query' => 'SN=SNLOG', 'body' => null,
        'ip' => '127.0.0.1', 'scheme' => 'http', 'accepted' => true, 'summary' => 'old handshake',
    ])->forceFill(['created_at' => Date::now()->subDays(8)])->save();
    DeviceLog::create([
        'serial_no' => 'OTHER', 'method' => 'GET', 'path' => 'iclock/cdata', 'query' => 'SN=OTHER', 'body' => null,
        'ip' => '127.0.0.1', 'scheme' => 'http', 'accepted' => false, 'summary' => 'rejected: unknown device',
    ]);

    $response = $this->actingAs(userWithRole('admin'))->get('/hrm-settings/devices?device='.$device->id);
    $response->assertInertia(fn ($p) => $p
        ->has('log', 3)
        ->where('log.0.type', 'Command poll')
        ->where('log.1.type', 'Upload')
        ->where('log.1.summary', 'ATTLOG upload: 1 stored, 0 duplicate(s), 0 skipped')
        ->where('log.2.type', 'Handshake')
        ->where('log.2.time', '2026-11-24 12:00'));

    $log = $response->viewData('page')['props']['log'];
    expect(array_keys($log[0]))->toBe(['id', 'time', 'type', 'accepted', 'summary'])
        ->and(json_encode($response->viewData('page')['props']))->not->toContain("2026-11-24 09:00:00\t");
});

// ---------------------------------------------------------------- add, rename, activate, remove

it('adds a device with a trimmed, unique serial number and audits it', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post('/hrm-settings/devices', ['name' => '  Main gate ', 'serial_no' => '  CQZ7232560123 '])
        ->assertSessionHasNoErrors()->assertRedirect();

    $device = Device::query()->sole();
    expect($device->serial_no)->toBe('CQZ7232560123')
        ->and($device->name)->toBe('Main gate')
        ->and($device->is_active)->toBeTrue();

    expect(Activity::query()->where('log_name', 'attendance')->where('subject_type', $device->getMorphClass())->where('event', 'created')->exists())->toBeTrue();

    $this->actingAs($admin)->post('/hrm-settings/devices', ['name' => 'Copy', 'serial_no' => 'CQZ7232560123 '])
        ->assertSessionHasErrors('serial_no');
    $this->actingAs($admin)->post('/hrm-settings/devices', ['name' => 'Bad', 'serial_no' => 'has space'])
        ->assertSessionHasErrors('serial_no');
    $this->actingAs($admin)->post('/hrm-settings/devices', ['name' => '', 'serial_no' => ''])
        ->assertSessionHasErrors(['name', 'serial_no']);

    expect(Device::count())->toBe(1);
});

it('renames a device without changing its serial number', function () {
    $device = Device::factory()->create(['name' => 'Old', 'serial_no' => 'SN1']);

    $this->actingAs(userWithRole('admin'))->put("/hrm-settings/devices/{$device->id}", ['name' => 'New name', 'serial_no' => 'SN2'])
        ->assertSessionHasNoErrors();

    expect($device->fresh()->name)->toBe('New name')->and($device->fresh()->serial_no)->toBe('SN1');
});

it('deactivates a device, which /iclock then refuses, and activates it again', function () {
    $device = Device::factory()->create(['serial_no' => 'SNACT']);
    $admin = userWithRole('admin');

    expect(dvIclock('POST', 'cdata', 'SNACT', "101\t2026-11-24 09:00:00\t0\t1\n")->getContent())->toBe("OK: 1\n");

    $this->actingAs($admin)->patch("/hrm-settings/devices/{$device->id}/active", ['is_active' => false])->assertSessionHasNoErrors();
    expect($device->fresh()->is_active)->toBeFalse();

    $refused = dvIclock('POST', 'cdata', 'SNACT', "101\t2026-11-24 10:00:00\t0\t1\n");
    expect($refused->status())->toBe(403)
        ->and($refused->getContent())->toBe("ERROR: unknown device\n")
        ->and(DevicePunch::count())->toBe(1);
    expect(dvIclock('GET', 'getrequest', 'SNACT')->status())->toBe(403);

    $this->actingAs($admin)->patch("/hrm-settings/devices/{$device->id}/active", ['is_active' => true])->assertSessionHasNoErrors();
    expect(dvIclock('POST', 'cdata', 'SNACT', "101\t2026-11-24 10:00:00\t0\t1\n")->getContent())->toBe("OK: 1\n");

    expect(Activity::query()->where('log_name', 'attendance')->where('subject_id', $device->id)->where('event', 'updated')->count())->toBe(2);
});

it('removes a device only when it has no punches', function () {
    $empty = Device::factory()->create(['name' => 'Empty']);
    $used = Device::factory()->create(['name' => 'Used']);
    DeviceCommand::create(['device_id' => $empty->id, 'command' => 'INFO']);
    punch('1', '2026-11-23 09:00:00', $used);
    $admin = userWithRole('admin');

    $this->actingAs($admin)->delete("/hrm-settings/devices/{$used->id}")->assertSessionHas('error');
    expect(Device::whereKey($used->id)->exists())->toBeTrue();

    $this->actingAs($admin)->delete("/hrm-settings/devices/{$empty->id}")->assertSessionHas('success');
    expect(Device::whereKey($empty->id)->exists())->toBeFalse()
        ->and(DeviceCommand::count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'attendance')->where('subject_id', $empty->id)->where('event', 'deleted')->exists())->toBeTrue();
});

// ---------------------------------------------------------------- re-send punches

it('queues a re-send command that getrequest hands out in the exact line format and devicecmd marks done', function () {
    $device = Device::factory()->create(['serial_no' => 'SNCMD']);
    $admin = userWithRole('admin');

    $this->actingAs($admin)->post("/hrm-settings/devices/{$device->id}/resend", ['from' => '2026-11-01', 'to' => '2026-11-07'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'next checks in'));

    $command = DeviceCommand::query()->sole();
    expect($command->command)->toBe("DATA QUERY ATTLOG StartTime=2026-11-01 00:00:00\tEndTime=2026-11-07 23:59:59")
        ->and($command->status)->toBe('queued')
        ->and($command->created_by)->toBe($admin->id);

    $audit = Activity::query()->where('log_name', 'attendance')->where('description', 'Device command queued')->sole();
    expect($audit->properties['from'])->toBe('2026-11-01')->and($audit->properties['to'])->toBe('2026-11-07');

    $poll = dvIclock('GET', 'getrequest', 'SNCMD');
    expect($poll->getContent())->toBe("C:{$command->id}:DATA QUERY ATTLOG StartTime=2026-11-01 00:00:00\tEndTime=2026-11-07 23:59:59\n")
        ->and($command->fresh()->status)->toBe('sent');
    expect(dvIclock('GET', 'getrequest', 'SNCMD')->getContent())->toBe("OK\n");

    expect(dvIclock('POST', 'devicecmd', 'SNCMD', "ID={$command->id}&Return=0&CMD=DATA\n")->getContent())->toBe("OK\n")
        ->and($command->fresh()->status)->toBe('done');

    $this->actingAs($admin)->get('/hrm-settings/devices')
        ->assertInertia(fn ($p) => $p->where('commands.0.label', 'Re-send punches 2026-11-01 to 2026-11-07')->where('commands.0.status', 'done'));
});

it('validates the re-send range', function (array $payload, string $field) {
    $device = Device::factory()->create();

    $this->actingAs(userWithRole('admin'))->post("/hrm-settings/devices/{$device->id}/resend", $payload)
        ->assertSessionHasErrors($field);

    expect(DeviceCommand::count())->toBe(0);
})->with([
    'from after to' => [['from' => '2026-11-10', 'to' => '2026-11-09'], 'to'],
    'more than 31 days' => [['from' => '2026-10-01', 'to' => '2026-11-01'], 'to'],
    'future end' => [['from' => '2026-11-20', 'to' => '2026-11-25'], 'to'],
    'future start' => [['from' => '2026-11-25', 'to' => '2026-11-25'], 'from'],
    'not a date' => [['from' => '2026-02-30', 'to' => '2026-03-01'], 'from'],
    'missing' => [[], 'from'],
]);

it('accepts exactly 31 days ending today', function () {
    $device = Device::factory()->create();

    $this->actingAs(userWithRole('admin'))->post("/hrm-settings/devices/{$device->id}/resend", ['from' => '2026-10-25', 'to' => '2026-11-24'])
        ->assertSessionHasNoErrors();

    expect(DeviceCommand::count())->toBe(1);
});

it('refuses to queue a command for an inactive device', function () {
    $device = Device::factory()->create(['is_active' => false]);

    $this->actingAs(userWithRole('admin'))->post("/hrm-settings/devices/{$device->id}/resend", ['from' => '2026-11-20', 'to' => '2026-11-21'])
        ->assertSessionHasErrors('from');

    expect(DeviceCommand::count())->toBe(0);
});
