<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Models\Attendance;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\User;
use App\Support\Attendance\MonthGrid;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| "Now" is Wednesday 2026-11-25 12:00 in Karachi. Attendance started on 2026-01-01 unless a test says otherwise.
| 2026-11-01 is a Sunday.
*/

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function mgPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2025-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function mgDay(Employee $e, string $date, ?string $status, array $attrs = []): Attendance
{
    return Attendance::factory()->create([
        'employee_id' => $e->id,
        'date' => $date,
        'status' => $status,
        'closed' => true,
        ...$attrs,
    ]);
}

/** Links a new login with $role to an existing employee. */
function mgLogin(Employee $e, string $role): void
{
    $user = User::factory()->create(['employee_id' => $e->id]);
    $user->assignRole($role);
}

/** The page props of GET /attendance/monthly?$query for $user. */
function mgProps($test, $user, string $query = ''): array
{
    $response = $test->actingAs($user)->get('/attendance/monthly'.($query === '' ? '' : '?'.$query));
    $response->assertOk();

    return $response->viewData('page')['props'];
}

/** The grid row of one employee in the props. */
function mgRow(array $props, Employee $e): array
{
    $row = collect($props['employees']['data'])->firstWhere('id', $e->id);
    expect($row)->not->toBeNull();

    return $row;
}

// ---------------------------------------------------------------- access and scope

it('opens for the attendance viewers only', function (string $role, int $status) {
    $user = userWithRole($role);
    employeeFor($user);

    expect($this->actingAs($user)->get('/attendance/monthly')->getStatusCode())->toBe($status);
})->with([
    'admin' => ['admin', 200],
    'hr' => ['hr', 200],
    'manager' => ['manager', 200],
    'employee' => ['employee', 403],
]);

it('sends guests to the sign-in page', function () {
    $this->get('/attendance/monthly')->assertRedirect('/login');
});

it('shows a Manager their own row and their direct reports, and nothing about anyone else', function () {
    $manager = userWithRole('manager');
    $boss = employeeFor($manager);
    $report = mgPerson(['manager_id' => $boss->id, 'first_name' => 'Rita']);
    $stranger = mgPerson(['first_name' => 'Zed', 'last_name' => 'Outsider']);
    mgDay($report, '2026-11-02', 'present');
    mgDay($stranger, '2026-11-02', 'present');
    mgDay($boss, '2026-11-02', 'present');

    $response = $this->actingAs($manager)->get('/attendance/monthly?month=2026-11')->assertOk();
    $props = $response->viewData('page')['props'];

    expect(array_column($props['employees']['data'], 'id'))->toEqualCanonicalizing([$boss->id, $report->id])
        ->and($props['employees']['total'])->toBe(2)
        ->and($props['can'])->toBe(['export' => false, 'edit' => false]);
    expect(json_encode($props))->not->toContain('Outsider');
});

it('never lets a Manager reach people outside their team through search, department, page or rows per page', function () {
    $manager = userWithRole('manager');
    $boss = employeeFor($manager);
    $dept = Department::factory()->create();
    $report = mgPerson(['manager_id' => $boss->id, 'first_name' => 'Rita', 'department_id' => $dept->id,
        'designation_id' => Designation::factory()->create(['department_id' => $dept->id])->id]);
    mgPerson(['first_name' => 'Zed', 'last_name' => 'Outsider', 'department_id' => $dept->id,
        'designation_id' => Designation::factory()->create(['department_id' => $dept->id])->id]);
    for ($i = 1; $i <= 30; $i++) {
        mgPerson(['first_name' => 'Other', 'last_name' => sprintf('Outsider%02d', $i)]);
    }

    $ids = fn (array $props) => array_column($props['employees']['data'], 'id');

    $search = mgProps($this, $manager, 'search=Outsider');
    expect($ids($search))->toBe([])->and($search['employees']['total'])->toBe(0);

    $department = mgProps($this, $manager, 'department_id='.$dept->id);
    expect($ids($department))->toBe([$report->id])->and($department['employees']['total'])->toBe(1);

    $wide = mgProps($this, $manager, 'per_page=50');
    expect($ids($wide))->toEqualCanonicalizing([$boss->id, $report->id])->and($wide['employees']['total'])->toBe(2);

    // A page past the end goes back to the last page in scope, never to someone else's rows.
    $this->actingAs($manager)->get('/attendance/monthly?page=2')->assertRedirect();
    $tiny = mgProps($this, $manager, 'per_page=10&page=1');
    expect($ids($tiny))->toEqualCanonicalizing([$boss->id, $report->id]);

    foreach ([$search, $department, $wide, $tiny] as $props) {
        expect(json_encode($props['employees']))->not->toContain('Outsider');
    }
});

