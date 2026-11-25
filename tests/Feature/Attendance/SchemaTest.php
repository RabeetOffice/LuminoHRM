<?php

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\DefaultShift;
use App\Support\EmployeeAccess;
use App\Support\Settings;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Activity;

/** Runs the app as a server whose own clock zone is $zone (config and PHP default both). */
function scZone(string $zone): void
{
    config(['app.timezone' => $zone]);
    date_default_timezone_set($zone);
}

/** Re-runs the default-shift data migration, as a second `migrate` would if it were not recorded. */
function scRunDefaultShiftMigration(): void
{
    (require database_path('migrations/2026_11_24_200100_create_default_shift.php'))->up();
}

/** A complete valid payload for POST /employees, merged with $overrides. */
function scPayload(array $overrides = []): array
{
    $dept = Department::factory()->create();
    $desig = Designation::factory()->create(['department_id' => $dept->id]);

    return array_merge([
        'first_name' => 'Bilal',
        'last_name' => 'Ahmed',
        'email' => 'bilal.ahmed@corp.test',
        'department_id' => $dept->id,
        'designation_id' => $desig->id,
        'joining_date' => '2024-03-01',
        'employment_type' => 'full_time',
        'status' => 'active',
        'role' => 'employee',
    ], $overrides);
}

beforeEach(function () {
    Notification::fake();
});

// ---------------------------------------------------------------- schema

it('creates the attendance tables and the two employee columns', function () {
    $tables = [
        'shifts' => ['id', 'name', 'start_time', 'end_time', 'grace_minutes', 'half_day_hours', 'weekly_offs', 'is_default', 'created_at', 'updated_at'],
        'devices' => ['id', 'name', 'serial_no', 'is_active', 'last_seen_at', 'last_stamp', 'last_punch_at', 'created_at', 'updated_at'],
        'device_punches' => ['id', 'device_id', 'device_user_id', 'employee_id', 'punch_time', 'punch_date', 'verify_type', 'status_code', 'source', 'created_at'],
        'device_commands' => ['id', 'device_id', 'command', 'status', 'sent_at', 'result', 'created_by', 'created_at', 'updated_at'],
        'attendances' => ['id', 'employee_id', 'date', 'shift_id', 'shift_rules', 'clock_in', 'clock_out', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'status', 'is_manual', 'status_overridden', 'needs_attention', 'needs_rebuild', 'closed', 'note', 'created_at', 'updated_at'],
        'attendance_corrections' => ['id', 'employee_id', 'date', 'requested_in', 'requested_out', 'reason', 'status', 'manager_id', 'manager_decided_at', 'hr_id', 'hr_decided_at', 'decision_note', 'created_at', 'updated_at'],
        'holidays' => ['id', 'name', 'date', 'created_at', 'updated_at'],
    ];

    foreach ($tables as $table => $columns) {
        expect(Schema::getColumnListing($table))->toEqualCanonicalizing($columns);
    }

    expect(Schema::hasColumns('employees', ['shift_id', 'attendance_exempt']))->toBeTrue()
        ->and(Schema::hasColumn('device_punches', 'updated_at'))->toBeFalse();
});

it('gives existing and new employees no shift and no exemption by default', function () {
    $employee = Employee::factory()->create();

    expect($employee->fresh()->shift_id)->toBeNull()
        ->and($employee->fresh()->attendance_exempt)->toBeFalse()
        ->and(Shift::forEmployee($employee)->is_default)->toBeTrue();
});

it('backs up every new table (none is in the data exclusion list)', function () {
    $excluded = config('hrm.backup.exclude_data');

    foreach (['shifts', 'devices', 'device_punches', 'device_commands', 'attendances', 'attendance_corrections', 'holidays'] as $table) {
        expect($excluded)->not->toContain($table);
    }

    expect($excluded)->not->toContain('device_logs');
});

