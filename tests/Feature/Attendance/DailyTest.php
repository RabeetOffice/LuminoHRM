<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| The default shift is 09:00-18:00, 15 minutes grace, 4 h half day, Saturday and Sunday off. "Now" is 2026-11-24
| (a Tuesday) 14:00 in Karachi, so the open day is 2026-11-24 and 2026-11-23 (Monday) is closed. Attendance started on
| 2026-01-01. The cases are kept unambiguous (on time, clearly late, clearly absent), not tap-timing edges.
*/

beforeEach(function () {
    $this->withoutVite(); // the page is not in the build manifest until the coordinator builds
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function dlEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function dlRow(Employee $e, string $date, array $attrs = []): Attendance
{
    return Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'closed' => $date < '2026-11-24', ...$attrs]);
}

function dlFind(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

/** @param  array<string, mixed>  $overrides */
function dlPayload(array $overrides = []): array
{
    return ['clock_in' => '09:00', 'clock_out' => '18:00', 'status' => null, 'reason' => 'Forgot to punch out', ...$overrides];
}

/** The ids of the people listed on the page. */
function dlIds($response): array
{
    return collect($response->inertiaProps('rows'))->pluck('employee.id')->sort()->values()->all();
}

function dlManagerWithTeam(): array
{
    $manager = userWithRole('manager');
    $me = employeeFor($manager, ['joining_date' => '2026-01-01']);
    $report = dlEmployee(['manager_id' => $me->id]);
    $other = dlEmployee();

    return [$manager, $me, $report, $other];
}

// ---------------------------------------------------------------- access and scope

it('shows admin and hr everyone, today by default', function (string $role) {
    $a = dlEmployee();
    $b = dlEmployee();

    $response = $this->actingAs(userWithRole($role))->get('/attendance/daily')->assertOk()
        ->assertInertia(fn ($p) => $p->component('attendance/daily')->where('date', '2026-11-24')->where('isOpen', true)
            ->where('can.edit', true));

    expect(dlIds($response))->toContain($a->id, $b->id);
})->with(['admin', 'hr']);

it('shows a manager their own team only, without the edit action', function () {
    [$manager, $me, $report, $other] = dlManagerWithTeam();
    dlRow($other, '2026-11-24');

    $response = $this->actingAs($manager)->get('/attendance/daily')->assertOk()
        ->assertInertia(fn ($p) => $p->where('can.edit', false));

    expect(dlIds($response))->toBe(collect([$me->id, $report->id])->sort()->values()->all())
        ->and(json_encode($response->inertiaProps()))->not->toContain($other->employee_code);
});

it('forbids a plain employee and sends guests to sign in', function () {
    $user = userWithRole('employee');
    employeeFor($user);

    $this->actingAs($user)->get('/attendance/daily')->assertForbidden();
    auth()->logout();
    $this->get('/attendance/daily')->assertRedirect('/login');
});

it('refuses a future date and an impossible one', function () {
    $user = userWithRole('hr');

    $this->actingAs($user)->get('/attendance/daily?date=2026-11-25')
        ->assertRedirect('/attendance/daily')->assertSessionHas('error');
    $this->actingAs($user)->get('/attendance/daily?date=2026-02-30')
        ->assertRedirect('/attendance/daily')->assertSessionHas('error');
    $this->actingAs($user)->get('/attendance/daily?date=2026-11-23')->assertOk()
        ->assertInertia(fn ($p) => $p->where('date', '2026-11-23')->where('isOpen', false)->where('nextDate', '2026-11-24'));
});

// ---------------------------------------------------------------- who is listed and how

it('lists people who should have a record: not in yet today, pending rows, never before joining or after leaving', function () {
    $in = dlEmployee();
    dlRow($in, '2026-11-24');
    $notIn = dlEmployee();
    $pending = dlEmployee();
    dlRow($pending, '2026-11-24', ['status' => null, 'clock_in' => '2026-11-24 09:00:00', 'clock_out' => null, 'worked_minutes' => 0]);
    $future = dlEmployee(['joining_date' => '2026-12-01']);
    $gone = dlEmployee(['status' => 'resigned', 'last_working_day' => '2026-11-20']);
    $leftNoDate = dlEmployee(['status' => 'inactive']);

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily')->assertOk();
    $rows = collect($response->inertiaProps('rows'))->keyBy('employee.id');

    expect($rows->keys()->sort()->values()->all())->toBe(collect([$in->id, $notIn->id, $pending->id])->sort()->values()->all())
        ->and($rows[$notIn->id]['not_in'])->toBeTrue()
        ->and($rows[$notIn->id]['status'])->toBeNull()
        ->and($rows[$pending->id]['not_in'])->toBeFalse()
        ->and($rows[$pending->id]['status'])->toBeNull()
        ->and($rows[$in->id]['clock_in'])->toBe('09:00')
        ->and($rows[$in->id]['clock_out'])->toBe('18:00')
        ->and($rows[$in->id]['shift'])->toBe('General');

    expect($response->inertiaProps('summary'))->toMatchArray([
        'total' => 3, 'present' => 1, 'pending' => 1, 'not_in' => 1, 'absent' => 0,
    ]);
    unset($future, $gone, $leftNoDate);
});

it('lists nobody without a row while attendance has not started', function () {
    Settings::set('attendance', ['start_date' => null]);
    $punched = dlEmployee();
    dlRow($punched, '2026-11-24');
    dlEmployee();

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily')->assertOk();

    expect(dlIds($response))->toBe([$punched->id]);
});

it('counts the summary within the viewer scope only', function () {
    [$manager, $me, $report, $other] = dlManagerWithTeam();
    dlRow($me, '2026-11-23', ['status' => 'late', 'late_minutes' => 30]);
    dlRow($report, '2026-11-23', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    dlRow($other, '2026-11-23', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    dlRow(dlEmployee(), '2026-11-23', ['status' => 'present']);

    $this->actingAs($manager)->get('/attendance/daily?date=2026-11-23')->assertOk()
        ->assertInertia(fn ($p) => $p->where('summary.total', 2)->where('summary.late', 1)->where('summary.absent', 1)
            ->where('summary.present', 0));

    $this->actingAs(userWithRole('hr'))->get('/attendance/daily?date=2026-11-23')->assertOk()
        ->assertInertia(fn ($p) => $p->where('summary.absent', 2)->where('summary.present', 1)->where('summary.late', 1));
});

it('filters by status, department, search and needs attention (whatever the status)', function () {
    $late = dlEmployee(['first_name' => 'Zara', 'last_name' => 'Late']);
    dlRow($late, '2026-11-23', ['status' => 'late', 'late_minutes' => 40, 'needs_attention' => true]);
    $present = dlEmployee(['first_name' => 'Omar', 'last_name' => 'Ontime']);
    dlRow($present, '2026-11-23', ['needs_attention' => true]);
    $absent = dlEmployee(['first_name' => 'Ali', 'last_name' => 'Away']);
    dlRow($absent, '2026-11-23', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    $hr = userWithRole('hr');

    $get = fn (string $query) => dlIds($this->actingAs($hr)->get('/attendance/daily?date=2026-11-23&'.$query)->assertOk());

    expect($get('status=late'))->toBe([$late->id])
        ->and($get('attention=1'))->toBe(collect([$late->id, $present->id])->sort()->values()->all())
        ->and($get('search=away'))->toBe([$absent->id])
        ->and($get('department='.$present->department_id))->toBe([$present->id]);

    $this->actingAs($hr)->get('/attendance/daily?date=2026-11-23&status=late')->assertInertia(
        fn ($p) => $p->where('summary.needs_attention', 2)->where('summary.total', 3)
    );
});

it('reads the day with a fixed number of queries, whatever the number of people', function () {
    $hr = userWithRole('hr');
    $count = function () use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get('/attendance/daily?date=2026-11-23')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    dlRow(dlEmployee(['shift_id' => shiftFor()->id]), '2026-11-23');
    dlRow(dlEmployee(), '2026-11-23');
    $count(); // warm caches (settings, permissions)
    $few = $count();

    foreach (range(1, 12) as $i) {
        $e = dlEmployee(['shift_id' => $i % 2 === 0 ? shiftFor()->id : null]);
        if ($i % 3 !== 0) {
            dlRow($e, '2026-11-23');
        }
    }
    $many = $count();

    expect($many)->toBe($few)->and($many)->toBeLessThanOrEqual(20);
});

// ---------------------------------------------------------------- manual correction

it('recalculates a corrected closed day from the entered times and audits old and new values', function () {
    $e = dlEmployee();
    $row = dlRow($e, '2026-11-23', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0,
        'needs_attention' => true, 'needs_rebuild' => true]);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '09:40', 'clock_out' => '18:00']))
        ->assertSessionHasNoErrors()->assertRedirect();

    $fresh = dlFind($e, '2026-11-23');
    expect($fresh->id)->toBe($row->id)
        ->and($fresh->status)->toBe('late')
        ->and($fresh->late_minutes)->toBe(40)
        ->and($fresh->worked_minutes)->toBe(500)
        ->and($fresh->clock_in->format('Y-m-d H:i:s'))->toBe('2026-11-23 09:40:00')
        ->and($fresh->is_manual)->toBeTrue()
        ->and($fresh->closed)->toBeTrue()
        ->and($fresh->needs_rebuild)->toBeFalse()
        ->and($fresh->needs_attention)->toBeFalse()
        ->and($fresh->shift_rules['start_time'])->toBe('09:00')
        ->and($fresh->shift_rules['grace_minutes'])->toBe(15);

    $entry = Activity::query()->where('log_name', 'attendance')->where('description', 'Attendance corrected')->sole();
    expect($entry->causer_id)->toBe($hr->id)
        ->and($entry->subject_id)->toBe($e->id)
        ->and($entry->properties['date'])->toBe('2026-11-23')
        ->and($entry->properties['reason'])->toBe('Forgot to punch out')
        ->and($entry->properties['old']['status'])->toBe('absent')
        ->and($entry->properties['old']['clock_in'])->toBeNull()
        ->and($entry->properties['attributes']['status'])->toBe('late')
        ->and($entry->properties['attributes']['clock_in'])->toBe('2026-11-23 09:40')
        ->and($entry->properties['attributes']['is_manual'])->toBeTrue();
});