it('renders the attendance/monthly page', function () {
    $admin = userWithRole('admin');

    // component() also checks that the page file exists (inertia.testing.ensure_pages_exist).
    $this->actingAs($admin)->get('/attendance/monthly')->assertOk()
        ->assertInertia(fn ($p) => $p->component('attendance/monthly'));
});

it('falls back to 25 rows for a rows-per-page value it does not offer', function (string $value) {
    $admin = userWithRole('admin');

    expect(mgProps($this, $admin, 'per_page='.$value)['employees']['per_page'])->toBe(25);
})->with(['1000', '7', '0', '-10', 'abc', '25.5']);

it('leaves out a leaver with no last working day in months where they have no attendance row', function (string $status) {
    $admin = userWithRole('admin');
    $active = mgPerson(['last_name' => 'Active']);
    $probation = mgPerson(['last_name' => 'Probation', 'status' => 'probation']);
    $gone = mgPerson(['last_name' => 'Gone', 'status' => $status, 'last_working_day' => null]);
    mgDay($gone, '2026-10-15', 'present');

    $october = array_column(mgProps($this, $admin, 'month=2026-10')['employees']['data'], 'id');
    $november = mgProps($this, $admin, 'month=2026-11');

    expect($october)->toContain($gone->id)->toContain($active->id)
        ->and(array_column($november['employees']['data'], 'id'))->toEqualCanonicalizing([$active->id, $probation->id])
        ->and($november['employees']['total'])->toBe(2);
})->with(['inactive', 'resigned', 'terminated']);

it('flags a clock-out on the next calendar day', function () {
    $admin = userWithRole('admin');
    $e = mgPerson();
    mgDay($e, '2026-11-02', 'present', ['clock_in' => '2026-11-02 21:00:00', 'clock_out' => '2026-11-03 06:00:00',
        'worked_minutes' => 540]);
    mgDay($e, '2026-11-03', 'present', ['clock_in' => '2026-11-03 09:00:00', 'clock_out' => '2026-11-03 18:00:00',
        'worked_minutes' => 540]);

    $cells = mgRow(mgProps($this, $admin, 'month=2026-11'), $e)['cells'];

    expect($cells[1])->toBe(['P', 16, '21:00', '06:00', 540, 0, 0, 0])
        ->and($cells[2][1])->toBe(0);
});

// ---------------------------------------------------------------- who may correct which row

it('says per row whether the viewer may correct it', function () {
    $admin = userWithRole('admin');
    $adminSelf = employeeFor($admin, ['last_name' => 'AdminSelf']);
    $hr = userWithRole('hr');
    $hrSelf = employeeFor($hr, ['last_name' => 'HrSelf']);
    $otherHr = userWithRole('hr');
    $otherHrRow = employeeFor($otherHr, ['last_name' => 'OtherHr']);
    $otherAdmin = userWithRole('admin');
    $otherAdminRow = employeeFor($otherAdmin, ['last_name' => 'OtherAdmin']);
    $managerUser = userWithRole('manager');
    $managerRow = employeeFor($managerUser, ['last_name' => 'ManagerRow']);
    $plain = mgPerson(['last_name' => 'Plain']);

    $canEdit = fn (array $props) => collect($props['employees']['data'])->mapWithKeys(fn ($r) => [$r['id'] => $r['can_edit']])->all();

    $byAdmin = $canEdit(mgProps($this, $admin, 'month=2026-11'));
    expect($byAdmin)->toEqual([
        $adminSelf->id => true, // an Admin may correct their own record
        $hrSelf->id => true,
        $managerRow->id => true,
        $otherAdminRow->id => true,
        $otherHrRow->id => true,
        $plain->id => true,
    ]);

    $byHr = $canEdit(mgProps($this, $hr, 'month=2026-11'));
    expect($byHr)->toEqual([
        $adminSelf->id => false, // privileged login: Admin only
        $hrSelf->id => false,    // own record
        $managerRow->id => true,
        $otherAdminRow->id => false,
        $otherHrRow->id => false,
        $plain->id => true,
    ]);

    $manager = userWithRole('manager');
    $boss = employeeFor($manager);
    mgPerson(['manager_id' => $boss->id]);
    expect(array_unique(array_values($canEdit(mgProps($this, $manager, 'month=2026-11')))))->toBe([false]);
});

