<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
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

// ---------------------------------------------------------------- fix round: open day decided as complete (C1)

it('decides an open-day correction with a clock-out as a complete day', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_in' => '09:00', 'clock_out' => '12:30']))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-24');
    expect($row->status)->toBe('half_day')
        ->and($row->early_minutes)->toBe(330)
        ->and($row->worked_minutes)->toBe(210)
        ->and($row->is_manual)->toBeTrue()
        ->and($row->closed)->toBeFalse();
});

it('refuses a clock-out (or clock-in) later than now on the open day', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');
    $url = "/attendance/daily/{$e->id}/2026-11-24";

    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '09:00', 'clock_out' => '15:00']))->assertSessionHasErrors('clock_out');
    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '14:30', 'clock_out' => null]))->assertSessionHasErrors('clock_in');
    expect(dlFind($e, '2026-11-24'))->toBeNull();

    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => '09:00', 'clock_out' => '13:59']))->assertSessionHasNoErrors();
});

it('finalises an open-day clock-in-only correction at the nightly close with its stored snapshot', function () {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_in' => '09:40', 'clock_out' => null]))
        ->assertSessionHasNoErrors();
    expect(dlFind($e, '2026-11-24')->only(['status', 'late_minutes', 'closed', 'needs_attention']))
        ->toBe(['status' => 'late', 'late_minutes' => 40, 'closed' => false, 'needs_attention' => false]);

    // The shift changes afterwards: the close still uses the rules stored with the correction.
    shiftFor($e, ['start_time' => '10:00', 'end_time' => '19:00']);
    punch($e, '2026-11-24 08:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-11-25 04:30:00', 'Asia/Karachi'));
    app(AttendanceRebuilder::class)->closeDay('2026-11-24');

    $row = dlFind($e, '2026-11-24');
    expect($row->is_manual)->toBeTrue()
        ->and($row->closed)->toBeTrue()
        ->and($row->status)->toBe('late')
        ->and($row->late_minutes)->toBe(40)
        ->and($row->needs_attention)->toBeTrue() // a closed day with only a clock-in
        ->and($row->clock_in->format('Y-m-d H:i:s'))->toBe('2026-11-24 09:40:00') // never replaced by the punch
        ->and($row->clock_out)->toBeNull();
});

it('takes entered times literally: a short day is not a machine double tap', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');

    // A closed day: 09:00-09:40 entered by hand is 40 minutes worked, a half day.
    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '09:00', 'clock_out' => '09:40']))
        ->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-23')->only(['status', 'worked_minutes', 'early_minutes', 'needs_attention', 'clock_out']))
        ->toMatchArray(['status' => 'half_day', 'worked_minutes' => 40, 'early_minutes' => 500, 'needs_attention' => false])
        ->and(dlFind($e, '2026-11-23')->clock_out->format('H:i'))->toBe('09:40');

    // The open day with a clock-out is decided as a complete day: the same.
    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_in' => '10:00', 'clock_out' => '10:30']))
        ->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-24')->only(['status', 'worked_minutes']))->toBe(['status' => 'half_day', 'worked_minutes' => 30]);
});

it('writes the full rule snapshot on a manual save', function () {
    $e = dlEmployee(['attendance_exempt' => false]);
    Settings::set('attendance', ['overtime_min_minutes' => 30, 'duplicate_punch_minutes' => 3]);

    $this->actingAs(userWithRole('hr'))->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload())->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-23')->shift_rules)->toMatchArray([
        'start_time' => '09:00', 'overtime_min_minutes' => 30, 'duplicate_punch_minutes' => 3, 'attendance_exempt' => false,
    ]);
});

// ---------------------------------------------------------------- fix round: who may correct whom (I1)

