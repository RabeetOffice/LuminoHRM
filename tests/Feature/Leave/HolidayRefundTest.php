<?php

use App\Actions\Leave\HolidayLeaveRefund;
use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| A holiday added on (or moved onto) approved leave (Task 8, plan open decision 14). "Now" is Wednesday 2026-11-25 14:00
| in Karachi; 2026-11-23 is a Monday. The default shift has Saturday and Sunday off. approvedLeave() writes one leave
| day per calendar date.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function hlrPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** @return list<string> */
function hlrDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

it('refunds an approved leave day when a holiday is added on it, re-marks attendance, mails and audits', function () {
    [$user, $employee] = hlrPerson();
    $hr = userWithRole('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-24');
    app(AttendanceRebuilder::class)->rebuild($employee, '2026-11-23');
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->status)->toBe('on_leave');

    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Snow Day', 'date' => '2026-11-23'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Holiday added. 1 approved leave day on that date went back to its balance; the employee was told.');

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and($r->days)->toBe(1.0)
        ->and($r->cancelled_from)->toBeNull()
        ->and(hlrDates($r))->toBe(['2026-11-24'])
        ->and(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(1.0);

    $row = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first();
    expect($row->needs_rebuild)->toBeTrue();
    app(AttendanceRebuilder::class)->rebuildPending();
    expect($row->fresh()->status)->toBe('holiday');

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->description)->toBe('Leave day refunded (holiday)')
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->subject_id)->toBe($employee->id)
        ->and($entry->properties['date'])->toBe('2026-11-23')
        ->and($entry->properties['holiday'])->toBe('Snow Day')
        ->and($entry->properties['refunded'])->toEqual(1)
        ->and($entry->properties['days'])->toEqual(1);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'refunded'
        && $m->note === 'Snow Day on Mon 23 Nov 2026' && $m->request->is($r));
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('cancels a one-day request whose only day becomes a holiday', function () {
    [, $employee] = hlrPerson();
    $r = approvedLeave($employee, '2026-12-01');

    $this->actingAs(userWithRole('admin'))->post('/leave/holidays', ['name' => 'Founders Day', 'date' => '2026-12-01'])->assertSessionHasNoErrors();

    $r->refresh();
    expect($r->status)->toBe('cancelled')
        ->and($r->days)->toBe(0.0)
        ->and($r->cancel_reason)->toBe('Every day became a holiday.')
        ->and($r->cancelled_at)->not->toBeNull()
        ->and(hlrDates($r))->toBe([]);
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->properties['cancelled'])->toBeTrue();
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->outcome === 'refunded');
});

it('refunds a half day and leaves other people and other dates alone', function () {
    [, $a] = hlrPerson();
    [, $b] = hlrPerson();
    $half = approvedLeave($a, '2026-12-03', null, 'am');
    $other = approvedLeave($b, '2026-12-04');

    $this->actingAs(userWithRole('hr'))->post('/leave/holidays', ['name' => 'Eid', 'date' => '2026-12-03'])->assertSessionHasNoErrors();

    expect($half->fresh()->status)->toBe('cancelled')
        ->and(hlrDates($other->fresh()))->toBe(['2026-12-04'])
        ->and($other->fresh()->days)->toBe(1.0);
    expect(Activity::where('log_name', 'leave')->count())->toBe(1);
});