it('creates the row when there is none, and leaves an open day open', function () {
    $e = dlEmployee();

    $this->actingAs(userWithRole('admin'))->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_out' => null]))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-24');
    expect($row->status)->toBe('present')
        ->and($row->is_manual)->toBeTrue()
        ->and($row->closed)->toBeFalse()
        ->and($row->clock_out)->toBeNull();
});

it('decides with the row shift-rule snapshot when it has one', function () {
    $e = dlEmployee();
    dlRow($e, '2026-11-23', ['shift_rules' => [
        'shift_id' => null, 'name' => 'Old late shift', 'start_time' => '10:00', 'end_time' => '19:00',
        'grace_minutes' => 15, 'half_day_hours' => 4.0, 'weekly_offs' => ['saturday', 'sunday'],
    ]]);

    $this->actingAs(userWithRole('hr'))->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '10:05', 'clock_out' => '19:00']))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-23');
    expect($row->status)->toBe('present')->and($row->late_minutes)->toBe(0)->and($row->shift_rules['name'])->toBe('Old late shift');
});

it('stores a status override as given, with the times', function () {
    $e = dlEmployee();
    dlRow($e, '2026-11-23');

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '09:50', 'clock_out' => '18:00', 'status' => 'present']))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-23');
    expect($row->status)->toBe('present')
        ->and($row->late_minutes)->toBe(0)
        ->and($row->worked_minutes)->toBe(490)
        ->and($row->clock_in->format('H:i'))->toBe('09:50')
        ->and($row->is_manual)->toBeTrue();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'absent']))
        ->assertSessionHasNoErrors();
    expect(dlFind($e, '2026-11-23')->status)->toBe('absent')->and(dlFind($e, '2026-11-23')->clock_in)->toBeNull();
});