it('forbids correcting your own record unless you are an Admin, and privileged logins unless you are an Admin', function () {
    $hr = userWithRole('hr');
    $ownHr = employeeFor($hr, ['joining_date' => '2026-01-01']);
    $admin = userWithRole('admin');
    $ownAdmin = employeeFor($admin, ['joining_date' => '2026-01-01']);
    $otherHr = userWithRole('hr');
    $otherHrEmployee = employeeFor($otherHr, ['joining_date' => '2026-01-01']);
    $plain = dlEmployee();

    foreach ([$ownHr, $ownAdmin, $otherHrEmployee] as $target) {
        $this->actingAs($hr)->put("/attendance/daily/{$target->id}/2026-11-23", dlPayload())->assertForbidden();
        $this->actingAs($hr)->delete("/attendance/daily/{$target->id}/2026-11-23")->assertForbidden();
    }
    expect(Attendance::count())->toBe(0);

    $this->actingAs($hr)->put("/attendance/daily/{$plain->id}/2026-11-23", dlPayload())->assertSessionHasNoErrors();
    foreach ([$ownAdmin, $ownHr, $otherHrEmployee, $plain] as $target) {
        $this->actingAs($admin)->put("/attendance/daily/{$target->id}/2026-11-23", dlPayload())->assertSessionHasNoErrors();
    }
    expect(Attendance::count())->toBe(4);

    $rows = collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-23')->inertiaProps('rows'))->keyBy('employee.id');
    expect($rows[$ownHr->id]['can_edit'])->toBeFalse()
        ->and($rows[$ownAdmin->id]['can_edit'])->toBeFalse()
        ->and($rows[$otherHrEmployee->id]['can_edit'])->toBeFalse()
        ->and($rows[$plain->id]['can_edit'])->toBeTrue();

    $rows = collect($this->actingAs($admin)->get('/attendance/daily?date=2026-11-23')->inertiaProps('rows'))->keyBy('employee.id');
    expect($rows->pluck('can_edit')->unique()->values()->all())->toBe([true]);
});

// ---------------------------------------------------------------- fix round: overrides (I2, I3)

it('applies each override to the status only, with minutes from the times', function (string $status, ?string $in, ?string $out, array $expected) {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => $in, 'clock_out' => $out, 'status' => $status]))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-23');
    expect($row->only(['status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'status_overridden']))
        ->toBe([...$expected, 'status_overridden' => true]);
})->with([
    'present hides late' => ['present', '09:50', '18:00', ['status' => 'present', 'worked_minutes' => 490, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0]],
    'present on a short day' => ['present', '09:00', '13:00', ['status' => 'present', 'worked_minutes' => 240, 'late_minutes' => 0, 'early_minutes' => 300, 'overtime_minutes' => 0]],
    'late keeps late minutes' => ['late', '09:40', '18:30', ['status' => 'late', 'worked_minutes' => 530, 'late_minutes' => 40, 'early_minutes' => 0, 'overtime_minutes' => 30]],
    'late on a short day' => ['late', '09:40', '12:00', ['status' => 'late', 'worked_minutes' => 140, 'late_minutes' => 40, 'early_minutes' => 360, 'overtime_minutes' => 0]],
    'half day has no late minutes' => ['half_day', '09:40', '18:00', ['status' => 'half_day', 'worked_minutes' => 500, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0]],
    'holiday counts worked as overtime' => ['holiday', '10:00', '10:30', ['status' => 'holiday', 'worked_minutes' => 30, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 30]],
    'weekend counts worked as overtime' => ['weekend', '10:00', '14:00', ['status' => 'weekend', 'worked_minutes' => 240, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 240]],
    'weekend without times' => ['weekend', null, null, ['status' => 'weekend', 'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0]],
    'absent' => ['absent', null, null, ['status' => 'absent', 'worked_minutes' => 0, 'late_minutes' => 0, 'early_minutes' => 0, 'overtime_minutes' => 0]],
]);

it('keeps the overtime minimum for an off-day override', function () {
    Settings::set('attendance', ['overtime_min_minutes' => 300]);
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '10:00', 'clock_out' => '14:00', 'status' => 'weekend']))
        ->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-23')->only(['worked_minutes', 'overtime_minutes']))->toBe(['worked_minutes' => 240, 'overtime_minutes' => 0]);
});

it('refuses times with an absent override', function () {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '09:00', 'clock_out' => '18:00', 'status' => 'absent']))
        ->assertSessionHasErrors('clock_in');

    expect(dlFind($e, '2026-11-23'))->toBeNull();
});

