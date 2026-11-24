<?php

use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\UpdateEmployee;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DeviceLog;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Support\Attendance\PunchIngestor;
use App\Support\Settings;
use App\Support\SettingsStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['hrm.devices.allowed_serials' => []]);
    $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'Asia/Karachi'));
    $this->device = Device::factory()->create(['serial_no' => 'MB20SN01', 'name' => 'Front door']);
});

function attlog(string $body, string $sn = 'MB20SN01', ?string $stamp = null): TestResponse
{
    $query = 'SN='.$sn.'&table=ATTLOG'.($stamp !== null ? '&Stamp='.$stamp : '');

    return test()->call('POST', '/iclock/cdata?'.$query, [], [], [], ['CONTENT_TYPE' => 'text/plain'], $body);
}

// --- Storing punches ------------------------------------------------------------------------

it('stores a body sent twice only once and acknowledges it both times', function () {
    $body = "101\t2026-11-23 09:00:17\t0\t1\t0\t0\n102\t2026-11-23 09:02:00\t0\t1\t0\t0\n";

    expect(attlog($body)->getContent())->toBe("OK: 2\n")
        ->and(attlog($body)->getContent())->toBe("OK: 2\n")
        ->and(DevicePunch::count())->toBe(2);

    expect(DeviceLog::latest('id')->first()->summary)->toBe('ATTLOG upload: 0 stored, 2 duplicate(s), 0 skipped');
});

it('stores lines sent in reverse time order', function () {
    $r = attlog("101\t2026-11-23 18:00:00\t1\t1\n101\t2026-11-23 13:00:00\t0\t1\n101\t2026-11-23 09:00:00\t0\t1\n");

    expect($r->getContent())->toBe("OK: 3\n");
    expect(DevicePunch::orderBy('punch_time')->get()->map(fn ($p) => $p->punch_time->format('H:i:s'))->all())
        ->toBe(['09:00:00', '13:00:00', '18:00:00']);
    expect(Device::find($this->device->id)->last_punch_at->format('Y-m-d H:i:s'))->toBe('2026-11-23 18:00:00');
});

it('handles a batch mixing new, duplicate, repeated and malformed lines', function () {
    attlog("101\t2026-11-23 09:00:00\t0\t1\n");

    $r = attlog("101\t2026-11-23 09:00:00\t0\t1\ngarbage\n102\t2026-11-23 09:05:00\t0\t1\n102\t2026-11-23 09:05:00\t0\t1\n103\tbad-time\n");

    expect($r->getContent())->toBe("OK: 3\n")
        ->and(DevicePunch::count())->toBe(2)
        ->and(DeviceLog::latest('id')->first()->summary)->toBe('ATTLOG upload: 1 stored, 2 duplicate(s), 2 skipped');
});

it('stores every field of a punch exactly as received', function () {
    attlog("101\t2026-11-23 09:00:17\t1\t15\t0\t0\n");

    $punch = DevicePunch::sole();
    expect($punch->getRawOriginal('punch_time'))->toStartWith('2026-11-23 09:00:17')
        ->and($punch->punch_date)->toBe('2026-11-23')
        ->and($punch->device_id)->toBe($this->device->id)
        ->and($punch->device_user_id)->toBe('101')
        ->and($punch->status_code)->toBe('1')
        ->and($punch->verify_type)->toBe('15')
        ->and($punch->source)->toBe('device')
        ->and($punch->created_at)->not->toBeNull();
});

it('stores a punch for an unknown user with no employee and marks no day', function () {
    attlog("999\t2026-11-23 09:00:00\t0\t1\n");

    expect(DevicePunch::sole()->employee_id)->toBeNull()
        ->and(Attendance::count())->toBe(0);
});

it('links a punch to the employee holding the machine user ID and marks the day for rebuild', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101']);

    attlog("101\t2026-11-23 09:00:00\t0\t1\n101\t2026-11-23 18:00:00\t1\t1\n");

    expect(DevicePunch::pluck('employee_id')->unique()->all())->toBe([$employee->id]);
    $day = Attendance::sole();
    expect($day->employee_id)->toBe($employee->id)
        ->and($day->date->format('Y-m-d'))->toBe('2026-11-23')
        ->and($day->needs_rebuild)->toBeTrue()
        ->and($day->is_manual)->toBeFalse()
        ->and($day->clock_in)->toBeNull();
});