it('lets the page offer a correction on exactly the days ApplyManualCorrection accepts', function () {
    $hr = userWithRole('hr');
    Settings::set('attendance', ['start_date' => '2026-11-04']);
    $people = [
        mgPerson(['last_name' => 'Steady']),
        mgPerson(['last_name' => 'Joiner', 'joining_date' => '2026-11-10']),
        mgPerson(['last_name' => 'Leaver', 'status' => 'resigned', 'last_working_day' => '2026-11-12']),
        // Left with no last working day, shown because of a row this month: nothing can be corrected.
        mgPerson(['last_name' => 'Gone', 'status' => 'terminated', 'last_working_day' => null]),
        mgPerson(['last_name' => 'Early', 'joining_date' => '2026-10-01', 'status' => 'probation']),
    ];
    mgDay($people[3], '2026-11-05', 'present');

    $props = mgProps($this, $hr, 'month=2026-11');
    $correct = app(ApplyManualCorrection::class);

    foreach ($people as $person) {
        $row = mgRow($props, $person);
        $person->refresh();

        foreach ($props['days'] as $day) {
            // The page's rule (monthly.tsx): can.edit && row.can_edit && inside the window && not in the future.
            $inWindow = $row['window'] !== null && $day['day'] >= $row['window'][0] && $day['day'] <= $row['window'][1];
            $offered = $props['can']['edit'] && $row['can_edit'] && $inWindow && ! $day['future'];
            $accepted = ! $day['future'] && $correct->employmentRefusal($person, $day['date']) === null;

            expect($offered)->toBe($accepted, "{$person->last_name} on {$day['date']}");
        }
    }

    expect(mgRow($props, $people[3])['can_edit'])->toBeFalse();
});

it('marks a day whose status was chosen by hand, and sends the statuses that may be chosen', function () {
    $admin = userWithRole('admin');
    $e = mgPerson();
    mgDay($e, '2026-11-02', 'absent', ['is_manual' => true, 'status_overridden' => true, 'clock_in' => null,
        'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-03', 'present', ['is_manual' => true]);

    $props = mgProps($this, $admin, 'month=2026-11');
    $cells = mgRow($props, $e)['cells'];

    expect($cells[1][1])->toBe(MonthGrid::FLAG_MANUAL | MonthGrid::FLAG_OVERRIDDEN)
        ->and($cells[2][1])->toBe(MonthGrid::FLAG_MANUAL)
        ->and($props['overrideStatuses'])->toBe(ApplyManualCorrection::OVERRIDE_STATUSES);
});

it('shows Admin everyone employed in the month, in name order', function () {
    $admin = userWithRole('admin');
    $b = mgPerson(['first_name' => 'Bo', 'last_name' => 'Beta']);
    $a = mgPerson(['first_name' => 'Al', 'last_name' => 'Alpha']);
    mgPerson(['last_name' => 'Later', 'joining_date' => '2026-12-01']);
    mgPerson(['last_name' => 'Gone', 'last_working_day' => '2026-10-31', 'status' => 'resigned']);

    $props = mgProps($this, $admin, 'month=2026-11');

    expect(array_column($props['employees']['data'], 'id'))->toBe([$a->id, $b->id])
        ->and($props['can'])->toBe(['export' => true, 'edit' => true]);
});

it('filters by search and department', function () {
    $admin = userWithRole('admin');
    $eng = Department::factory()->create(['name' => 'Engineering']);
    $sales = Department::factory()->create(['name' => 'Sales']);
    $ada = mgPerson(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'department_id' => $eng->id,
        'designation_id' => Designation::factory()->create(['department_id' => $eng->id])->id]);
    $bob = mgPerson(['first_name' => 'Bob', 'last_name' => 'Stone', 'department_id' => $sales->id,
        'designation_id' => Designation::factory()->create(['department_id' => $sales->id])->id]);

    expect(array_column(mgProps($this, $admin, 'search=love')['employees']['data'], 'id'))->toBe([$ada->id])
        ->and(array_column(mgProps($this, $admin, 'department_id='.$sales->id)['employees']['data'], 'id'))->toBe([$bob->id])
        ->and(mgProps($this, $admin, 'department_id=999999')['filters']['department_id'])->toBeNull();
});