it('sends whether a manual row is an override, and lets an absent override be saved again', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');
    $url = "/attendance/daily/{$e->id}/2026-11-23";
    $listed = fn () => collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-23')->inertiaProps('rows'))
        ->firstWhere('employee.id', $e->id);

    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'absent']))->assertSessionHasNoErrors();
    expect($listed()['status_overridden'])->toBeTrue()->and($listed()['status'])->toBe('absent');

    // Re-opening the dialog posts the same override back.
    $this->actingAs($hr)->put($url, dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'absent', 'reason' => 'Confirmed again']))
        ->assertSessionHasNoErrors();
    expect(Activity::query()->where('description', 'Attendance corrected')->count())->toBe(2);

    // Back to working it out from the times.
    $this->actingAs($hr)->put($url, dlPayload())->assertSessionHasNoErrors();
    expect($listed()['status_overridden'])->toBeFalse()->and($listed()['status'])->toBe('present');
});

it('only marks an override row closed at the nightly close', function () {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_in' => '09:00', 'clock_out' => null, 'status' => 'half_day']))
        ->assertSessionHasNoErrors();
    $before = dlFind($e, '2026-11-24')->only(['status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'needs_attention']);

    $this->travelTo(CarbonImmutable::parse('2026-11-25 04:30:00', 'Asia/Karachi'));
    app(AttendanceRebuilder::class)->closeDay('2026-11-24');

    $row = dlFind($e, '2026-11-24');
    expect($row->closed)->toBeTrue()->and($row->is_manual)->toBeTrue()->and($row->status_overridden)->toBeTrue()
        ->and($row->only(array_keys($before)))->toBe($before);
});

// ---------------------------------------------------------------- fix round: employment window (spec deviations, M10)

it('refuses corrections before the attendance start date, for update and reset, and marks such days not editable', function () {
    Settings::set('attendance', ['start_date' => '2026-11-20']);
    $e = dlEmployee();
    dlRow($e, '2026-11-19', ['is_manual' => true]);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-19", dlPayload())->assertSessionHasErrors('date');
    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/2026-11-19")->assertSessionHasErrors('date');
    expect(dlFind($e, '2026-11-19')->is_manual)->toBeTrue();

    $row = collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-19')->inertiaProps('rows'))->firstWhere('employee.id', $e->id);
    expect($row['can_edit'])->toBeFalse();

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-20", dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'weekend']))
        ->assertSessionHasNoErrors();
});

it('refuses corrections for people who left without a last working day', function (string $status) {
    $e = dlEmployee(['status' => $status]);
    dlRow($e, '2026-11-23');
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload())->assertSessionHasErrors('date');

    $row = collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-23')->inertiaProps('rows'))->firstWhere('employee.id', $e->id);
    expect($row['can_edit'])->toBeFalse();
})->with(['inactive', 'resigned', 'terminated']);

it('answers 404 for a malformed date in the URL', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-13-45", dlPayload())->assertNotFound();
    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-02-30", dlPayload())->assertNotFound();
    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/24-11-2026")->assertNotFound();
});

// ---------------------------------------------------------------- fix round: notes, audit, race (M1, M4, M5)

it('keeps a correction-request note when a later correction gives none, and audits the note', function () {
    $e = dlEmployee();
    $hr = userWithRole('hr');

    app(ApplyManualCorrection::class)->handle($e, '2026-11-23', '09:00', '18:00', null, 'Approved request', $hr, 'Correction request #12');
    $entry = Activity::query()->where('description', 'Attendance corrected')->sole();
    expect($entry->properties['note'])->toBe('Correction request #12');

    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => '09:05']))->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-23')->note)->toBe('Correction request #12');
});

