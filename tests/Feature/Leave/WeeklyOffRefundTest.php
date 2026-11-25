<?php

use App\Actions\Employees\UpdateEmployee;
use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\User;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveLedger;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
| Weekly offs changed after leave was approved (owner ruling, final fix round I1): approved leave days that are now
| weekly offs are refunded automatically from each person's first unclosed attendance day; closed days keep their
| snapshot. "Now" is Wednesday 2026-11-25 14:00 in Karachi; 2026-11-23 and 2026-11-30 are Mondays.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee, 2: Shift} an employee on their own Sat/Sun-off shift */
function woPerson(): array
{
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2026-01-01']);
    $shift = shiftFor($employee, ['name' => 'Office '.$employee->id, 'weekly_offs' => ['saturday', 'sunday']]);

    return [$user, $employee, $shift];
}

/** A closed attendance day decided with Sat/Sun off. */
function woClosed(Employee $e, string $date, string $status = 'on_leave'): Attendance
{
    return Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => $date, 'status' => $status, 'closed' => true, 'needs_rebuild' => false,
        'shift_rules' => ['weekly_offs' => ['saturday', 'sunday'], 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15, 'half_day_hours' => 4],
    ]);
}

function woPayload(Shift $shift, array $offs): array
{
    return [
        'name' => $shift->name, 'start_time' => '09:00', 'end_time' => '18:00', 'grace_minutes' => 15,
        'half_day_hours' => 4, 'weekly_offs' => $offs,
    ];
}

/** @return list<string> */
function woDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

it('refunds approved leave days that become weekly offs on a shift save, from the first unclosed day only', function () {
    [$user, $employee, $shift] = woPerson();
    $closedLeave = approvedLeave($employee, '2026-11-23');          // Monday, closed: keeps its snapshot
    woClosed($employee, '2026-11-23');
    woClosed($employee, '2026-11-24', 'present');
    $future = approvedLeave($employee, '2026-11-30', '2026-12-02'); // Mon–Wed
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put("/attendance/shifts/{$shift->id}", woPayload($shift, ['sunday', 'monday']))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', "Shift updated. Leave refunded for the new weekly offs: {$employee->full_name} · 1 day went back to their balance.");

    expect(woDates($closedLeave->fresh()))->toBe(['2026-11-23'])
        ->and($closedLeave->fresh()->days)->toBe(1.0);

    $future->refresh();
    expect(woDates($future))->toBe(['2026-12-01', '2026-12-02'])
        ->and($future->status)->toBe('approved')
        ->and($future->days)->toBe(2.0)
        ->and(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(3.0);

    $entry = Activity::query()->where('log_name', 'leave')->where('description', 'Leave day refunded (weekly off)')->sole();
    expect($entry->properties['request'])->toBe($future->id)
        ->and($entry->properties['removed'])->toBe(['2026-11-30'])
        ->and($entry->properties['refunded'])->toEqual(1)
        ->and($entry->properties['days'])->toEqual(2)
        ->and($entry->causer_id)->toBe($admin->id)
        ->and($entry->subject_id)->toBe($employee->id);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'refunded'
        && $m->note === 'Your weekly offs changed: Mon 30 Nov 2026 is now a weekly off.');
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('cancels a request whose every day became a weekly off, keeping its days for display', function () {
    [$user, $employee, $shift] = woPerson();
    $r = approvedLeave($employee, '2026-11-30');

    $this->actingAs(userWithRole('hr'))->put("/attendance/shifts/{$shift->id}", woPayload($shift, ['sunday', 'monday']))
        ->assertSessionHasNoErrors();

    $r->refresh();
    expect($r->status)->toBe('cancelled')
        ->and($r->days)->toBe(1.0)
        ->and($r->cancel_reason)->toBe('Every day became a weekly off.')
        ->and(woDates($r))->toBe([]);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->outcome === 'cancelled'
        && $m->note === 'Your weekly offs changed: Mon 30 Nov 2026 is now a weekly off. Every day became a weekly off.');
});

it('stops payroll counting an unpaid leave day that became a weekly off, and re-marks today', function () {
    [, $employee, $shift] = woPerson();
    approvedLeave($employee, '2026-11-25', null, null, 'Unpaid'); // today, not closed
    app(AttendanceRebuilder::class)->rebuild($employee, '2026-11-25');
    expect(LeaveLedger::unpaidDays($employee->id, '2026-11-01', '2026-11-30'))->toBe(1.0);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", woPayload($shift, ['wednesday']))
        ->assertSessionHasNoErrors();

    expect(LeaveLedger::unpaidDays($employee->id, '2026-11-01', '2026-11-30'))->toBe(0.0);
    app(AttendanceRebuilder::class)->rebuildPending();
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-25')->first()->status)->toBe('weekend');
});

it('changes nothing when only the times change, or for people on other shifts', function () {
    [, $employee, $shift] = woPerson();
    [, $other] = woPerson();
    $r = approvedLeave($employee, '2026-11-30');
    $o = approvedLeave($other, '2026-11-30');

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", [...woPayload($shift, ['saturday', 'sunday']), 'start_time' => '08:00'])
        ->assertSessionHas('success', 'Shift updated.');
    expect(woDates($r->fresh()))->toBe(['2026-11-30']);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", woPayload($shift, ['monday']))->assertSessionHasNoErrors();
    expect(woDates($r->fresh()))->toBe([])->and(woDates($o->fresh()))->toBe(['2026-11-30']);
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('refunds when an employee is moved to a shift with other weekly offs, and says so in the save\'s warnings', function () {
    [$user, $employee] = woPerson();
    $r = approvedLeave($employee, '2026-11-30', '2026-12-01');
    $friday = shiftFor(null, ['name' => 'Night', 'weekly_offs' => ['monday']]);
    $admin = userWithRole('admin');

    $update = app(UpdateEmployee::class);
    $update->handle($employee, ['shift_id' => $friday->id], $admin, false);

    expect(woDates($r->fresh()))->toBe(['2026-12-01'])
        ->and($update->warnings())->toBe(["Leave refunded for the new weekly offs: {$employee->full_name} · 1 day went back to their balance."]);
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'refunded');
});

it('refunds people without a shift when another default shift has other weekly offs', function () {
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2026-01-01']); // no own shift: works the default (Sat/Sun)
    Shift::default();
    $r = approvedLeave($employee, '2026-11-30');
    $mondays = shiftFor(null, ['name' => 'Mondays off', 'weekly_offs' => ['monday']]);

    $this->actingAs(userWithRole('admin'))->post("/attendance/shifts/{$mondays->id}/make-default")
        ->assertSessionHas('success', fn (string $m) => str_starts_with($m, 'Mondays off is now the default shift. Leave refunded'));

    expect($r->fresh()->status)->toBe('cancelled');
});

it('lists hand-corrected days among the refunded ones in the flash and the audit entry', function () {
    [, $employee, $shift] = woPerson();
    $r = approvedLeave($employee, '2026-11-25', '2026-11-26');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-25', 'status' => 'on_leave', 'is_manual' => true, 'closed' => false]);

    $this->actingAs(userWithRole('admin'))->put("/attendance/shifts/{$shift->id}", woPayload($shift, ['wednesday']))
        ->assertSessionHas('success', fn (string $m) => str_contains($m, "{$employee->full_name}'s 25 Nov was corrected by hand on the Daily screen and still shows leave — check it."));

    expect(woDates($r->fresh()))->toBe(['2026-11-26']);
    $entry = Activity::query()->where('description', 'Leave day refunded (weekly off)')->sole();
    expect($entry->properties['manual'])->toBe(['2026-11-25']);
});