it('declares the attendance enums in config', function () {
    expect(config('hrm.attendance.statuses'))->toBe(['present', 'late', 'half_day', 'absent', 'on_leave', 'holiday', 'weekend'])
        ->and(config('hrm.attendance.correction_statuses'))->toBe(['pending', 'manager_approved', 'approved', 'rejected', 'cancelled'])
        ->and(config('hrm.attendance.punch_sources'))->toBe(['device', 'import', 'manual']);
});

// ---------------------------------------------------------------- default shift

it('creates the General default shift from the attendance settings', function () {
    $shifts = Shift::all();

    expect($shifts)->toHaveCount(1);

    $general = $shifts->first();
    expect($general->name)->toBe('General')
        ->and($general->is_default)->toBeTrue()
        ->and($general->start_time)->toBe('09:00')
        ->and($general->end_time)->toBe('18:00')
        ->and($general->grace_minutes)->toBe(15)
        ->and($general->half_day_hours)->toBe('4.00')
        ->and($general->weekly_offs)->toBe(['saturday', 'sunday'])
        ->and(Shift::default()->is($general))->toBeTrue();
});

it('never duplicates the default shift when the data migration runs again', function () {
    Settings::set('attendance', ['shift_start' => '08:00', 'shift_end' => '17:00']);

    scRunDefaultShiftMigration();
    scRunDefaultShiftMigration();

    expect(Shift::count())->toBe(1)
        ->and(Shift::query()->where('is_default', true)->count())->toBe(1)
        ->and(Shift::default()->start_time)->toBe('09:00'); // an existing default is left as it is
});

it('builds the default from saved settings when there is none yet', function () {
    DB::table('shifts')->delete();
    Settings::set('attendance', [
        'shift_start' => '08:30', 'shift_end' => '17:30', 'grace_minutes' => 10, 'half_day_hours' => 5,
        'weekly_offs' => ['sunday', 'friday'],
    ]);

    scRunDefaultShiftMigration();

    $general = Shift::default();
    expect(Shift::count())->toBe(1)
        ->and($general->name)->toBe('General')
        ->and($general->start_time)->toBe('08:30')
        ->and($general->end_time)->toBe('17:30')
        ->and($general->grace_minutes)->toBe(10)
        ->and($general->half_day_hours)->toBe('5.00')
        ->and($general->weekly_offs)->toBe(['friday', 'sunday']);
});

it('falls back to the config defaults for unusable saved settings', function () {
    DB::table('shifts')->delete();
    // Hand-edited rows: the end before the start, and an unknown day.
    Settings::set('attendance', ['shift_start' => '18:00', 'shift_end' => '09:00', 'weekly_offs' => ['sunday', 'Funday']]);

    $values = DefaultShift::values();
    expect($values['start_time'])->toBe('09:00:00')
        ->and($values['end_time'])->toBe('18:00:00')
        ->and($values['weekly_offs'])->toBe(['sunday']);

    expect(Shift::default()->name)->toBe('General'); // created on demand when missing
});

it('falls back to the config default start when the saved start is too close after the day cutoff', function () {
    DB::table('shifts')->delete();
    Settings::set('attendance', ['shift_start' => '06:00', 'shift_end' => '15:00', 'day_cutoff_time' => '05:00']);

    expect(DefaultShift::values())->toMatchArray(['start_time' => '09:00:00', 'end_time' => '18:00:00']);

    // Two hours after the cutoff is enough: kept.
    Settings::set('attendance', ['shift_start' => '07:00', 'shift_end' => '15:00', 'day_cutoff_time' => '05:00']);
    expect(DefaultShift::values())->toMatchArray(['start_time' => '07:00:00', 'end_time' => '15:00:00']);
});

it('makes a hand-made shift called General the default instead of adding a second one', function () {
    DB::table('shifts')->delete();
    Shift::factory()->create(['name' => 'General', 'start_time' => '10:00', 'end_time' => '19:00']);

    scRunDefaultShiftMigration();

    expect(Shift::count())->toBe(1)
        ->and(Shift::default()->start_time)->toBe('10:00');
});