it('audits the values before the reset and the rebuilt values after it', function () {
    $e = dlEmployee();
    punch($e, '2026-11-23 09:00:00');
    punch($e, '2026-11-23 18:00:00');
    $hr = userWithRole('hr');
    $this->actingAs($hr)->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => null, 'clock_out' => null, 'status' => 'absent']));

    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/2026-11-23")->assertSessionHas('success');

    $entry = Activity::query()->where('description', 'Attendance correction removed')->sole();
    expect($entry->properties['old']['status'])->toBe('absent')
        ->and($entry->properties['attributes']['status'])->toBe('present')
        ->and($entry->properties['attributes']['clock_in'])->toBe('2026-11-23 09:00')
        ->and($entry->properties['attributes']['is_manual'])->toBeFalse()
        ->and(dlFind($e, '2026-11-23')->status_overridden)->toBeFalse();
});

it('resets a day with no punches: absent once attendance has started, removed while it has not', function (?string $start, ?string $expected) {
    Settings::set('attendance', ['start_date' => $start]);
    $e = dlEmployee();
    $hr = userWithRole('hr');
    dlRow($e, '2026-11-23', ['is_manual' => true, 'status_overridden' => true, 'status' => 'present']);

    $this->actingAs($hr)->delete("/attendance/daily/{$e->id}/2026-11-23")->assertSessionHas('success');

    expect(dlFind($e, '2026-11-23')?->status)->toBe($expected);
    $entry = Activity::query()->where('description', 'Attendance correction removed')->sole();
    expect($entry->properties['old']['status'])->toBe('present')
        ->and($entry->properties['attributes']['status'])->toBe($expected)
        ->and($entry->properties['attributes']['is_manual'])->toBeFalse();
})->with([
    'start date set' => ['2026-01-01', 'absent'],
    'no start date' => [null, null],
]);