it('pages by 25 employees', function () {
    $admin = userWithRole('admin');
    for ($i = 1; $i <= 27; $i++) {
        mgPerson(['last_name' => sprintf('P%02d', $i)]);
    }

    $first = mgProps($this, $admin);
    $second = mgProps($this, $admin, 'page=2');

    expect($first['employees']['data'])->toHaveCount(25)
        ->and($first['employees']['total'])->toBe(27)
        ->and($second['employees']['data'])->toHaveCount(2);
});

// ---------------------------------------------------------------- month

it('defaults to the current month and refuses a month that has not started', function () {
    $admin = userWithRole('admin');

    $props = mgProps($this, $admin);
    expect($props['month'])->toBe([
        'value' => '2026-11', 'label' => 'November 2026', 'previous' => '2026-10', 'next' => null, 'current' => '2026-11',
    ]);

    $this->actingAs($admin)->get('/attendance/monthly?month=2026-12')
        ->assertRedirect('/attendance/monthly?month=2026-11')
        ->assertSessionHas('error');
    expect(mgProps($this, $admin, 'month=nonsense')['month']['value'])->toBe('2026-11')
        ->and(mgProps($this, $admin, 'month[]=2026-01')['month']['value'])->toBe('2026-11')
        ->and(mgProps($this, $admin, 'month=2026-10')['month']['next'])->toBe('2026-11');
});

it('uses the attendance day for the current month (before the day cutoff it is still yesterday)', function () {
    $admin = userWithRole('admin');
    // 02:00 on 1 December, cutoff 04:00: still the attendance day of 30 November.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00:00', 'Asia/Karachi'));

    expect(mgProps($this, $admin)['month']['value'])->toBe('2026-11');
    $this->actingAs($admin)->get('/attendance/monthly?month=2026-12')->assertRedirect();
});

it('has one column per day with the weekday worked out on the server', function (string $month, int $days, string $firstWeekday) {
    $admin = userWithRole('admin');
    mgPerson(['joining_date' => '2020-01-01']);

    $props = mgProps($this, $admin, 'month='.$month);

    expect($props['days'])->toHaveCount($days)
        ->and($props['days'][0]['weekday'])->toBe($firstWeekday)
        ->and($props['days'][$days - 1]['date'])->toBe($month.'-'.$days)
        ->and($props['employees']['data'][0]['cells'])->toHaveCount($days);
})->with([
    'February 2026 (28)' => ['2026-02', 28, 'Sun'],
    'February 2024 (29)' => ['2024-02', 29, 'Thu'],
    'April 2026 (30)' => ['2026-04', 30, 'Wed'],
    'January 2026 (31)' => ['2026-01', 31, 'Thu'],
]);

it('marks future days and names holidays in the header', function () {
    $admin = userWithRole('admin');
    Holiday::factory()->create(['date' => '2026-11-10', 'name' => 'Iqbal Day']);

    $days = mgProps($this, $admin)['days'];

    expect($days[0])->toBe(['day' => 1, 'date' => '2026-11-01', 'weekday' => 'Sun', 'future' => false, 'holiday' => null])
        ->and($days[9]['holiday'])->toBe('Iqbal Day')
        ->and($days[24]['future'])->toBeFalse()
        ->and($days[25]['future'])->toBeTrue();
});

// ---------------------------------------------------------------- cells and totals