it('never lets two shifts be the default', function () {
    $general = Shift::default();

    expect(fn () => Shift::factory()->create(['is_default' => true]))->toThrow(LogicException::class);

    $night = Shift::factory()->create(['name' => 'Late']);
    $night->is_default = true;
    expect(fn () => $night->save())->toThrow(LogicException::class);

    Shift::makeDefault($night);

    expect(Shift::query()->where('is_default', true)->pluck('id')->all())->toBe([$night->id])
        ->and($general->fresh()->is_default)->toBeFalse()
        ->and(Shift::default()->is($night))->toBeTrue();

    Shift::makeDefault($night); // already the default: nothing changes
    Shift::makeDefault($general);

    expect(Shift::query()->where('is_default', true)->pluck('id')->all())->toBe([$general->id]);
});

it('refuses to delete the default shift', function () {
    expect(fn () => Shift::default()->delete())->toThrow(LogicException::class)
        ->and(Shift::count())->toBe(1);
});

it('uses the employee\'s own shift, or the default when they have none', function () {
    $employee = Employee::factory()->create();
    $own = shiftFor($employee, ['name' => 'Early', 'start_time' => '07:00', 'end_time' => '15:00']);
    $other = Employee::factory()->create();

    expect(Shift::forEmployee($employee->fresh())->is($own))->toBeTrue()
        ->and(Shift::forEmployee($other)->is(Shift::default()))->toBeTrue()
        ->and($employee->fresh()->shift->name)->toBe('Early');
});

// ---------------------------------------------------------------- shift values

it('reads shift times as H:i and stores them as TIME values', function () {
    $shift = Shift::factory()->create(['start_time' => '07:30:00', 'end_time' => '16:45']);

    expect($shift->fresh()->start_time)->toBe('07:30')
        ->and($shift->fresh()->end_time)->toBe('16:45')
        ->and(DB::table('shifts')->where('id', $shift->id)->value('start_time'))->toBe('07:30:00')
        ->and($shift->fresh()->toArray())->toMatchArray(['start_time' => '07:30', 'end_time' => '16:45'])
        ->and($shift->minutes())->toBe(555);
});

it('refuses a shift that does not end after it starts, or has a bad time', function (array $attrs) {
    Shift::factory()->create($attrs);
})->with([
    'end before start' => [['start_time' => '18:00', 'end_time' => '09:00']],
    'end equals start' => [['start_time' => '09:00', 'end_time' => '09:00']],
    'across midnight' => [['start_time' => '22:00', 'end_time' => '06:00']],
    'bad hour' => [['start_time' => '24:00', 'end_time' => '09:00']],
    'not a time' => [['start_time' => '9am', 'end_time' => '18:00']],
    'negative grace' => [['grace_minutes' => -1]],
    'zero half day' => [['half_day_hours' => 0]],
])->throws(InvalidArgumentException::class);

it('keeps weekly offs as known lowercase day names in week order', function () {
    $shift = Shift::factory()->create(['weekly_offs' => ['Sunday', ' FRIDAY ', 'sunday']]);

    expect($shift->fresh()->weekly_offs)->toBe(['friday', 'sunday']);

    $none = Shift::factory()->create(['weekly_offs' => []]);
    expect($none->fresh()->weekly_offs)->toBe([]);
});

it('refuses an unknown weekly off day', function (mixed $offs) {
    Shift::factory()->create(['weekly_offs' => $offs]);
})->with([
    [['funday']],
    [['sat']],
    [[1]],
    ['sunday'],
])->throws(InvalidArgumentException::class);