it('requires a reason of 5 to 255 characters and refuses on_leave and bad times', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');
    $url = "/attendance/daily/{$e->id}/2026-11-23";

    $this->actingAs($hr)->put($url, dlPayload(['reason' => '']))->assertSessionHasErrors('reason');
    $this->actingAs($hr)->put($url, dlPayload(['reason' => 'abc']))->assertSessionHasErrors('reason');
    $this->actingAs($hr)->put($url, dlPayload(['reason' => str_repeat('a', 256)]))->assertSessionHasErrors('reason');
    $this->actingAs($hr)->put($url, dlPayload(['status' => 'on_leave']))->assertSessionHasErrors('status');
    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '9am']))->assertSessionHasErrors('clock_in');
    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => null, 'clock_out' => null]))->assertSessionHasErrors('clock_in');

    expect(dlFind($e, '2026-11-23'))->toBeNull()
        ->and(Activity::query()->where('description', 'Attendance corrected')->count())->toBe(0);
});

it('refuses a clock-out before the clock-in unless it is after midnight before the day cutoff', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');
    $url = "/attendance/daily/{$e->id}/2026-11-23";

    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '09:00', 'clock_out' => '08:00']))->assertSessionHasErrors('clock_out');
    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '02:00', 'clock_out' => '10:00']))->assertSessionHasErrors('clock_in');
    expect(dlFind($e, '2026-11-23'))->toBeNull();

    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '09:00', 'clock_out' => '01:30']))->assertSessionHasNoErrors();
    $row = dlFind($e, '2026-11-23');
    expect($row->clock_out->format('Y-m-d H:i:s'))->toBe('2026-11-24 01:30:00')
        ->and($row->worked_minutes)->toBe(990);

    $response = $this->actingAs($hr)->get('/attendance/daily?date=2026-11-23');
    $listed = collect($response->inertiaProps('rows'))->firstWhere('employee.id', $e->id);
    expect($listed['clock_out'])->toBe('01:30')->and($listed['clock_out_next_day'])->toBeTrue();
});

