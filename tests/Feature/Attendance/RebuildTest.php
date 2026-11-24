<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/*
| The default shift ("General", from the settings) is 09:00-18:00, 15 minutes grace, 4 h half day, Saturday and Sunday
| off; overtime is recorded from 30 minutes. 2026-11-23 is a Monday, 2026-11-24 a Tuesday. "Now" is 2026-11-24 14:00
| in Karachi unless a test says otherwise.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
});

function rbRebuilder(): AttendanceRebuilder
{
    return app(AttendanceRebuilder::class);
}

/** @param  array<string, mixed>  $attrs */
function rbEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

function rbRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->where('date', $date)->first();
}

/** The stored row exactly as the database holds it. */
function rbRaw(int $id): array
{
    return (array) DB::table('attendances')->where('id', $id)->first();
}

/** Marks the day for rebuild as punch ingestion does (creating a bare row when there is none). */
function rbMark(Employee $e, string $date): void
{
    $row = rbRow($e, $date);

    if ($row === null) {
        Attendance::query()->create(['employee_id' => $e->id, 'date' => $date, 'status' => 'absent', 'needs_rebuild' => true]);
    } else {
        DB::table('attendances')->where('id', $row->id)->update(['needs_rebuild' => true]);
    }
}

it('writes the decided day for one employee-day', function () {
    $e = rbEmployee();
    punch($e, '2026-11-23 09:20:00');
    punch($e, '2026-11-23 18:40:00');

    rbRebuilder()->rebuild($e, '2026-11-23');

    $row = rbRow($e, '2026-11-23');
    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('late')
        ->and($row->getRawOriginal('clock_in'))->toBe('2026-11-23 09:20:00')
        ->and($row->getRawOriginal('clock_out'))->toBe('2026-11-23 18:40:00')
        ->and($row->worked_minutes)->toBe(560)
        ->and($row->late_minutes)->toBe(20)
        ->and($row->early_minutes)->toBe(0)
        ->and($row->overtime_minutes)->toBe(40)
        ->and($row->shift_id)->toBe(Shift::default()->id)
        ->and($row->needs_rebuild)->toBeFalse()
        ->and($row->closed)->toBeTrue() // before today
        ->and($row->is_manual)->toBeFalse();
});

it('keeps today open: a single punch is not flagged and the row is not closed', function () {
    $e = rbEmployee();
    punch($e, '2026-11-24 09:05:00');

    rbRebuilder()->rebuild($e, '2026-11-24');

    $row = rbRow($e, '2026-11-24');
    expect($row->status)->toBe('present')
        ->and($row->clock_out)->toBeNull()
        ->and($row->needs_attention)->toBeFalse()
        ->and($row->closed)->toBeFalse();
});

it('flags a single punch on a past day', function () {
    $e = rbEmployee();
    punch($e, '2026-11-23 09:05:00');

    rbRebuilder()->rebuild($e, '2026-11-23');

    expect(rbRow($e, '2026-11-23')->needs_attention)->toBeTrue();
});

it('records nothing for today without punches, and removes a row whose punches went away', function () {
    $e = rbEmployee();

    rbRebuilder()->rebuild($e, '2026-11-24');
    expect(rbRow($e, '2026-11-24'))->toBeNull();

    // A bare row from ingestion whose punch was later re-linked to someone else.
    rbMark($e, '2026-11-24');
    expect(rbRebuilder()->rebuildPending())->toBe(1);
    expect(rbRow($e, '2026-11-24'))->toBeNull();
});

it('records absent for a past working day without punches', function () {
    $e = rbEmployee();

    rbRebuilder()->rebuild($e, '2026-11-23');

    expect(rbRow($e, '2026-11-23')->status)->toBe('absent');
});

it('keeps a manual row byte for byte, even when it is marked for rebuild', function () {
    $e = rbEmployee();
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'status' => 'present', 'is_manual' => true,
        'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 17:00:00', 'worked_minutes' => 480,
        'note' => 'Corrected by HR', 'closed' => false,
    ]);
    punch($e, '2026-11-23 11:00:00');
    punch($e, '2026-11-23 12:00:00');
    DB::table('attendances')->where('id', $manual->id)->update(['needs_rebuild' => true]);
    $before = rbRaw($manual->id);

    $this->travel(5)->minutes();
    rbRebuilder()->rebuild($e, '2026-11-23');
    rbRebuilder()->rebuildPending();
    rbRebuilder()->rebuildRange('2026-11-23', '2026-11-23');
    rbRebuilder()->closeDay('2026-11-23');
    $this->artisan('hrm:attendance-rebuild')->assertSuccessful();

    expect(rbRaw($manual->id))->toBe($before);
});

it('leaves manual pending rows out of the pending batch, so the batch always finishes', function () {
    $e = rbEmployee();
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-20', 'is_manual' => true, 'needs_rebuild' => true]);

    expect(rbRebuilder()->rebuildPending())->toBe(0);
});