it('only raises the rebuild flag on an existing day and leaves its other columns alone', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101']);
    $day = Attendance::factory()->create([
        'employee_id' => $employee->id, 'date' => '2026-11-23', 'status' => 'late', 'late_minutes' => 20,
        'clock_in' => '2026-11-23 09:20:00', 'clock_out' => '2026-11-23 18:00:00', 'closed' => true, 'note' => 'kept',
    ]);
    $before = Attendance::find($day->id)->getAttributes();

    attlog("101\t2026-11-23 19:00:00\t1\t1\n");

    $after = Attendance::find($day->id)->getAttributes();
    expect($after['needs_rebuild'])->toBe(1);
    unset($before['needs_rebuild'], $before['updated_at'], $after['needs_rebuild'], $after['updated_at']);
    expect($after)->toBe($before);
});

it('leaves a manually corrected day untouched', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101']);
    $day = Attendance::factory()->create([
        'employee_id' => $employee->id, 'date' => '2026-11-23', 'status' => 'present', 'is_manual' => true, 'note' => 'HR fixed',
    ]);
    $before = Attendance::find($day->id)->getAttributes();

    expect(attlog("101\t2026-11-23 07:00:00\t0\t1\n")->getContent())->toBe("OK: 1\n");

    expect(Attendance::find($day->id)->getAttributes())->toBe($before)
        ->and(DevicePunch::sole()->employee_id)->toBe($employee->id);
});

it('puts punches at 23:59:59 and 00:00:00 on different dates', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101']);

    attlog("101\t2026-11-22 23:59:59\t0\t1\n101\t2026-11-23 00:00:00\t0\t1\n");

    expect(DevicePunch::orderBy('punch_time')->pluck('punch_date')->all())->toBe(['2026-11-22', '2026-11-23']);
    expect(Attendance::where('employee_id', $employee->id)->orderBy('date')->get()->map(fn ($a) => $a->date->format('Y-m-d'))->all())
        ->toBe(['2026-11-22', '2026-11-23']);
});

it('skips clock faults, counts them and writes them to the log summary', function () {
    // Now is 2026-11-24 12:00 in Asia/Karachi: up to 2026-11-25 12:00:00 is accepted.
    $r = attlog("101\t2026-11-25 12:00:00\t0\t1\n102\t2026-11-25 12:00:01\t0\t1\n103\t1999-12-31 23:59:59\t0\t1\n");

    expect($r->getContent())->toBe("OK: 1\n")
        ->and(DevicePunch::pluck('device_user_id')->all())->toBe(['101'])
        ->and(DeviceLog::latest('id')->first()->summary)
        ->toBe('ATTLOG upload: 1 stored, 0 duplicate(s), 2 skipped; clock fault: 102 2026-11-25 12:00:01, 103 1999-12-31 23:59:59');
});

it('judges the future limit by the company zone, not the server zone', function () {
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    // 2026-11-24 12:00 in Karachi is 07:00 UTC; a punch at 2026-11-25 11:00 (Karachi wall clock) is within 24 h.
    expect(attlog("101\t2026-11-25 11:00:00\t0\t1\n")->getContent())->toBe("OK: 1\n");
});