it('audits shift changes in the attendance area', function () {
    $shift = Shift::factory()->create(['name' => 'Ramadan']);
    $shift->update(['end_time' => '15:00']);

    $entries = Activity::query()->where('log_name', 'attendance')->where('subject_type', $shift->getMorphClass())->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['Shift created', 'Shift updated'])
        ->and($entries->last()->properties['old']['end_time'])->toBe('18:00')
        ->and($entries->last()->properties['attributes']['end_time'])->toBe('15:00');
});

// ---------------------------------------------------------------- punches: naive times

it('stores and reads a punch time exactly as written, whatever the app timezone', function (string $writeZone, string $readZone) {
    scZone($writeZone);
    $punch = punch('501', '2026-11-24 23:59:59');

    scZone($readZone);
    $read = DevicePunch::query()->findOrFail($punch->id);

    expect($read->punch_time->format('Y-m-d H:i:s'))->toBe('2026-11-24 23:59:59')
        ->and($read->getRawOriginal('punch_time'))->toStartWith('2026-11-24 23:59:59')
        ->and(DB::table('device_punches')->where('id', $punch->id)->value('punch_time'))->toStartWith('2026-11-24 23:59:59')
        ->and($read->punch_date)->toBe('2026-11-24')
        ->and($read->toArray()['punch_time'])->toBe('2026-11-24 23:59:59')
        ->and(json_decode($read->toJson(), true)['punch_time'])->toBe('2026-11-24 23:59:59');
})->with([
    ['UTC', 'UTC'],
    ['Asia/Karachi', 'Asia/Karachi'],
    ['UTC', 'Asia/Karachi'],
    ['Asia/Karachi', 'UTC'],
    ['America/New_York', 'Pacific/Kiritimati'],
]);

it('stores a DateTime as its wall clock in the company zone', function () {
    scZone('UTC');
    // 00:01 in New York is 10:01 the same day in Asia/Karachi (the company zone).
    $at = new DateTimeImmutable('2026-11-25 00:01:00', new DateTimeZone('America/New_York'));

    $punch = DevicePunch::factory()->create(['punch_time' => $at]);

    expect($punch->fresh()->punch_time->format('Y-m-d H:i:s'))->toBe('2026-11-25 10:01:00')
        ->and($punch->fresh()->punch_date)->toBe('2026-11-25');

    // A value already in the company zone (read from the cast, or from AttendanceClock) is stored unchanged.
    $again = DevicePunch::factory()->create(['punch_time' => $punch->fresh()->punch_time->addSecond()]);
    expect($again->fresh()->punch_time->format('Y-m-d H:i:s'))->toBe('2026-11-25 10:01:01');
});

it('labels a read punch with the company zone so it compares with the attendance clock', function () {
    scZone('UTC');
    $punch = punch('502', '2026-11-24 09:00:00');

    expect($punch->fresh()->punch_time->getTimezone()->getName())->toBe('Asia/Karachi');
});

it('keeps punch_date equal to the attendance day of punch_time, and never lets either drift', function () {
    // Before the 04:00 day cutoff a punch belongs to the previous day (AttendanceClock::attendanceDateFor).
    $punch = punch('503', '2026-11-25 00:00:00');

    expect($punch->punch_date)->toBe('2026-11-24');

    // punch_date cannot be set on its own...
    $punch->forceFill(['punch_date' => '1999-01-01', 'verify_type' => '15'])->save();
    expect($punch->fresh()->punch_date)->toBe('2026-11-24');

    // ...and a stored punch time is never changed.
    $punch->punch_time = '2026-11-26 10:00:00';
    expect(fn () => $punch->save())->toThrow(LogicException::class);

    // Every punch written through the model agrees.
    punch('503', '2026-11-25 23:59:59');
    punch('503', '2026-11-24 00:00:01');
    punch('503', '2026-11-24 04:00:00');
    foreach (DB::table('device_punches')->get() as $row) {
        expect($row->punch_date)->toStartWith(app(AttendanceClock::class)->attendanceDateFor(substr($row->punch_time, 0, 19)));
    }
    expect(DB::table('device_punches')->orderBy('punch_time')->pluck('punch_date')->map(fn ($d) => substr((string) $d, 0, 10))->all())
        ->toBe(['2026-11-23', '2026-11-24', '2026-11-24', '2026-11-25']);
});