it('rebuilds a late punch for a day that was already closed', function () {
    $e = rbEmployee();
    rbRebuilder()->closeDay('2026-11-23');
    expect(rbRow($e, '2026-11-23')->status)->toBe('absent');

    punch($e, '2026-11-23 09:10:00');
    punch($e, '2026-11-23 18:00:00');
    rbMark($e, '2026-11-23');

    expect(rbRebuilder()->rebuildPending())->toBe(1);

    $row = rbRow($e, '2026-11-23');
    expect($row->status)->toBe('present')
        ->and($row->worked_minutes)->toBe(530)
        ->and($row->closed)->toBeTrue()
        ->and($row->needs_rebuild)->toBeFalse();
});

it('processes pending rows oldest first, up to the limit', function () {
    $e = rbEmployee();

    foreach (['2026-11-20', '2026-11-18', '2026-11-19'] as $date) {
        punch($e, "{$date} 09:00:00");
        rbMark($e, $date);
    }

    expect(rbRebuilder()->rebuildPending(2))->toBe(2);
    expect(rbRow($e, '2026-11-18')->needs_rebuild)->toBeFalse()
        ->and(rbRow($e, '2026-11-19')->needs_rebuild)->toBeFalse()
        ->and(rbRow($e, '2026-11-20')->needs_rebuild)->toBeTrue();

    expect(rbRebuilder()->rebuildPending(2))->toBe(1)
        ->and(rbRebuilder()->rebuildPending(2))->toBe(0);
});

it('produces no rows before the joining date for someone who joined mid-month', function () {
    $e = rbEmployee(['joining_date' => '2026-11-16']);

    rbRebuilder()->rebuildRange('2026-11-01', '2026-11-23');

    $dates = Attendance::query()->where('employee_id', $e->id)->orderBy('date')->get()->map(fn ($a) => $a->getRawOriginal('date'))->all();
    expect($dates)->toBe(['2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23']);
});

it('removes a non-manual row before the joining date or after the last working day, never a manual one', function () {
    $e = rbEmployee(['joining_date' => '2026-11-10', 'last_working_day' => '2026-11-20', 'status' => 'resigned']);
    $early = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-09']);
    $late = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-21']);
    $manual = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-23', 'is_manual' => true]);

    rbRebuilder()->rebuild($e, '2026-11-09');
    rbRebuilder()->rebuild($e, '2026-11-21');
    rbRebuilder()->rebuild($e, '2026-11-23');

    expect(Attendance::query()->find($early->id))->toBeNull()
        ->and(Attendance::query()->find($late->id))->toBeNull()
        ->and(Attendance::query()->find($manual->id))->not->toBeNull();

    // Inside the window the resigned employee is still decided normally.
    rbRebuilder()->rebuild($e, '2026-11-20');
    expect(rbRow($e, '2026-11-20')->status)->toBe('absent');
});

it('creates no punch-less day for someone who left without a last working day, but records their punches', function () {
    $e = rbEmployee(['status' => 'terminated']);

    rbRebuilder()->rebuild($e, '2026-11-23');
    expect(rbRow($e, '2026-11-23'))->toBeNull();

    punch($e, '2026-11-20 09:00:00');
    rbRebuilder()->rebuild($e, '2026-11-20');
    expect(rbRow($e, '2026-11-20')->status)->toBe('present');
});

it('applies holidays, weekly offs and the exempt flag', function () {
    $e = rbEmployee();
    $exempt = rbEmployee(['attendance_exempt' => true]);
    Holiday::factory()->create(['date' => '2026-11-20']);
    punch($e, '2026-11-20 10:00:00');
    punch($e, '2026-11-20 13:00:00');
    punch($e, '2026-11-21 10:00:00');
    punch($e, '2026-11-21 11:00:00');
    punch($exempt, '2026-11-19 12:00:00');

    foreach (['2026-11-19', '2026-11-20', '2026-11-21'] as $date) {
        rbRebuilder()->rebuild($e, $date);
        rbRebuilder()->rebuild($exempt, $date);
    }

    expect(rbRow($e, '2026-11-20')->only(['status', 'worked_minutes', 'overtime_minutes']))
        ->toBe(['status' => 'holiday', 'worked_minutes' => 180, 'overtime_minutes' => 180]);
    expect(rbRow($e, '2026-11-21')->only(['status', 'worked_minutes', 'overtime_minutes']))
        ->toBe(['status' => 'weekend', 'worked_minutes' => 60, 'overtime_minutes' => 60]);
    expect(rbRow($exempt, '2026-11-19')->only(['status', 'late_minutes', 'needs_attention']))
        ->toBe(['status' => 'present', 'late_minutes' => 0, 'needs_attention' => false]);
    expect(rbRow($exempt, '2026-11-20')->status)->toBe('holiday')
        ->and(rbRow($exempt, '2026-11-21')->status)->toBe('weekend');
});