it('retries once when the row is created by someone else at the same moment', function () {
    $e = dlEmployee();
    $raced = false;

    Attendance::creating(function (Attendance $a) use (&$raced, $e): void {
        if (! $raced && $a->employee_id === $e->id) {
            $raced = true;
            DB::table('attendances')->insert([
                'employee_id' => $e->id, 'date' => '2026-11-23', 'status' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $this->actingAs(userWithRole('hr'))->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload())->assertSessionHasNoErrors();

    expect($raced)->toBeTrue()
        ->and(Attendance::query()->where('employee_id', $e->id)->count())->toBe(1)
        ->and(dlFind($e, '2026-11-23')->status)->toBe('present');
});

// ---------------------------------------------------------------- fix round: list (M2, M3, M9)

it('matches people without a record on a past day with the not-in / no-record filter', function () {
    $missing = dlEmployee();
    dlRow(dlEmployee(), '2026-11-23');

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily?date=2026-11-23&status=not_in')->assertOk();

    expect(dlIds($response))->toBe([$missing->id])
        ->and($response->inertiaProps('summary.not_in'))->toBe(1);
});

it('shows people without a row as Weekend or Holiday on an open off day, not as not in yet', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-28 14:00:00', 'Asia/Karachi')); // Saturday
    $e = dlEmployee();
    $worksSaturday = dlEmployee();
    shiftFor($worksSaturday, ['weekly_offs' => ['friday']]);
    $hr = userWithRole('hr');

    $response = $this->actingAs($hr)->get('/attendance/daily')->assertOk();
    $rows = collect($response->inertiaProps('rows'))->keyBy('employee.id');

    expect($rows[$e->id]['status'])->toBe('weekend')
        ->and($rows[$e->id]['not_in'])->toBeFalse()
        ->and($rows[$worksSaturday->id]['not_in'])->toBeTrue()
        ->and($response->inertiaProps('summary'))->toMatchArray(['not_in' => 1, 'holiday_weekend' => 1]);

    Holiday::query()->create(['name' => 'Founders Day', 'date' => '2026-11-28']);
    $response = $this->actingAs($hr)->get('/attendance/daily')->assertOk();
    $rows = collect($response->inertiaProps('rows'))->keyBy('employee.id');

    expect($rows[$worksSaturday->id]['status'])->toBe('holiday')
        ->and($response->inertiaProps('summary'))->toMatchArray(['not_in' => 0, 'holiday_weekend' => 2]);
});

it('lists only the departments of people in a manager scope, and filters within the scope', function () {
    [$manager, $me, $report, $other] = dlManagerWithTeam();
    dlRow($report, '2026-11-23', ['status' => 'late', 'late_minutes' => 30]);
    dlRow($other, '2026-11-23', ['status' => 'late', 'late_minutes' => 30]);

    $response = $this->actingAs($manager)->get('/attendance/daily?date=2026-11-23&status=late')->assertOk();

    expect(collect($response->inertiaProps('departments'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$me->department_id, $report->department_id])->unique()->sort()->values()->all())
        ->and(dlIds($response))->toBe([$report->id]);

    $this->actingAs($manager)->get('/attendance/daily?date=2026-11-23&department='.$other->department_id)->assertOk()
        ->assertInertia(fn ($p) => $p->where('rows', []));
});

// ---------------------------------------------------------------- a single entered time keeps its role (I-1)

it('keeps a single entered time in the role it was entered as', function (?string $in, ?string $out, array $expected) {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-23", dlPayload(['clock_in' => $in, 'clock_out' => $out]))
        ->assertSessionHasNoErrors();

    $row = dlFind($e, '2026-11-23');
    expect([$row->getRawOriginal('clock_in'), $row->getRawOriginal('clock_out'), $row->status, $row->late_minutes, $row->early_minutes, $row->needs_attention])
        ->toBe($expected);
})->with([
    'clock-in 14:00 only: late 300, flagged' => ['14:00', null, ['2026-11-23 14:00:00', null, 'late', 300, 0, true]],
    'clock-out 12:00 only: never late' => [null, '12:00', [null, '2026-11-23 12:00:00', 'present', 0, 0, true]],
]);

it('keeps a clock-in entered alone on the open day as the clock-in', function () {
    $e = dlEmployee();

    $this->actingAs(userWithRole('hr'))
        ->put("/attendance/daily/{$e->id}/2026-11-24", dlPayload(['clock_in' => '13:45', 'clock_out' => null]))
        ->assertSessionHasNoErrors();

    expect(dlFind($e, '2026-11-24')->only(['status', 'late_minutes', 'needs_attention', 'closed']))
        ->toBe(['status' => 'late', 'late_minutes' => 285, 'needs_attention' => false, 'closed' => false]);
});

it('does not count an exempt person with no row on the open day as not in yet', function () {
    $exempt = dlEmployee(['attendance_exempt' => true]);
    $missing = dlEmployee();

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily')->assertOk();
    $rows = collect($response->inertiaProps('rows'));

    expect($rows->firstWhere('employee.id', $exempt->id))->toMatchArray(['not_in' => false, 'exempt' => true, 'has_record' => false, 'status' => null])
        ->and($rows->firstWhere('employee.id', $missing->id))->toMatchArray(['not_in' => true, 'exempt' => false])
        ->and($response->inertiaProps('summary')['not_in'])->toBe(1);

    // On a past day without a row (the close has not run) both are "No record".
    $past = collect($this->actingAs(userWithRole('hr'))->get('/attendance/daily?date=2026-11-23')->inertiaProps('rows'));
    expect($past->firstWhere('employee.id', $exempt->id)['not_in'])->toBeTrue();
});

it('marks rows before the attendance start date and offers no edit on them', function () {
    Settings::set('attendance', ['start_date' => '2026-11-20']);
    $e = dlEmployee();
    dlRow($e, '2026-11-19', ['status' => 'present', 'clock_in' => '2026-11-19 09:00:00', 'clock_out' => '2026-11-19 18:00:00']);
    dlRow($e, '2026-11-20', ['status' => 'present']);
    $hr = userWithRole('hr');

    $before = collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-19')->inertiaProps('rows'))->firstWhere('employee.id', $e->id);
    $after = collect($this->actingAs($hr)->get('/attendance/daily?date=2026-11-20')->inertiaProps('rows'))->firstWhere('employee.id', $e->id);

    expect($before)->toMatchArray(['before_start' => true, 'can_edit' => false, 'status' => 'present'])
        ->and($after)->toMatchArray(['before_start' => false, 'can_edit' => true]);
});