it('refuses a punch time that is not a naive Y-m-d H:i:s', function (mixed $value) {
    punch('504', $value);
})->with(['2026-11-24T09:00:00Z', '24/11/2026 09:00', '2026-11-24', 'now', '2026-02-30 09:00:00'])
    ->throws(InvalidArgumentException::class);

it('stores a punch once: the same device, user and time is refused by the unique key', function () {
    $device = Device::factory()->create();
    punch('600', '2026-11-24 09:00:00', $device);

    expect(fn () => punch('600', '2026-11-24 09:00:00', $device))->toThrow(UniqueConstraintViolationException::class);

    // A different second, user or device is a different punch.
    punch('600', '2026-11-24 09:00:01', $device);
    punch('601', '2026-11-24 09:00:00', $device);
    punch('600', '2026-11-24 09:00:00', Device::factory()->create());

    expect(DevicePunch::count())->toBe(4);
});

it('links a punch helper call to the employee by machine user ID, giving one when missing', function () {
    $employee = Employee::factory()->create(['device_user_id' => null]);
    $p = punch($employee, '2026-11-24 09:05');

    expect($employee->fresh()->device_user_id)->not->toBeNull()
        ->and($p->employee_id)->toBe($employee->id)
        ->and($p->device_user_id)->toBe($employee->fresh()->device_user_id)
        ->and($p->fresh()->punch_time->format('Y-m-d H:i:s'))->toBe('2026-11-24 09:05:00');

    $byId = punch((string) $employee->fresh()->device_user_id, '2026-11-24 18:00');
    $unknown = punch('99999', '2026-11-24 18:00');

    expect($byId->employee_id)->toBe($employee->id)
        ->and($unknown->employee_id)->toBeNull()
        ->and(Device::count())->toBe(1);
});

