<?php

use App\Actions\Employees\UpdateEmployee;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\Leavers;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/*
| Leavers without a last working day (I-3) and employment changes re-marking stored days (M-2).
| "Now" is 2026-11-25 (a Wednesday) 10:00 in Karachi: the open attendance day is the 25th. Attendance started on
| 2026-11-01. Default shift 09:00-18:00, Saturday and Sunday off.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-11-01']);
    $this->device = Device::factory()->create(['serial_no' => 'SNWINDOW']);
});

function ewLine(string $pin, string $at): string
{
    return "{$pin}\t{$at}\t0\t1\n";
}

function ewRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

it('has one predicate for a leaver without a last working day', function () {
    expect(Leavers::withoutLastDay(Employee::factory()->make(['status' => 'inactive', 'last_working_day' => null])))->toBeTrue()
        ->and(Leavers::withoutLastDay(Employee::factory()->make(['status' => 'resigned', 'last_working_day' => '2026-11-20'])))->toBeFalse()
        ->and(Leavers::withoutLastDay(Employee::factory()->make(['status' => 'active', 'last_working_day' => null])))->toBeFalse();

    $gone = Employee::factory()->create(['status' => 'terminated', 'last_working_day' => null]);
    $dated = Employee::factory()->create(['status' => 'terminated', 'last_working_day' => '2026-11-20']);
    $working = Employee::factory()->create(['status' => 'active']);

    expect(Leavers::exceptWithoutLastDay(Employee::query())->pluck('id')->sort()->values()->all())->toBe([$dated->id, $working->id])
        ->and(Leavers::onlyWithoutLastDay(Employee::query())->pluck('id')->all())->toBe([$gone->id]);
});

it('leaves the punches of a leaver without a last working day unknown at ingestion', function () {
    $gone = Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'inactive', 'last_working_day' => null, 'device_user_id' => '101']);
    $dated = Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'resigned', 'last_working_day' => '2026-11-30', 'device_user_id' => '102']);

    app(PunchIngestor::class)->ingest($this->device, ewLine('101', '2026-11-25 09:00:00').ewLine('102', '2026-11-25 09:01:00'), null);

    expect(DevicePunch::query()->where('device_user_id', '101')->value('employee_id'))->toBeNull()
        ->and(DevicePunch::query()->where('device_user_id', '102')->value('employee_id'))->toBe($dated->id)
        ->and(ewRow($gone, '2026-11-25'))->toBeNull();
});

it('attaches nothing to a leaver without a last working day when their machine user ID is set', function () {
    punch('201', '2026-11-24 09:00:00');
    $gone = Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'inactive', 'last_working_day' => null]);

    app(UpdateEmployee::class)->handle($gone, ['device_user_id' => '201'], userWithRole('admin'), syncAccount: false);

    expect(DevicePunch::query()->where('device_user_id', '201')->value('employee_id'))->toBeNull()
        ->and(ewRow($gone, '2026-11-24'))->toBeNull();
});

it('lets HR give a leaver\'s machine user ID to the new hire: punches link from the new hire\'s joining date', function () {
    $hr = userWithRole('hr');
    $gone = Employee::factory()->create(['joining_date' => '2025-01-01', 'status' => 'inactive', 'last_working_day' => null, 'device_user_id' => '301']);
    $hire = Employee::factory()->create(['joining_date' => '2026-11-20', 'status' => 'probation']);

    app(PunchIngestor::class)->ingest($this->device, ewLine('301', '2026-11-18 09:00:00').ewLine('301', '2026-11-24 09:02:00')
        .ewLine('301', '2026-11-24 18:05:00'), null);
    expect(DevicePunch::query()->where('device_user_id', '301')->whereNotNull('employee_id')->count())->toBe(0);

    // The ID still belongs to the leaver: Raw Punches says so (and why).
    $this->actingAs($hr)->post('/attendance/punches/assign', ['device_user_id' => '301', 'employee_id' => $hire->id])
        ->assertSessionHasErrors(['device_user_id' => "This machine user ID still belongs to {$gone->full_name}, who has left without a last working day. Clear it on their employee form first."]);

    app(UpdateEmployee::class)->handle($gone, ['device_user_id' => null], $hr, syncAccount: false);
    $this->actingAs($hr)->post('/attendance/punches/assign', ['device_user_id' => '301', 'employee_id' => $hire->id])
        ->assertSessionHasNoErrors();

    expect(DevicePunch::query()->where('employee_id', $hire->id)->pluck('punch_date')->unique()->values()->all())->toBe(['2026-11-24'])
        ->and(DevicePunch::query()->whereNull('employee_id')->where('device_user_id', '301')->value('punch_date'))->toBe('2026-11-18');

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();
    expect(ewRow($hire, '2026-11-24')->status)->toBe('present')
        ->and(Attendance::query()->where('employee_id', $gone->id)->count())->toBe(0);
});

it('keeps no automatic day from today on for a leaver without a last working day, and leaves closed history alone', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'active']);
    punch($e, '2026-11-24 09:00:00');
    punch($e, '2026-11-24 18:00:00');
    punch($e, '2026-11-25 09:00:00');
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-24');
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-25');
    expect(ewRow($e, '2026-11-25')->status)->toBe('present');

    $this->actingAs(userWithRole('hr'))->patch("/employees/{$e->id}/status", ['status' => 'inactive'])->assertSessionHasNoErrors();
    expect(ewRow($e, '2026-11-25')->needs_rebuild)->toBeTrue();

    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    expect(ewRow($e, '2026-11-25'))->toBeNull()
        ->and(ewRow($e, '2026-11-24')->only(['status', 'closed']))->toBe(['status' => 'present', 'closed' => true]);

    // A later rebuild of today (a punch stored before the change, a manual mark) does not bring it back.
    app(AttendanceRebuilder::class)->rebuild($e->fresh(), '2026-11-25');
    expect(ewRow($e, '2026-11-25'))->toBeNull();
});

it('removes automatic days after a newly set last working day on the rebuild, keeping manual days', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'active']);
    foreach (['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23', '2026-11-24'] as $day) {
        app(AttendanceRebuilder::class)->closeDay($day);
    }
    $manual = ewRow($e, '2026-11-23');
    $manual->forceFill(['is_manual' => true, 'status' => 'present', 'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 18:00:00'])->save();
    expect(ewRow($e, '2026-11-24')->status)->toBe('absent');

    $this->actingAs(userWithRole('hr'))
        ->patch("/employees/{$e->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-11-20'])
        ->assertSessionHasNoErrors();
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    $dates = Attendance::query()->where('employee_id', $e->id)->orderBy('date')->get()->map(fn ($a) => $a->getRawOriginal('date'))->all();
    expect($dates)->toBe(['2026-11-20', '2026-11-23'])
        ->and(ewRow($e, '2026-11-23')->is_manual)->toBeTrue();
});

it('re-marks the days before a later joining date with one update', function () {
    $e = Employee::factory()->create(['joining_date' => '2026-11-02', 'status' => 'active']);
    foreach (['2026-11-16', '2026-11-17', '2026-11-18'] as $day) {
        app(AttendanceRebuilder::class)->closeDay($day);
    }

    $e->forceFill(['joining_date' => '2026-11-18'])->save();
    expect(RebuildMarks::outsideEmployment($e->fresh()))->toBe(2);

    app(AttendanceRebuilder::class)->rebuildPending();
    expect(Attendance::query()->where('employee_id', $e->id)->count())->toBe(1);
});
