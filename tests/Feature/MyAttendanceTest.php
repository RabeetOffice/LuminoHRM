<?php

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;

/*
| "Now" is 2026-11-24 (a Tuesday) 14:00 in Karachi: the attendance day is 2026-11-24 (open), the current month
| 2026-11. Attendance started on 2026-01-01 unless a test says otherwise.
*/

beforeEach(function () {
    $this->withoutVite(); // the page is not in the build manifest until the coordinator builds
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function maRow(Employee $e, string $date, array $attrs = []): Attendance
{
    return Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'closed' => $date < '2026-11-24', ...$attrs]);
}

/** @return array{0: User, 1: Employee} */
function maMe(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** The cell of a day number (1-based) as the page received it. */
function maCell($response, int $day): ?array
{
    return $response->inertiaProps('cells')[$day - 1];
}

// ---------------------------------------------------------------- access and own data only

it('shows every role their own month, the current one by default', function (string $role) {
    [$user, $me] = maMe($role);
    maRow($me, '2026-11-23');

    $response = $this->actingAs($user)->get('/my/attendance')->assertOk()
        ->assertInertia(fn ($p) => $p->component('my/attendance')
            ->where('month.value', '2026-11')
            ->where('month.label', 'November 2026')
            ->where('month.next', null)
            ->where('month.previous', '2026-10')
            ->where('today', '2026-11-24')
            ->where('employee.code', $me->employee_code)
            ->has('days', 30)
            ->has('cells', 30));

    expect(maCell($response, 23)[0])->toBe('P')
        ->and(maCell($response, 23)[2])->toBe('09:00')
        ->and(maCell($response, 23)[3])->toBe('18:00');
})->with(['admin', 'hr', 'manager', 'employee']);

it('never shows anyone else, whatever is put in the URL', function () {
    [$user, $me] = maMe();
    $other = Employee::factory()->create(['joining_date' => '2026-01-01', 'manager_id' => $me->id]);
    maRow($other, '2026-11-20', ['status' => 'late', 'late_minutes' => 33]);
    maRow($me, '2026-11-19');

    $response = $this->actingAs($user)
        ->get("/my/attendance?employee={$other->id}&employee_id={$other->id}&id={$other->id}")->assertOk();

    expect(maCell($response, 20))->toBeNull()
        ->and(maCell($response, 19)[0])->toBe('P')
        ->and(json_encode($response->inertiaProps()))->not->toContain($other->employee_code)
        ->and(json_encode($response->inertiaProps()))->not->toContain((string) $other->first_name);
});

it('gives a user with no employee record a friendly empty page, not an error or someone else', function () {
    $user = userWithRole('admin');
    $someone = Employee::factory()->create(['joining_date' => '2026-01-01']);
    maRow($someone, '2026-11-23');

    $this->actingAs($user)->get('/my/attendance')->assertOk()
        ->assertInertia(fn ($p) => $p->component('my/attendance')
            ->where('employee', null)
            ->where('cells', [])
            ->where('totals.present', 0)
            ->where('correctable', []));
});

it('sends guests to sign in', function () {
    $this->get('/my/attendance')->assertRedirect('/login');
});

// ---------------------------------------------------------------- month parameter

it('refuses a malformed month and a month that has not started', function (string $month) {
    [$user] = maMe();

    $this->actingAs($user)->get("/my/attendance?month={$month}")
        ->assertRedirect('/my/attendance')->assertSessionHas('error');
})->with(['2026-12', '2027-01', '2026-13', 'nonsense', '1999-12', '2026-1']);

it('shows an earlier month, and a month before joining as empty', function () {
    [$user, $me] = maMe('employee', ['joining_date' => '2026-10-15']);
    maRow($me, '2026-10-20');
    // A stray row before joining is never shown or counted.
    maRow($me, '2026-10-10');
    maRow($me, '2026-09-10');

    $october = $this->actingAs($user)->get('/my/attendance?month=2026-10')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.next', '2026-11')->where('window', [15, 31])->has('cells', 31));

    expect(maCell($october, 20)[0])->toBe('P')
        ->and(maCell($october, 10))->toBeNull()
        ->and($october->inertiaProps('totals.present'))->toBe(1);

    $this->actingAs($user)->get('/my/attendance?month=2026-09')->assertOk()
        ->assertInertia(fn ($p) => $p->where('window', null)->where('totals.present', 0)->where('correctable', []));
});

it('stops paging back at the first month the person can have records: max(joining date, attendance start date)', function () {
    // Joined before attendance started: the start date's month is the first.
    [$user] = maMe('employee', ['joining_date' => '2025-06-10']);
    Settings::set('attendance', ['start_date' => '2026-09-15']);

    $this->actingAs($user)->get('/my/attendance?month=2026-10')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.previous', '2026-09'));
    $this->actingAs($user)->get('/my/attendance?month=2026-09')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.previous', null));
    // An earlier month typed into the URL is still shown empty, with no way further back.
    $this->actingAs($user)->get('/my/attendance?month=2026-03')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.previous', null)->where('window', null)->where('month.next', '2026-04'));

    // Joined after attendance started: the joining month is the first.
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    [$late] = maMe('employee', ['joining_date' => '2026-11-03']);
    $this->actingAs($late)->get('/my/attendance')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.value', '2026-11')->where('month.previous', null));

    // Attendance not started at all: the joining month.
    Settings::set('attendance', ['start_date' => null]);
    $this->actingAs($user)->get('/my/attendance?month=2025-06')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.previous', null));
});

it('gives a user with no employee record no earlier months to page through', function () {
    $this->actingAs(userWithRole('admin'))->get('/my/attendance')->assertOk()
        ->assertInertia(fn ($p) => $p->where('month.previous', null));
});

it('handles February of a leap year and a 31-day month', function () {
    [$user] = maMe('employee', ['joining_date' => '2020-01-01']);
    Settings::set('attendance', ['start_date' => '2020-01-01']);

    $this->actingAs($user)->get('/my/attendance?month=2024-02')->assertOk()
        ->assertInertia(fn ($p) => $p->has('days', 29)->has('cells', 29));
    $this->actingAs($user)->get('/my/attendance?month=2026-01')->assertOk()
        ->assertInertia(fn ($p) => $p->has('days', 31));
});

// ---------------------------------------------------------------- summary and blanks

it('counts the summary from decided days only: half day 0.5, pending never, blanks outside the window', function () {
    [$user, $me] = maMe('employee', ['joining_date' => '2026-11-03', 'last_working_day' => '2026-11-27']);
    Settings::set('attendance', ['start_date' => '2026-11-04']);
    maRow($me, '2026-11-03'); // before the attendance start date: blank, not counted
    maRow($me, '2026-11-04', ['status' => 'present', 'worked_minutes' => 540]);
    maRow($me, '2026-11-05', ['status' => 'late', 'late_minutes' => 20, 'worked_minutes' => 520]);
    maRow($me, '2026-11-06', ['status' => 'half_day', 'worked_minutes' => 200]);
    maRow($me, '2026-11-09', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    maRow($me, '2026-11-10', ['status' => null, 'clock_out' => null, 'worked_minutes' => 0]); // pending
    maRow($me, '2026-11-07', ['status' => 'weekend', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    maRow($me, '2026-11-24', ['status' => 'late', 'late_minutes' => 10, 'clock_out' => null, 'worked_minutes' => 0]); // today, open

    $response = $this->actingAs($user)->get('/my/attendance')->assertOk();
    $totals = $response->inertiaProps('totals');

    expect($totals)->toMatchArray([
        'present' => 1, 'late' => 2, 'half_day' => 1, 'absent' => 1, 'off' => 1,
        'worked_minutes' => 540 + 520 + 200, 'late_minutes' => 30,
        'equivalent' => 3.5, 'working_days' => 5,
    ])
        ->and($response->inertiaProps('window'))->toBe([4, 24])
        ->and(maCell($response, 3))->toBeNull()
        ->and(maCell($response, 10)[0])->toBe('-')
        ->and(maCell($response, 25))->toBeNull(); // future
});

it('shows an empty month when attendance has no rows yet (fresh install)', function () {
    [$user] = maMe();
    Settings::set('attendance', ['start_date' => null]);

    $this->actingAs($user)->get('/my/attendance')->assertOk()
        ->assertInertia(fn ($p) => $p->where('totals.present', 0)->where('totals.absent', 0)->has('cells', 30));
});

// ---------------------------------------------------------------- request correction

it('offers a correction (CorrectionEligibility) on past days within 31 days: not corrected by hand, no open request', function () {
    [$user, $me] = maMe();
    maRow($me, '2026-11-20', ['is_manual' => true]);
    maRow($me, '2026-11-21');
    AttendanceCorrection::factory()->create(['employee_id' => $me->id, 'date' => '2026-11-19', 'status' => 'pending']);
    AttendanceCorrection::factory()->create(['employee_id' => $me->id, 'date' => '2026-11-18', 'status' => 'rejected']);

    $november = $this->actingAs($user)->get('/my/attendance')->assertOk()->inertiaProps('correctable');

    expect($november)->toContain('2026-11-21', '2026-11-23', '2026-11-01')
        ->toContain('2026-11-18')       // a rejected request may be asked again
        ->not->toContain('2026-11-20')   // manual
        ->not->toContain('2026-11-19')   // open request
        ->not->toContain('2026-11-24')   // today, still open
        ->not->toContain('2026-11-25');  // future

    $october = $this->actingAs($user)->get('/my/attendance?month=2026-10')->assertOk()->inertiaProps('correctable');

    // 31 days back from 2026-11-24 is 2026-10-24.
    expect($october)->toContain('2026-10-24', '2026-10-31')->not->toContain('2026-10-23');
});

it('never sends raw dates or someone else in any prop', function () {
    [$user, $me] = maMe();
    maRow($me, '2026-11-23');

    $props = $this->actingAs($user)->get('/my/attendance')->assertOk()->inertiaProps();
    $json = json_encode(array_diff_key($props, array_flip(['auth', 'app', 'flash', 'errors', 'name', 'quote', 'sidebarOpen'])));

    expect($json)->not->toMatch('/\d{4}-\d{2}-\d{2}T\d{2}:/');
});