it('replies an error in plain text and stores nothing when storing fails, then stores everything once on retry', function () {
    Employee::factory()->create(['device_user_id' => '101']);
    Schema::rename('attendances', 'attendances_away');
    Log::spy();

    $body = "101\t2026-11-23 09:00:00\t0\t1\n102\t2026-11-23 09:01:00\t0\t1\n";
    $r = attlog($body, stamp: '777');

    expect($r->status())->toBe(500)
        ->and($r->getContent())->toBe("ERROR: retry\n")
        ->and($r->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and($r->headers->getCookies())->toBeEmpty()
        ->and(DevicePunch::count())->toBe(0)
        ->and(Device::find($this->device->id)->last_stamp)->toBeNull();
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_starts_with($message, 'iclock:')
        && is_string($context['exception'] ?? null) && ! str_contains(json_encode($context), '09:00:00'));
    expect(DeviceLog::latest('id')->first()->summary)->toStartWith('ATTLOG upload failed');

    Schema::rename('attendances_away', 'attendances');

    expect(attlog($body, stamp: '777')->getContent())->toBe("OK: 2\n")
        ->and(DevicePunch::count())->toBe(2)
        ->and(Attendance::count())->toBe(1)
        ->and(Device::find($this->device->id)->last_stamp)->toBe('777');
});

it('stores overlapping batches from two requests exactly once each', function () {
    $employee = Employee::factory()->create(['device_user_id' => '101']);
    $a = "101\t2026-11-23 09:00:00\t0\t1\n101\t2026-11-23 13:00:00\t0\t1\n101\t2026-11-23 14:00:00\t0\t1\n";
    $b = "101\t2026-11-23 13:00:00\t0\t1\n101\t2026-11-23 14:00:00\t0\t1\n101\t2026-11-23 18:00:00\t1\t1\n";

    expect(attlog($a)->getContent())->toBe("OK: 3\n")
        ->and(attlog($b)->getContent())->toBe("OK: 3\n")
        ->and(DevicePunch::count())->toBe(4)
        ->and(Attendance::where('employee_id', $employee->id)->count())->toBe(1);
});

it('treats a punch another request stored in the meantime as a duplicate', function () {
    $ingestor = app(PunchIngestor::class);
    punch('101', '2026-11-23 09:00:00', $this->device);

    $result = $ingestor->ingest($this->device, "101\t2026-11-23 09:00:00\t0\t1\n101\t2026-11-23 09:30:00\t0\t1\n", null);

    expect($result->stored)->toBe(1)->and($result->duplicates)->toBe(1)->and($result->skipped)->toBe(0);
});

it('keeps the same punch from two different machines as two punches', function () {
    Device::factory()->create(['serial_no' => 'MB20SN02']);

    attlog("101\t2026-11-23 09:00:00\t0\t1\n");
    attlog("101\t2026-11-23 09:00:00\t0\t1\n", 'MB20SN02');

    expect(DevicePunch::count())->toBe(2);
});

it('records when the machine was seen, its newest punch and its stamp', function () {
    attlog("101\t2026-11-23 09:00:00\t0\t1\n101\t2026-11-23 17:00:00\t0\t1\n", stamp: '9001');
    attlog("101\t2026-11-22 08:00:00\t0\t1\n", stamp: '9002'); // an older punch never moves last_punch_at back

    $device = Device::find($this->device->id);
    expect($device->last_stamp)->toBe('9002')
        ->and($device->last_punch_at->format('Y-m-d H:i:s'))->toBe('2026-11-23 17:00:00')
        ->and($device->last_seen_at)->not->toBeNull();
});

it('acknowledges an empty upload with OK: 0', function () {
    expect(attlog('')->getContent())->toBe("OK: 0\n");
});

// --- Handshake ------------------------------------------------------------------------------

it('round-trips the ATTLOG stamp through the handshake', function () {
    expect($this->get('/iclock/cdata?SN=MB20SN01&options=all')->getContent())->toContain("ATTLOGStamp=None\n");

    attlog("101\t2026-11-23 09:00:00\t0\t1\n", stamp: '123456');

    expect($this->get('/iclock/cdata?SN=MB20SN01&options=all')->getContent())->toContain("ATTLOGStamp=123456\n");
});

it('derives the handshake TimeZone from the company zone', function () {
    expect($this->get('/iclock/cdata?SN=MB20SN01')->getContent())->toContain("TimeZone=5\n");

    Settings::set('company', ['timezone' => 'Asia/Kolkata']);

    expect($this->get('/iclock/cdata?SN=MB20SN01')->getContent())->toContain("TimeZone=5.5\n");
});