it('refuses an unknown punch source', function () {
    DevicePunch::factory()->create(['source' => 'telepathy']);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- attendances

it('stores attendance clock times naively and the day as a bare date', function (string $writeZone, string $readZone) {
    scZone($writeZone);
    $a = Attendance::factory()->create([
        'date' => '2026-11-24',
        'clock_in' => '2026-11-24 08:59:30',
        'clock_out' => '2026-11-24 23:59:59',
    ]);

    scZone($readZone);
    $read = Attendance::query()->findOrFail($a->id);

    expect($read->clock_in->format('Y-m-d H:i:s'))->toBe('2026-11-24 08:59:30')
        ->and($read->clock_out->format('Y-m-d H:i:s'))->toBe('2026-11-24 23:59:59')
        ->and($read->date->format('Y-m-d'))->toBe('2026-11-24')
        ->and(DB::table('attendances')->where('id', $a->id)->value('date'))->toBe('2026-11-24')
        ->and(Attendance::query()->where('date', '2026-11-24')->count())->toBe(1)
        ->and($read->toArray())->toMatchArray(['date' => '2026-11-24', 'clock_in' => '2026-11-24 08:59:30', 'clock_out' => '2026-11-24 23:59:59']);
})->with([
    ['UTC', 'Asia/Karachi'],
    ['Asia/Karachi', 'UTC'],
]);

it('keeps one attendance record per employee per day', function () {
    $employee = Employee::factory()->create();
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24']);

    expect(fn () => Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24']))
        ->toThrow(UniqueConstraintViolationException::class);

    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-25']);
    expect($employee->attendances()->count())->toBe(2);
});

it('refuses an unknown attendance status', function () {
    Attendance::factory()->create(['status' => 'sleeping']);
})->throws(InvalidArgumentException::class);

// ---------------------------------------------------------------- foreign keys and delete rules

it('blocks deleting an employee who has attendance records', function () {
    $admin = userWithRole('admin');
    $employee = Employee::factory()->create();
    $access = new EmployeeAccess($admin);

    expect($access->deleteBlockers($employee))->toBe([]);

    Attendance::factory()->create(['employee_id' => $employee->id]);

    expect($access->deleteBlockers($employee))->toBe(['This employee has attendance records. Set the status to Inactive, Resigned or Terminated instead.'])
        ->and($access->canDelete($employee))->toBeFalse()
        ->and(fn () => DB::table('employees')->where('id', $employee->id)->delete())->toThrow(QueryException::class);
});

it('clears the shift of employees and attendance days when a shift is deleted', function () {
    $employee = Employee::factory()->create();
    $shift = shiftFor($employee);
    $day = Attendance::factory()->create(['employee_id' => $employee->id, 'shift_id' => $shift->id]);

    $shift->delete();

    expect($employee->fresh()->shift_id)->toBeNull()
        ->and($day->fresh()->shift_id)->toBeNull();
});

it('keeps punches when their employee is deleted and refuses deleting a device that has punches', function () {
    $employee = Employee::factory()->create();
    $p = punch($employee, '2026-11-24 09:00');

    $employee->delete();
    expect($p->fresh()->employee_id)->toBeNull();

    expect(fn () => $p->device->delete())->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- factories

it('has a factory for every attendance model that makes valid rows', function () {
    $shift = Shift::factory()->create();
    $device = Device::factory()->create();
    $punch = DevicePunch::factory()->create();
    $command = DeviceCommand::factory()->create();
    $attendance = Attendance::factory()->create();
    $correction = AttendanceCorrection::factory()->create();
    $holidays = Holiday::factory()->count(3)->create();

    expect($shift->fresh()->is_default)->toBeFalse()
        ->and($device->fresh()->is_active)->toBeTrue()
        ->and($punch->fresh()->punch_date)->toBe(substr($punch->getRawOriginal('punch_time'), 0, 10))
        ->and($command->fresh()->status)->toBe('queued')
        ->and($attendance->fresh()->worked_minutes)->toBe(540)
        ->and($attendance->fresh()->clock_in->format('Y-m-d H:i:s'))->toBe('2026-11-24 09:00:00')
        ->and($correction->fresh()->requested_in)->toBe('09:00')
        ->and($correction->fresh()->date->format('Y-m-d'))->toBe('2026-11-24')
        ->and($holidays->pluck('date')->map->format('Y-m-d')->unique())->toHaveCount(3);
});

it('audits holidays and devices in the attendance area, but not a device heartbeat', function () {
    $holiday = Holiday::factory()->create(['name' => 'Quaid Day', 'date' => '2026-12-25']);
    $device = Device::factory()->create(['name' => 'Gate']);
    $device->update(['last_seen_at' => now(), 'last_stamp' => '123']);

    $entries = Activity::query()->where('log_name', 'attendance')->orderBy('id')->get();

    expect($entries->pluck('description')->all())->toBe(['Holiday created', 'Device created'])
        ->and($entries->first()->properties['attributes'])->toMatchArray(['name' => 'Quaid Day', 'date' => '2026-12-25']);
});

// ---------------------------------------------------------------- the employee form

it('accepts and stores a shift and the exempt flag on the employee form', function () {
    $hr = userWithRole('hr');
    $shift = Shift::factory()->create(['name' => 'Early']);

    $this->actingAs($hr)->post('/employees', scPayload(['shift_id' => $shift->id, 'attendance_exempt' => true]))
        ->assertSessionHasNoErrors();

    $employee = Employee::where('email', 'bilal.ahmed@corp.test')->firstOrFail();
    expect($employee->shift_id)->toBe($shift->id)
        ->and($employee->attendance_exempt)->toBeTrue();

    $entry = Activity::query()->where('log_name', 'employees')->where('description', 'Employee created')->latest('id')->firstOrFail();
    expect($entry->properties['attributes'])->toMatchArray(['shift_id' => $shift->id, 'attendance_exempt' => true]);
});

it('leaves shift and exemption unset when the form does not send them, and keeps them on an edit without them', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', scPayload())->assertSessionHasNoErrors();
    $employee = Employee::where('email', 'bilal.ahmed@corp.test')->firstOrFail();

    expect($employee->shift_id)->toBeNull()->and($employee->attendance_exempt)->toBeFalse();

    $shift = shiftFor($employee);
    $employee->forceFill(['attendance_exempt' => true])->save();

    $edit = [
        'first_name' => 'Bilal', 'last_name' => 'Ahmed', 'email' => 'bilal.ahmed@corp.test',
        'department_id' => $employee->department_id, 'designation_id' => $employee->designation_id,
        'joining_date' => '2024-03-01', 'employment_type' => 'full_time', 'role' => 'employee',
    ];
    $this->actingAs($hr)->put("/employees/{$employee->id}", $edit)->assertSessionHasNoErrors();

    expect($employee->fresh()->shift_id)->toBe($shift->id)
        ->and($employee->fresh()->attendance_exempt)->toBeTrue();

    // An explicit null shift means "the default", and false clears the exemption.
    $this->actingAs($hr)->put("/employees/{$employee->id}", [...$edit, 'shift_id' => null, 'attendance_exempt' => false])->assertSessionHasNoErrors();

    expect($employee->fresh()->shift_id)->toBeNull()
        ->and($employee->fresh()->attendance_exempt)->toBeFalse();
});

it('refuses an unknown shift and a non-boolean exempt flag', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/employees', scPayload(['shift_id' => 99999, 'attendance_exempt' => 'perhaps']))
        ->assertSessionHasErrors(['shift_id', 'attendance_exempt']);

    expect(Employee::where('email', 'bilal.ahmed@corp.test')->exists())->toBeFalse();
});

// ---------------------------------------------------------------- the profile

it('shows the shift to every viewer of the employment group and the exempt flag to Admin and HR only', function () {
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser);
    $report = Employee::factory()->create(['manager_id' => $manager->id, 'attendance_exempt' => true]);
    $hr = userWithRole('hr');
    $admin = userWithRole('admin');

    $employment = fn ($user, Employee $e) => $this->actingAs($user)->get("/employees/{$e->id}")->assertOk()
        ->viewData('page')['props']['employee']['employment'];

    $asHr = $employment($hr, $report);
    $asAdmin = $employment($admin, $report);
    $asManager = $employment($managerUser, $report);
    $ownAsManager = $employment($managerUser, $manager);

    expect($asHr)->toMatchArray(['shift' => 'General', 'shift_is_default' => true, 'attendance_exempt' => true])
        ->and($asAdmin)->toMatchArray(['shift' => 'General', 'shift_is_default' => true, 'attendance_exempt' => true])
        ->and($asManager)->toMatchArray(['shift' => 'General', 'shift_is_default' => true])
        ->and($asManager)->not->toHaveKey('attendance_exempt')
        ->and($ownAsManager)->not->toHaveKey('attendance_exempt')
        // Exactly the Phase 2 employment keys plus the shift name and its default flag: nothing restricted.
        ->and(array_keys($asManager))->toBe([
            'department', 'designation', 'manager', 'joining_date', 'probation_end_date', 'employment_type', 'status',
            'last_working_day', 'reports', 'shift', 'shift_is_default',
        ]);

    shiftFor($report, ['name' => 'Night Owl', 'start_time' => '12:00', 'end_time' => '21:00']);

    expect($employment($managerUser, $report))->toMatchArray(['shift' => 'Night Owl', 'shift_is_default' => false])
        ->and(json_encode($employment($managerUser, $report)))->not->toContain('12:00')->not->toContain('21:00');
});