it('gives each day its status code, markers and times', function () {
    $admin = userWithRole('admin');
    $e = mgPerson();
    mgDay($e, '2026-11-02', 'present', ['clock_in' => '2026-11-02 08:55:00', 'clock_out' => '2026-11-02 18:40:00',
        'worked_minutes' => 585, 'overtime_minutes' => 40]);
    mgDay($e, '2026-11-03', 'late', ['clock_in' => '2026-11-03 09:20:00', 'clock_out' => '2026-11-03 17:30:00',
        'worked_minutes' => 490, 'late_minutes' => 20, 'early_minutes' => 30]);
    mgDay($e, '2026-11-04', 'half_day', ['clock_in' => '2026-11-04 09:00:00', 'clock_out' => '2026-11-04 12:00:00', 'worked_minutes' => 180]);
    mgDay($e, '2026-11-05', 'absent', ['clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-06', 'on_leave', ['clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-07', 'weekend', ['clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-10', 'holiday', ['clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-11', 'present', ['is_manual' => true, 'needs_attention' => true, 'clock_out' => null, 'worked_minutes' => 0]);
    mgDay($e, '2026-11-12', null, ['clock_in' => '2026-11-12 09:01:00', 'clock_out' => null, 'worked_minutes' => 0, 'closed' => false]);

    $cells = mgRow(mgProps($this, $admin, 'month=2026-11'), $e)['cells'];

    expect($cells[1])->toBe(['P', 4, '08:55', '18:40', 585, 0, 0, 40])
        ->and($cells[2])->toBe(['L', 2, '09:20', '17:30', 490, 20, 30, 0])
        ->and($cells[3][0])->toBe('H')
        ->and($cells[4])->toBe(['A', 0, null, null, 0, 0, 0, 0])
        ->and($cells[5][0])->toBe('V')
        ->and($cells[6][0])->toBe('W')
        ->and($cells[9][0])->toBe('O')
        ->and($cells[10])->toBe(['P', 1 | 8, '09:00', null, 0, 0, 0, 0])
        // Pending calculation: shown as "-", never as present.
        ->and($cells[11][0])->toBe('-')
        // No record.
        ->and($cells[0])->toBeNull()
        ->and($cells[8])->toBeNull();
});

it('totals present-equivalent over working days, never counting pending, leave or days off', function () {
    $admin = userWithRole('admin');
    $e = mgPerson();
    mgDay($e, '2026-11-02', 'present', ['worked_minutes' => 540, 'overtime_minutes' => 30]);
    mgDay($e, '2026-11-03', 'present', ['worked_minutes' => 540]);
    mgDay($e, '2026-11-04', 'late', ['worked_minutes' => 500, 'late_minutes' => 25]);
    mgDay($e, '2026-11-05', 'half_day', ['worked_minutes' => 200]);
    mgDay($e, '2026-11-06', 'absent', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    mgDay($e, '2026-11-07', 'weekend', ['worked_minutes' => 120, 'overtime_minutes' => 120]);
    mgDay($e, '2026-11-08', 'weekend', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    mgDay($e, '2026-11-09', 'on_leave', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    mgDay($e, '2026-11-10', 'holiday', ['worked_minutes' => 0, 'clock_in' => null, 'clock_out' => null]);
    mgDay($e, '2026-11-11', null, ['worked_minutes' => 999, 'late_minutes' => 99]);

    expect(mgRow(mgProps($this, $admin, 'month=2026-11'), $e)['totals'])->toBe([
        'present' => 2, 'late' => 1, 'half_day' => 1, 'absent' => 1, 'on_leave' => 1, 'off' => 3,
        'worked_minutes' => 540 + 540 + 500 + 200 + 120, 'late_minutes' => 25, 'overtime_minutes' => 150,
        'equivalent' => 3.5, 'working_days' => 5,
    ]);
});

it('leaves blank the days before joining, after the last working day, before the attendance start and still to come', function () {
    $admin = userWithRole('admin');
    Settings::set('attendance', ['start_date' => '2026-11-04']);
    $joiner = mgPerson(['joining_date' => '2026-11-10']);
    $leaver = mgPerson(['last_working_day' => '2026-11-12', 'status' => 'resigned']);
    $steady = mgPerson();
    foreach (['2026-11-02', '2026-11-05', '2026-11-11', '2026-11-13', '2026-11-25', '2026-11-26'] as $date) {
        foreach ([$joiner, $leaver, $steady] as $e) {
            mgDay($e, $date, 'absent', ['clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
        }
    }

    $props = mgProps($this, $admin, 'month=2026-11');
    $codes = fn (Employee $e) => array_map(fn ($c) => $c[0] ?? null, array_intersect_key(mgRow($props, $e)['cells'], array_flip([1, 4, 10, 12, 24, 25])));

    expect($codes($steady))->toBe([1 => null, 4 => 'A', 10 => 'A', 12 => 'A', 24 => 'A', 25 => null])
        ->and($codes($joiner))->toBe([1 => null, 4 => null, 10 => 'A', 12 => 'A', 24 => 'A', 25 => null])
        ->and($codes($leaver))->toBe([1 => null, 4 => 'A', 10 => 'A', 12 => null, 24 => null, 25 => null])
        ->and(mgRow($props, $steady)['window'])->toBe([4, 25])
        ->and(mgRow($props, $joiner)['window'])->toBe([10, 25])
        ->and(mgRow($props, $leaver)['window'])->toBe([4, 12])
        ->and(mgRow($props, $steady)['totals']['working_days'])->toBe(4)
        ->and(mgRow($props, $leaver)['totals']['absent'])->toBe(2);
});

it('shows the whole window when the attendance start is not set', function () {
    $admin = userWithRole('admin');
    Settings::set('attendance', ['start_date' => null]);
    $e = mgPerson();
    mgDay($e, '2026-11-02', 'present');

    $row = mgRow(mgProps($this, $admin, 'month=2026-11'), $e);

    expect($row['window'])->toBe([1, 25])->and($row['cells'][1][0])->toBe('P');
});

// ---------------------------------------------------------------- performance

it('reads a fixed number of queries whatever the number of days or people', function (string $role) {
    $admin = userWithRole($role); // HR also reads the privileged logins (one query), an Admin does not
    $count = function (string $month) use ($admin): int {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($admin)->get('/attendance/monthly?month='.$month)->assertOk();

        return $queries;
    };

    // Logins are linked so the per-row edit rule (whose account is privileged) is part of the count.
    $first = employeeFor(userWithRole('hr'), ['joining_date' => '2025-01-01']);
    foreach (['2026-02-02', '2026-03-02', '2026-03-31'] as $date) {
        mgDay($first, $date, 'present');
    }
    $count('2026-01'); // warm-up: permission cache and the like
    $february = $count('2026-02');

    $people = Employee::factory()->count(9)->create(['joining_date' => '2025-01-01']);
    foreach ($people as $i => $e) {
        mgLogin($e, ['admin', 'hr', 'manager', 'employee'][$i % 4]);
        for ($d = 1; $d <= 31; $d++) {
            mgDay($e, sprintf('2026-03-%02d', $d), 'present');
        }
    }
    // A leaver with no last working day: the extra condition is one subquery, not a query per person.
    $gone = mgPerson(['status' => 'resigned']);
    mgDay($gone, '2026-03-05', 'present');
    $march = $count('2026-03');

    expect($march)->toBe($february)->and($march)->toBeLessThanOrEqual(12);
})->with(['admin', 'hr']);

it('lists only the departments of people in a manager scope, and every department for admin', function () {
    $mine = Department::factory()->create(['name' => 'Mine']);
    $theirs = Department::factory()->create(['name' => 'Elsewhere Dept']);
    $manager = userWithRole('manager');
    $boss = employeeFor($manager, ['department_id' => $mine->id]);
    mgPerson(['manager_id' => $boss->id, 'department_id' => $mine->id]);
    mgPerson(['department_id' => $theirs->id]);

    $props = mgProps($this, $manager, 'month=2026-11');
    expect(array_column($props['departments'], 'id'))->toBe([$mine->id])
        ->and(json_encode($props))->not->toContain('Elsewhere Dept');

    expect(array_column(mgProps($this, userWithRole('admin'), 'month=2026-11')['departments'], 'id'))
        ->toContain($mine->id, $theirs->id);
});