it('refuses a future date and a day outside the employment window', function () {
    $e = dlEmployee(['joining_date' => '2026-11-20']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-25", dlPayload())->assertForbidden();
    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-19", dlPayload())->assertSessionHasErrors('date');
    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/2026-11-25")->assertForbidden();

    expect(Attendance::count())->toBe(0);
});

it('forbids corrections to people without attendance.edit and outside the editor scope', function () {
    [$manager, $me, $report, $other] = dlManagerWithTeam();
    dlRow($report, '2026-11-23', ['is_manual' => true, 'status' => 'absent']);

    $this->actingAs($manager)->put("/attendance/daily/{$report->id}/2026-11-23", dlPayload())->assertForbidden();
    $this->actingAs($manager)->delete("/attendance/daily/{$report->id}/2026-11-23")->assertForbidden();

    $employee = userWithRole('employee');
    $own = employeeFor($employee, ['joining_date' => '2026-01-01']);
    $this->actingAs($employee)->put("/attendance/daily/{$own->id}/2026-11-23", dlPayload())->assertForbidden();

    // A team-scoped editor may not reach someone outside the team by changing the id in the URL.
    $manager->givePermissionTo('attendance.edit');
    $this->actingAs($manager)->put("/attendance/daily/{$other->id}/2026-11-23", dlPayload())->assertForbidden();
    $this->actingAs($manager)->delete("/attendance/daily/{$other->id}/2026-11-23")->assertForbidden();
    $this->actingAs($manager)->put("/attendance/daily/{$report->id}/2026-11-23", dlPayload())->assertSessionHasNoErrors();

    expect(dlFind($other, '2026-11-23'))->toBeNull()
        ->and(dlFind($report, '2026-11-23')->status)->toBe('present');
});

// ---------------------------------------------------------------- reset

it('removes a correction and shows the punch-based values at once', function () {
    $e = dlEmployee();
    punch($e, '2026-11-23 09:00:00');
    punch($e, '2026-11-23 18:00:00');
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-23');
    expect(dlFind($e, '2026-11-23')->status)->toBe('present');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'absent']))
        ->assertSessionHasNoErrors();
    expect(dlFind($e, '2026-11-23')->status)->toBe('absent');

    // A rebuild leaves the manual day alone.
    app(AttendanceRebuilder::class)->rebuild($e, '2026-11-23');
    expect(dlFind($e, '2026-11-23')->status)->toBe('absent');

    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/2026-11-23")->assertRedirect()->assertSessionHas('success');

    $row = dlFind($e, '2026-11-23');
    expect($row->is_manual)->toBeFalse()
        ->and($row->status)->toBe('present')
        ->and($row->clock_in->format('H:i'))->toBe('09:00')
        ->and($row->clock_out->format('H:i'))->toBe('18:00')
        ->and($row->needs_rebuild)->toBeFalse();

    $entry = Activity::query()->where('description', 'Attendance correction removed')->sole();
    expect($entry->properties['old']['status'])->toBe('absent')
        ->and($entry->properties['old']['is_manual'])->toBeTrue()
        ->and($entry->properties['attributes']['is_manual'])->toBeFalse()
        ->and($entry->causer_id)->toBe($hr->id);
});

it('does nothing when the day has no manual correction', function () {
    $e = dlEmployee();
    dlRow($e, '2026-11-23');

    $this->actingAs(userWithRole('hr'))->delete("/attendance/daily/{$e->id}/2026-11-23")->assertSessionHas('info');

    expect(Activity::query()->where('description', 'Attendance correction removed')->count())->toBe(0);
});

it('never sends raw date objects to the page', function () {
    $e = dlEmployee();
    dlRow($e, '2026-11-23', ['is_manual' => true, 'note' => 'Checked']);

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily?date=2026-11-23');
    $row = collect($response->inertiaProps('rows'))->firstWhere('employee.id', $e->id);

    expect($row['is_manual'])->toBeTrue()
        ->and($response->inertiaProps('previousDate'))->toBe('2026-11-22')
        ->and(json_encode($response->inertiaProps()))->not->toContain('T00:00:00');
});