it('uses the employee\'s own shift', function () {
    $e = rbEmployee();
    $late = shiftFor($e, ['name' => 'Late', 'start_time' => '11:00', 'end_time' => '20:00', 'grace_minutes' => 0, 'weekly_offs' => ['friday']]);
    punch($e, '2026-11-23 11:00:00');
    punch($e, '2026-11-23 20:00:00');

    rbRebuilder()->rebuild($e->fresh(), '2026-11-23');
    rbRebuilder()->rebuild($e->fresh(), '2026-11-20'); // a Friday: this shift's weekly off

    expect(rbRow($e, '2026-11-23')->only(['status', 'shift_id', 'late_minutes']))
        ->toBe(['status' => 'present', 'shift_id' => $late->id, 'late_minutes' => 0])
        ->and(rbRow($e, '2026-11-20')->status)->toBe('weekend');
});

it('changing a shift rebuilds only open and pending days; closed days change only on an explicit rebuild', function () {
    $e = rbEmployee();
    $default = Shift::default();
    punch($e, '2026-11-23 09:30:00');
    punch($e, '2026-11-23 18:00:00');
    rbRebuilder()->closeDay('2026-11-23');
    $closed = rbRow($e, '2026-11-23');
    expect($closed->status)->toBe('late')->and($closed->shift_id)->toBe($default->id);
    $before = rbRaw($closed->id);

    $later = shiftFor($e, ['name' => 'Ten to seven', 'start_time' => '10:00', 'end_time' => '19:00', 'grace_minutes' => 0]);

    // Nightly jobs do not touch the closed day.
    rbRebuilder()->rebuildPending();
    rbRebuilder()->closeDay('2026-11-23');
    expect(rbRaw($closed->id))->toBe($before);

    // A late punch for that closed day: still decided with the shift it was closed with.
    punch($e, '2026-11-23 12:00:00');
    rbMark($e, '2026-11-23');
    rbRebuilder()->rebuildPending();
    expect(rbRow($e, '2026-11-23')->only(['status', 'shift_id', 'late_minutes']))
        ->toBe(['status' => 'late', 'shift_id' => $default->id, 'late_minutes' => 30]);

    // Today (open) is decided with the new shift.
    punch($e, '2026-11-24 09:55:00');
    rbMark($e, '2026-11-24');
    rbRebuilder()->rebuildPending();
    expect(rbRow($e, '2026-11-24')->only(['status', 'shift_id']))->toBe(['status' => 'present', 'shift_id' => $later->id]);

    // An explicit rebuild applies the new shift to the closed day.
    $this->artisan('hrm:attendance-rebuild', ['--from' => '2026-11-23', '--to' => '2026-11-23', '--employee' => [$e->employee_code]])
        ->assertSuccessful();

    expect(rbRow($e, '2026-11-23')->only(['status', 'shift_id', 'late_minutes', 'early_minutes', 'closed']))
        ->toBe(['status' => 'present', 'shift_id' => $later->id, 'late_minutes' => 0, 'early_minutes' => 60, 'closed' => true]);
});

it('limits an explicit rebuild to the employees named', function () {
    $a = rbEmployee();
    $b = rbEmployee();

    $this->artisan('hrm:attendance-rebuild', ['--from' => '2026-11-23', '--employee' => [(string) $a->id]])->assertSuccessful();

    expect(rbRow($a, '2026-11-23'))->not->toBeNull()
        ->and(rbRow($b, '2026-11-23'))->toBeNull();
});

it('rejects a bad explicit rebuild', function (array $options) {
    rbEmployee(['employee_code' => 'LD-9999']);

    $this->artisan('hrm:attendance-rebuild', $options)->assertFailed();
})->with([
    'to without from' => [['--to' => '2026-11-23']],
    'employee without from' => [['--employee' => ['LD-9999']]],
    'not a date' => [['--from' => '23/11/2026']],
    'backwards' => [['--from' => '2026-11-23', '--to' => '2026-11-01']],
    'after today' => [['--from' => '2026-11-24', '--to' => '2026-11-25']],
    'too long' => [['--from' => '2025-01-01', '--to' => '2026-11-23']],
    'unknown employee' => [['--from' => '2026-11-23', '--employee' => ['LD-0000X']]],
]);

it('rebuilds pending days from the command', function () {
    $e = rbEmployee();
    punch($e, '2026-11-24 09:00:00');
    rbMark($e, '2026-11-24');

    $this->artisan('hrm:attendance-rebuild')->expectsOutput('attendance_rebuild pending=1')->assertSuccessful();

    expect(rbRow($e, '2026-11-24')->status)->toBe('present');
});

it('schedules the rebuild every minute without overlapping and the close hourly', function () {
    $events = collect(app(Schedule::class)->events());

    $rebuild = $events->first(fn ($e) => str_contains($e->command, 'hrm:attendance-rebuild'));
    $close = $events->first(fn ($e) => str_contains($e->command, 'hrm:attendance-close'));

    expect($rebuild->expression)->toBe('* * * * *')
        ->and($rebuild->withoutOverlapping)->toBeTrue()
        ->and($close->expression)->toBe('30 * * * *')
        ->and($close->withoutOverlapping)->toBeTrue();
});