it('refunds when a holiday is moved onto a leave day, and gives nothing back on the date it left', function () {
    [, $employee] = hlrPerson();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-07']);
    // Approved around the holiday: 7 Dec was never counted.
    $r = approvedLeave($employee, '2026-12-08', '2026-12-09');
    $r->forceFill(['from_date' => '2026-12-07'])->save();
    $hr = userWithRole('hr');

    // Only the name changes: nothing happens to leave.
    $this->actingAs($hr)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-12-07'])
        ->assertSessionHas('success', 'Holiday updated.');
    expect(hlrDates($r->fresh()))->toBe(['2026-12-08', '2026-12-09']);

    $this->actingAs($hr)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-12-08'])
        ->assertSessionHasNoErrors();

    $r->refresh();
    expect(hlrDates($r))->toBe(['2026-12-09'])->and($r->days)->toBe(1.0)->and($r->status)->toBe('approved');
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('adds nothing back when a holiday is deleted', function () {
    [, $employee] = hlrPerson();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    $r = approvedLeave($employee, '2026-12-07');
    $r->forceFill(['to_date' => '2026-12-09'])->save();
    LeaveDay::query()->create(['leave_request_id' => $r->id, 'employee_id' => $employee->id, 'leave_type_id' => $r->leave_type_id,
        'date' => '2026-12-09', 'amount' => 1, 'half' => 'full']);

    $this->actingAs(userWithRole('admin'))->delete("/leave/holidays/{$holiday->id}")->assertSessionHas('success');

    expect(hlrDates($r->fresh()))->toBe(['2026-12-07', '2026-12-09'])
        ->and(Activity::where('log_name', 'leave')->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('recounts open requests covering the new holiday and holds a request of only holidays at zero days', function () {
    [, $employee] = hlrPerson();
    $week = leaveRequest($employee, ['from_date' => '2026-12-07', 'to_date' => '2026-12-11', 'days' => 5]);
    $single = leaveRequest($employee, ['from_date' => '2026-12-14', 'to_date' => '2026-12-14', 'days' => 1, 'status' => 'manager_approved']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Day A', 'date' => '2026-12-09'])->assertSessionHasNoErrors();
    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Day B', 'date' => '2026-12-14'])->assertSessionHasNoErrors();

    expect($week->fresh()->days)->toBe(4.0)->and($week->fresh()->status)->toBe('pending')
        ->and($single->fresh()->days)->toBe(0.0)->and($single->fresh()->status)->toBe('manager_approved');

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $week->id)->sole();
    expect($entry->description)->toBe('Leave days recounted (holiday)')
        ->and($entry->properties['old'])->toEqual(['days' => 5])
        ->and($entry->properties['attributes'])->toEqual(['days' => 4]);
    Mail::assertNothingQueued();
});

it('is idempotent and touches nobody without leave on the date', function () {
    [, $employee] = hlrPerson();
    $r = approvedLeave($employee, '2026-12-01', '2026-12-02');
    Holiday::factory()->create(['name' => 'X', 'date' => '2026-12-01']);
    $hr = userWithRole('hr');

    $first = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $second = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $nobody = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-20', 'Y', $hr));

    expect($first)->toHaveCount(1)->and($second)->toBe([])->and($nobody)->toBe([])
        ->and(hlrDates($r->fresh()))->toBe(['2026-12-02'])
        ->and(Activity::where('log_name', 'leave')->count())->toBe(1);
});

it('finds the affected people in a fixed number of queries, whoever else has leave', function () {
    $hr = userWithRole('hr');
    Holiday::factory()->create(['name' => 'X', 'date' => '2026-12-01']);

    foreach (range(1, 6) as $i) {
        approvedLeave(Employee::factory()->create(['joining_date' => '2026-01-01']), '2026-12-02', '2026-12-04');
    }

    DB::enableQueryLog();
    DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(3);
});

it('lists the approved requests covering each holiday in the index props, for the delete confirmation', function () {
    [, $a] = hlrPerson('employee', ['first_name' => 'Zara', 'last_name' => 'Khan']);
    [, $b] = hlrPerson('employee', ['first_name' => 'Ali', 'last_name' => 'Raza']);
    Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    Holiday::factory()->create(['name' => 'Quiet', 'date' => '2026-12-25']);
    $ra = approvedLeave($a, '2026-12-07');
    $ra->forceFill(['to_date' => '2026-12-09'])->save();
    $rb = approvedLeave($b, '2026-12-08');
    leaveRequest($a, ['from_date' => '2026-12-08', 'to_date' => '2026-12-08']); // pending: not listed
    $cancelled = approvedLeave($b, '2026-12-07');
    $cancelled->forceFill(['status' => 'cancelled', 'to_date' => '2026-12-09'])->save();

    $this->actingAs(userWithRole('hr'))->get('/leave/holidays?year=2026')
        ->assertInertia(fn ($p) => $p
            ->where('holidays.0.name', 'Eid')
            ->where('holidays.0.affected_leave', [
                ['request_id' => $rb->id, 'employee' => 'Ali Raza'],
                ['request_id' => $ra->id, 'employee' => 'Zara Khan'],
            ])
            ->where('holidays.1.affected_leave', []));
});

it('keeps the affected list to people the user may see', function () {
    [, $employee] = hlrPerson();
    Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    approvedLeave($employee, '2026-12-08');

    // An HR user without employees.view-all (permission removed for this test) sees only their own record.
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $hr->roles->first()->revokePermissionTo('employees.view-all');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($hr->fresh())->get('/leave/holidays?year=2026')
        ->assertInertia(fn ($p) => $p->where('holidays.0.affected_leave', []));
});