it('updates last_seen_at on a handshake', function () {
    $this->get('/iclock/cdata?SN=MB20SN01');

    expect(Device::find($this->device->id)->last_seen_at)->not->toBeNull();
});

// --- Device registration --------------------------------------------------------------------

it('creates the row for a serial listed in the environment once, on first contact', function () {
    config(['hrm.devices.allowed_serials' => ['ENVSN77']]);

    $this->get('/iclock/cdata?SN=ENVSN77')->assertOk();
    $this->get('/iclock/getrequest?SN=ENVSN77')->assertOk();

    $device = Device::where('serial_no', 'ENVSN77')->sole();
    expect($device->name)->toBe('MB20 (ENVSN77)')->and($device->is_active)->toBeTrue();
});

it('rejects an inactive device even when its serial is listed in the environment', function () {
    config(['hrm.devices.allowed_serials' => ['MB20SN01']]);
    $this->device->update(['is_active' => false]);

    $r = attlog("101\t2026-11-23 09:00:00\t0\t1\n");

    $r->assertStatus(403);
    expect($r->getContent())->toBe("ERROR: unknown device\n")
        ->and(DevicePunch::count())->toBe(0)
        ->and(DeviceLog::latest('id')->first()->accepted)->toBeFalse();
});

it('rejects a serial that is neither in the table nor in the environment', function () {
    $r = $this->get('/iclock/cdata?SN=NOPE1');

    $r->assertStatus(403);
    expect(Device::where('serial_no', 'NOPE1')->exists())->toBeFalse();
});

// --- Commands -------------------------------------------------------------------------------

it('hands out queued commands oldest first and marks them sent', function () {
    $first = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'command' => 'INFO']);
    $second = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'command' => 'DATA QUERY ATTLOG']);
    DeviceCommand::factory()->create(['command' => 'REBOOT']); // another machine's

    expect($this->get('/iclock/getrequest?SN=MB20SN01')->getContent())->toBe("C:{$first->id}:INFO\n");
    expect($first->fresh()->status)->toBe('sent')->and($first->fresh()->sent_at)->not->toBeNull();

    expect($this->get('/iclock/getrequest?SN=MB20SN01')->getContent())->toBe("C:{$second->id}:DATA QUERY ATTLOG\n");
    expect($this->get('/iclock/getrequest?SN=MB20SN01')->getContent())->toBe("OK\n");
});

it('records command results as done or failed', function () {
    $ok = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'status' => 'sent']);
    $bad = DeviceCommand::factory()->create(['device_id' => $this->device->id, 'status' => 'sent']);
    $foreign = DeviceCommand::factory()->create(['status' => 'sent']);

    $r = $this->call('POST', '/iclock/devicecmd?SN=MB20SN01', [], [], [], [],
        "ID={$ok->id}&Return=0&CMD=INFO\nID={$bad->id}&Return=-1002&CMD=INFO\nID={$foreign->id}&Return=0&CMD=INFO\nnonsense\n");

    expect($r->getContent())->toBe("OK\n")
        ->and($ok->fresh()->status)->toBe('done')
        ->and($ok->fresh()->result)->toBe("ID={$ok->id}&Return=0&CMD=INFO")
        ->and($bad->fresh()->status)->toBe('failed')
        ->and($foreign->fresh()->status)->toBe('sent');
});

// --- No session -----------------------------------------------------------------------------

it('sets no cookie and starts no session on any reply', function () {
    DeviceCommand::factory()->create(['device_id' => $this->device->id]);

    $replies = [
        $this->get('/iclock/cdata?SN=MB20SN01'),
        attlog("101\t2026-11-23 09:00:00\t0\t1\n"),
        $this->get('/iclock/getrequest?SN=MB20SN01'),
        $this->call('POST', '/iclock/devicecmd?SN=MB20SN01', [], [], [], [], 'ID=1&Return=0'),
        $this->call('POST', '/iclock/cdata?SN=MB20SN01&table=OPERLOG', [], [], [], [], "OPLOG 4\n"),
    ];

    foreach ($replies as $r) {
        expect($r->headers->getCookies())->toBeEmpty();
    }
});

// --- Relinking when the machine user ID changes --------------------------------------------

it('attaches earlier unmatched punches when an employee gets the machine user ID, and marks their days', function () {
    attlog("205\t2026-11-20 09:00:00\t0\t1\n205\t2026-11-21 09:00:00\t0\t1\n");
    $employee = Employee::factory()->create(['device_user_id' => '205']);

    app(PunchIngestor::class)->relink($employee, null);

    expect(DevicePunch::pluck('employee_id')->unique()->all())->toBe([$employee->id]);
    expect(Attendance::where('employee_id', $employee->id)->where('needs_rebuild', true)->count())->toBe(2);
});

it('detaches punches from the old machine user ID and attaches those of the new one', function () {
    $employee = Employee::factory()->create(['device_user_id' => '300']);
    attlog("300\t2026-11-20 09:00:00\t0\t1\n301\t2026-11-21 09:00:00\t0\t1\n");
    Attendance::query()->update(['needs_rebuild' => false]);

    $employee->forceFill(['device_user_id' => '301'])->save();
    app(PunchIngestor::class)->relink($employee, '300');

    expect(DevicePunch::where('device_user_id', '300')->value('employee_id'))->toBeNull()
        ->and(DevicePunch::where('device_user_id', '301')->value('employee_id'))->toBe($employee->id);
    expect(Attendance::where('employee_id', $employee->id)->where('needs_rebuild', true)->get()
        ->map(fn ($a) => $a->date->format('Y-m-d'))->sort()->values()->all())->toBe(['2026-11-20', '2026-11-21']);
});

it('never takes punches already linked to another employee when relinking', function () {
    $other = Employee::factory()->create(['device_user_id' => '400']);
    attlog("400\t2026-11-20 09:00:00\t0\t1\n");
    $other->forceFill(['device_user_id' => null])->save(); // without relink: the punch stays linked to $other

    $employee = Employee::factory()->create(['device_user_id' => '400']);
    app(PunchIngestor::class)->relink($employee, null);

    expect(DevicePunch::sole()->employee_id)->toBe($other->id);
});

it('relinks punches when the employee form changes the machine user ID', function () {
    Mail::fake();
    Notification::fake();
    $admin = userWithRole('admin');
    $employee = Employee::factory()->create(['device_user_id' => '500']);
    attlog("500\t2026-11-20 09:00:00\t0\t1\n501\t2026-11-21 09:00:00\t0\t1\n");

    app(UpdateEmployee::class)->handle($employee, ['device_user_id' => '501'], $admin);

    expect(DevicePunch::where('device_user_id', '500')->value('employee_id'))->toBeNull()
        ->and(DevicePunch::where('device_user_id', '501')->value('employee_id'))->toBe($employee->id);
});

it('relinks punches when a new employee is created with a machine user ID', function () {
    Mail::fake();
    Notification::fake();
    $admin = userWithRole('admin');
    attlog("600\t2026-11-20 09:00:00\t0\t1\n");
    $template = Employee::factory()->make();

    $employee = app(CreateEmployee::class)->handle([
        'first_name' => 'New', 'last_name' => 'Joiner', 'email' => 'new.joiner@example.test',
        'department_id' => $template->department_id, 'designation_id' => $template->designation_id,
        'joining_date' => '2026-11-01', 'device_user_id' => '600', 'role' => 'employee',
    ], $admin);

    expect(DevicePunch::sole()->employee_id)->toBe($employee->id)
        ->and(Attendance::where('employee_id', $employee->id)->value('needs_rebuild'))->toBeTrue();
});

// --- Fix round: body size limit (I-1) ------------------------------------------------------

it('rejects a body over the size limit with a plain-text 413, storing nothing and logging no body', function () {
    config(['hrm.devices.max_body_bytes' => 1000]);
    Log::spy();
    $line = "101\t2026-11-23 09:00:00\t0\t1\n"; // 25 bytes
    $body = str_repeat($line, 41); // 1025 bytes

    $r = attlog($body);

    expect($r->status())->toBe(413)
        ->and($r->getContent())->toBe("ERROR: body too large\n")
        ->and($r->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and(DevicePunch::count())->toBe(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => str_starts_with($message, 'iclock:')
        && ! str_contains(json_encode($context), '09:00:00'));
    $log = DeviceLog::latest('id')->first();
    expect($log->body)->toBeNull()->and($log->accepted)->toBeFalse()->and($log->summary)->toStartWith('rejected: body too large');
});

it('accepts a body of exactly the size limit', function () {
    $line = "101\t2026-11-23 09:00:00\t0\t1\n";
    config(['hrm.devices.max_body_bytes' => strlen($line) * 40]);

    expect(attlog(str_repeat($line, 40))->getContent())->toBe("OK: 40\n")
        ->and(DevicePunch::count())->toBe(1);
});

it('rejects a request whose Content-Length is over the limit before reading the body', function () {
    config(['hrm.devices.max_body_bytes' => 1000]);

    $r = test()->call('POST', '/iclock/cdata?SN=MB20SN01&table=ATTLOG', [], [], [],
        ['CONTENT_TYPE' => 'text/plain', 'CONTENT_LENGTH' => '5000'], "101\t2026-11-23 09:00:00\t0\t1\n");

    expect($r->status())->toBe(413)
        ->and($r->getContent())->toBe("ERROR: body too large\n")
        ->and(DevicePunch::count())->toBe(0);
});

it('applies the size limit to command results too', function () {
    config(['hrm.devices.max_body_bytes' => 100]);

    $r = $this->call('POST', '/iclock/devicecmd?SN=MB20SN01', [], [], [], [], str_repeat('ID=1&Return=0&CMD=INFO', 10));

    expect($r->status())->toBe(413);
});

it('defaults the body size limit to 4 MB', function () {
    expect(config('hrm.devices.max_body_bytes'))->toBe(4194304);
});

// --- Fix round: relink keeps closed history (I-2) ------------------------------------------

it('moves a reused PIN to the new employee without rewriting the closed days of the one who left', function () {
    $a = Employee::factory()->create(['device_user_id' => '101', 'joining_date' => '2025-01-01']);
    attlog("101\t2026-11-20 09:00:00\t0\t1\n101\t2026-11-23 09:00:00\t0\t1\n");
    Attendance::where('employee_id', $a->id)->onDate('2026-11-20')->update(['closed' => true, 'status' => 'present', 'needs_rebuild' => false]);
    Attendance::where('employee_id', $a->id)->onDate('2026-11-23')->update(['status' => 'present', 'needs_rebuild' => false]);

    // A leaves: the PIN is cleared.
    $a->forceFill(['device_user_id' => null])->save();
    app(PunchIngestor::class)->relink($a, '101');

    expect(DevicePunch::where('punch_date', '2026-11-20')->value('employee_id'))->toBe($a->id)
        ->and(DevicePunch::where('punch_date', '2026-11-23')->value('employee_id'))->toBeNull()
        ->and(Attendance::where('employee_id', $a->id)->onDate('2026-11-20')->value('needs_rebuild'))->toBeFalse()
        ->and(Attendance::where('employee_id', $a->id)->onDate('2026-11-23')->value('needs_rebuild'))->toBeTrue();

    // A punch under the free PIN before B joins, then B joins on 2026-11-22 with the same PIN.
    attlog("101\t2026-11-21 09:00:00\t0\t1\n");
    $b = Employee::factory()->create(['device_user_id' => '101', 'joining_date' => '2026-11-22']);
    app(PunchIngestor::class)->relink($b, null);

    expect(DevicePunch::where('punch_date', '2026-11-20')->value('employee_id'))->toBe($a->id)
        ->and(DevicePunch::where('punch_date', '2026-11-21')->value('employee_id'))->toBeNull()
        ->and(DevicePunch::where('punch_date', '2026-11-23')->value('employee_id'))->toBe($b->id)
        ->and(Attendance::where('employee_id', $b->id)->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all())->toBe(['2026-11-23']);
});

it('keeps punches on closed days with the employee when the machine user ID is cleared', function () {
    $a = Employee::factory()->create(['device_user_id' => '101']);
    attlog("101\t2026-11-20 09:00:00\t0\t1\n101\t2026-11-21 09:00:00\t0\t1\n");
    Attendance::query()->update(['closed' => true, 'status' => 'present', 'needs_rebuild' => false]);
    $before = Attendance::orderBy('id')->get()->map->getAttributes()->all();

    $a->forceFill(['device_user_id' => null])->save();
    app(PunchIngestor::class)->relink($a, '101');

    expect(DevicePunch::pluck('employee_id')->unique()->all())->toBe([$a->id])
        ->and(Attendance::orderBy('id')->get()->map->getAttributes()->all())->toBe($before);
});

it('corrects a mistyped machine user ID on the same open day, flagging only that day', function () {
    $e = Employee::factory()->create(['device_user_id' => '11']);
    attlog("11\t2026-11-24 09:00:00\t0\t1\n101\t2026-11-24 09:01:00\t0\t1\n");
    Attendance::query()->update(['status' => 'present', 'needs_rebuild' => false]);
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-19', 'closed' => true]);

    $e->forceFill(['device_user_id' => '101'])->save();
    app(PunchIngestor::class)->relink($e, '11');

    expect(DevicePunch::where('device_user_id', '11')->value('employee_id'))->toBeNull()
        ->and(DevicePunch::where('device_user_id', '101')->value('employee_id'))->toBe($e->id)
        ->and(Attendance::where('needs_rebuild', true)->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all())->toBe(['2026-11-24']);
});

it('never flags a manual day when relinking', function () {
    $e = Employee::factory()->create(['device_user_id' => '11']);
    attlog("11\t2026-11-24 09:00:00\t0\t1\n");
    Attendance::query()->update(['status' => 'present', 'needs_rebuild' => false, 'is_manual' => true]);

    $e->forceFill(['device_user_id' => '12'])->save();
    app(PunchIngestor::class)->relink($e, '11');

    expect(Attendance::sole()->needs_rebuild)->toBeFalse();
});

// --- Fix round: pending status (I-3) --------------------------------------------------------

it('creates a new day row with no status, meaning pending calculation', function () {
    Employee::factory()->create(['device_user_id' => '101']);

    attlog("101\t2026-11-23 09:00:00\t0\t1\n");

    expect(Attendance::sole()->status)->toBeNull();
});

// --- Fix round: stamp validation (I-4) ------------------------------------------------------

it('ignores a stamp that is not 1 to 20 digits', function (string $stamp) {
    attlog("101\t2026-11-23 09:00:00\t0\t1\n", stamp: '500');
    attlog("101\t2026-11-23 09:01:00\t0\t1\n", stamp: $stamp);

    expect(Device::find($this->device->id)->last_stamp)->toBe('500');
})->with([
    'letters' => 'abc',
    'mixed' => '12a',
    'negative' => '-5',
    'decimal' => '600.5',
    '21 digits' => str_repeat('9', 21),
    'inner space' => '600%201',
]);

it('accepts a 20-digit stamp', function () {
    attlog("101\t2026-11-23 09:00:00\t0\t1\n", stamp: str_repeat('9', 20));

    expect(Device::find($this->device->id)->last_stamp)->toBe(str_repeat('9', 20));
});

it('never moves the stamp backwards, comparing numerically', function () {
    attlog("101\t2026-11-23 09:00:00\t0\t1\n", stamp: '999');
    attlog("101\t2026-11-23 09:01:00\t0\t1\n", stamp: '1000');
    expect(Device::find($this->device->id)->last_stamp)->toBe('1000');

    attlog("101\t2026-11-23 09:02:00\t0\t1\n", stamp: '998');
    expect(Device::find($this->device->id)->last_stamp)->toBe('1000');

    attlog("101\t2026-11-23 09:03:00\t0\t1\n", stamp: '0001001');
    expect(Device::find($this->device->id)->last_stamp)->toBe('0001001');
});

// --- Fix round: lost rows are never acknowledged (M-2) --------------------------------------

it('replies retry instead of acknowledging when stored rows are missing after the insert', function () {
    DB::statement("CREATE TRIGGER lose_punch BEFORE INSERT ON device_punches WHEN NEW.device_user_id = '666' BEGIN SELECT RAISE(IGNORE); END");

    $r = attlog("101\t2026-11-23 09:00:00\t0\t1\n666\t2026-11-23 09:00:00\t0\t1\n");

    expect($r->status())->toBe(500)
        ->and($r->getContent())->toBe("ERROR: retry\n")
        ->and(DevicePunch::count())->toBe(0);
});

// --- Fix round: every failure answers retry (M-4) -------------------------------------------

it('answers a failing command poll with a plain-text retry and logs it', function () {
    Schema::rename('device_commands', 'device_commands_away');

    $r = $this->get('/iclock/getrequest?SN=MB20SN01');

    expect($r->status())->toBe(500)
        ->and($r->getContent())->toBe("ERROR: retry\n")
        ->and($r->headers->get('Content-Type'))->toBe('text/plain; charset=UTF-8')
        ->and(DeviceLog::latest('id')->first()->summary)->toStartWith('request failed');
    Schema::rename('device_commands_away', 'device_commands');
});

it('answers a failing command result with a plain-text retry and logs it', function () {
    Schema::rename('device_commands', 'device_commands_away');

    $r = $this->call('POST', '/iclock/devicecmd?SN=MB20SN01', [], [], [], [], "ID=1&Return=0&CMD=INFO\n");

    expect($r->status())->toBe(500)
        ->and($r->getContent())->toBe("ERROR: retry\n")
        ->and(DeviceLog::latest('id')->first()->summary)->toStartWith('request failed');
    Schema::rename('device_commands_away', 'device_commands');
});

it('answers the handshake with the app zone when the settings table cannot be read', function () {
    config(['app.timezone' => 'Asia/Kolkata']);
    // The table is there but cannot be read (database error): SettingsStore rethrows.
    app()->instance(SettingsStore::class, new class extends SettingsStore
    {
        public function all(): array
        {
            throw new RuntimeException('settings unreadable');
        }
    });

    $r = $this->get('/iclock/cdata?SN=MB20SN01');

    expect($r->status())->toBe(200)->and($r->getContent())->toContain("TimeZone=5.5\n");
});

// --- Fix round: HTTP edge cases (M-5) -------------------------------------------------------

it('stores a CRLF body', function () {
    expect(attlog("101\t2026-11-23 09:00:00\t0\t1\r\n102\t2026-11-23 09:01:00\t0\t1\r\n")->getContent())->toBe("OK: 2\n")
        ->and(DevicePunch::orderBy('device_user_id')->pluck('device_user_id')->all())->toBe(['101', '102']);
});

it('ignores trailing blank lines', function () {
    expect(attlog("101\t2026-11-23 09:00:00\t0\t1\n\n\r\n\n")->getContent())->toBe("OK: 1\n")
        ->and(DeviceLog::latest('id')->first()->summary)->toBe('ATTLOG upload: 1 stored, 0 duplicate(s), 0 skipped');
});

it('skips a non-UTF-8 PIN and blanks a non-UTF-8 status', function () {
    $r = attlog("\xFF\xFE\t2026-11-23 09:00:00\t0\t1\n101\t2026-11-23 09:01:00\t\xFF\t1\n");

    expect($r->getContent())->toBe("OK: 1\n")
        ->and(DevicePunch::sole()->device_user_id)->toBe('101')
        ->and(DevicePunch::sole()->status_code)->toBeNull();
});

it('skips a PIN of 21 or more characters', function () {
    $r = attlog(str_repeat('9', 21)."\t2026-11-23 09:00:00\t0\t1\n".str_repeat('8', 20)."\t2026-11-23 09:00:00\t0\t1\n");

    expect($r->getContent())->toBe("OK: 1\n")
        ->and(DevicePunch::sole()->device_user_id)->toBe(str_repeat('8', 20));
});
