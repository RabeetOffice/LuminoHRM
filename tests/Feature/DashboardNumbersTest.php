<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Support\Settings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

const DN_OUTSIDER_FIRST = 'Xylophonia';
const DN_OUTSIDER_DOB_YEAR = '1971';

beforeEach(function () {
    Notification::fake();
    // Storage and the app run in UTC; the company's display zone is Asia/Karachi (UTC+5, no DST).
    $this->dnZone = [date_default_timezone_get(), config('app.timezone')];
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
    // Pin the display zone so the day-boundary tests never depend on the default setting.
    Settings::set('company', ['timezone' => 'Asia/Karachi']);
});

afterEach(function () {
    [$php, $app] = $this->dnZone;
    date_default_timezone_set($php);
    config(['app.timezone' => $app]);
    Date::setTestNow();
});

function dnPlace(): array
{
    $dept = Department::where('name', 'Sales')->first() ?? Department::factory()->create(['name' => 'Sales']);
    $desig = Designation::where('department_id', $dept->id)->first()
        ?? Designation::factory()->create(['department_id' => $dept->id, 'name' => 'Sales Executive']);

    return ['department_id' => $dept->id, 'designation_id' => $desig->id];
}

function dnEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(array_merge(dnPlace(), $attrs));
}

function dnProps($response): array
{
    return $response->viewData('page')['props'];
}

/** The page's own props (shared props such as auth and app left out). */
function dnPageProps($response): array
{
    return array_diff_key(dnProps($response), array_flip(['auth', 'app', 'flash', 'errors', 'name', 'quote', 'sidebarOpen', 'updateFlag']));
}

/**
 * Company: 3 active, 2 probation, and one each of inactive, resigned, terminated.
 * The manager's team: the manager (active) + 2 direct reports (1 active, 1 probation) + 1 resigned report,
 * and one indirect report (not counted for the manager).
 */
function dnCompany(): array
{
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, array_merge(dnPlace(), ['status' => 'active']));
    $direct = dnEmployee(['manager_id' => $manager->id, 'status' => 'active']);
    dnEmployee(['manager_id' => $manager->id, 'status' => 'probation']);
    dnEmployee(['manager_id' => $manager->id, 'status' => 'resigned']);
    dnEmployee(['manager_id' => $direct->id, 'status' => 'active', 'first_name' => DN_OUTSIDER_FIRST, 'dob' => DN_OUTSIDER_DOB_YEAR.'-06-15']);
    dnEmployee(['status' => 'probation']);
    dnEmployee(['status' => 'inactive']);
    dnEmployee(['status' => 'terminated']);

    // active: manager, direct, outsider = 3; probation: 2; inactive/resigned/terminated: 3.
    return [$managerUser, $manager];
}

// ---------------------------------------------------------------- route

it('keeps the dashboard route name and URL, and opens for every role', function (string $role) {
    expect(route('dashboard', [], false))->toBe('/dashboard');

    $this->actingAs(userWithRole($role))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($p) => $p->component('dashboard'));
})->with(['admin', 'hr', 'manager', 'employee']);

// ---------------------------------------------------------------- Total Employees

it('counts active and probation employees company-wide for Admin and HR', function (string $role) {
    dnCompany();

    $props = dnProps($this->actingAs(userWithRole($role))->get('/dashboard')->assertOk());

    expect($props['employeeCount'])->toBe(['scope' => 'company', 'total' => 5, 'probation' => 2]);
})->with(['admin', 'hr']);

it('counts the manager and their direct reports for a Manager, never anyone else', function () {
    [$managerUser] = dnCompany();

    $props = dnProps($this->actingAs($managerUser)->get('/dashboard')->assertOk());

    // Manager (active) + direct active + direct probation; the resigned report and the indirect report are not counted.
    expect($props['employeeCount'])->toBe(['scope' => 'team', 'total' => 3, 'probation' => 1]);
});

it('gives a Manager with no employee record a zero team', function () {
    dnCompany();

    $props = dnProps($this->actingAs(userWithRole('manager'))->get('/dashboard')->assertOk());

    expect($props['employeeCount'])->toBe(['scope' => 'team', 'total' => 0, 'probation' => 0]);
});

it('sends a plain Employee no count at all', function () {
    dnCompany();
    $user = userWithRole('employee');
    employeeFor($user, dnPlace());

    $props = dnProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect($props)->not->toHaveKey('employeeCount')
        ->and($props)->not->toHaveKey('birthdays');
});

it('reports zero on probation when nobody is', function () {
    dnEmployee(['status' => 'active']);
    dnEmployee(['status' => 'inactive']);

    $props = dnProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect($props['employeeCount'])->toBe(['scope' => 'company', 'total' => 1, 'probation' => 0]);
});

// ---------------------------------------------------------------- Today's Birthdays

it('lists today\'s birthdays for Admin and HR with id, name, designation and photo only', function (string $role) {
    Date::setTestNow('2026-06-15 06:00:00'); // 11:00 in Karachi
    $today = dnEmployee(['first_name' => 'Birthday', 'last_name' => 'Person', 'dob' => '1990-06-15', 'status' => 'active']);
    dnEmployee(['first_name' => 'Probation', 'last_name' => 'Birthday', 'dob' => '2001-06-15', 'status' => 'probation']);
    dnEmployee(['first_name' => 'Gone', 'last_name' => 'Person', 'dob' => '1985-06-15', 'status' => 'resigned']);
    dnEmployee(['first_name' => 'Tomorrow', 'last_name' => 'Person', 'dob' => '1990-06-16']);
    dnEmployee(['first_name' => 'No', 'last_name' => 'Date', 'dob' => null]);

    $response = $this->actingAs(userWithRole($role))->get('/dashboard')->assertOk();
    $birthdays = dnProps($response)['birthdays'];

    expect(array_column($birthdays, 'name'))->toBe(['Birthday Person', 'Probation Birthday'])
        ->and($birthdays[0])->toBe([
            'id' => $today->id,
            'name' => 'Birthday Person',
            'designation' => 'Sales Executive',
            'photo_url' => null,
        ]);

    // The birth year and the full date never reach the browser, in any prop (the attendance ones included).
    $json = json_encode(dnPageProps($response));
    expect($json)->not->toContain('1990')->not->toContain('2001')->not->toContain('dob');
    // The attendance figures name today's date ("2026-06-15"): the only "06-15" allowed is inside that exact string.
    expect(str_replace('2026-06-15', '', $json))->not->toContain('06-15');
})->with(['admin', 'hr']);

it('sends an empty birthday list when nobody has a birthday today', function () {
    Date::setTestNow('2026-06-15 06:00:00');
    dnEmployee(['dob' => '1990-01-01']);

    $props = dnProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect($props['birthdays'])->toBe([]);
});

it('decides "today" in the display timezone, not in UTC', function () {
    dnEmployee(['first_name' => 'Fourteenth', 'dob' => '1990-03-14']);
    dnEmployee(['first_name' => 'Fifteenth', 'dob' => '1990-03-15']);
    $admin = userWithRole('admin');

    // 18:59:59 UTC on 14 March is 23:59:59 on 14 March in Karachi.
    Date::setTestNow('2026-03-14 18:59:59');
    $before = dnProps($this->actingAs($admin)->get('/dashboard')->assertOk())['birthdays'];

    // 19:00:00 UTC on 14 March is already 00:00 on 15 March in Karachi (UTC still says the 14th).
    Date::setTestNow('2026-03-14 19:00:00');
    $after = dnProps($this->actingAs($admin)->get('/dashboard')->assertOk())['birthdays'];

    expect(array_map(fn ($b) => explode(' ', $b['name'])[0], $before))->toBe(['Fourteenth'])
        ->and(array_map(fn ($b) => explode(' ', $b['name'])[0], $after))->toBe(['Fifteenth']);
});

it('shows people born on 29 February on 28 February in a non-leap year, and on 29 February in a leap year', function () {
    dnEmployee(['first_name' => 'Leapling', 'dob' => '2000-02-29']);
    dnEmployee(['first_name' => 'Twentyeighth', 'dob' => '1995-02-28']);
    $admin = userWithRole('admin');
    $names = fn () => array_map(
        fn ($b) => explode(' ', $b['name'])[0],
        dnProps($this->actingAs($admin)->get('/dashboard')->assertOk())['birthdays'],
    );

    Date::setTestNow('2027-02-28 07:00:00'); // 2027 is not a leap year
    expect($names())->toBe(['Leapling', 'Twentyeighth']);

    Date::setTestNow('2027-03-01 07:00:00');
    expect($names())->toBe([]);

    Date::setTestNow('2028-02-28 07:00:00'); // 2028 is a leap year
    expect($names())->toBe(['Twentyeighth']);

    Date::setTestNow('2028-02-29 07:00:00');
    expect($names())->toBe(['Leapling']);
});

it('sends a Manager and an Employee no birthday data at all, even on a team member\'s birthday', function (string $role) {
    Date::setTestNow('2026-06-15 06:00:00');
    [$managerUser, $manager] = dnCompany(); // the indirect report was born on 15 June
    dnEmployee(['manager_id' => $manager->id, 'first_name' => 'Teambirthday', 'dob' => '1988-06-15']);

    $user = $role === 'manager' ? $managerUser : userWithRole('employee');
    if ($role === 'employee') {
        employeeFor($user, array_merge(dnPlace(), ['dob' => '1999-06-15']));
    }

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();
    $props = dnProps($response);
    $json = json_encode(dnPageProps($response));

    expect($props)->not->toHaveKey('birthdays')
        ->and($json)->not->toContain('Teambirthday')
        ->and($json)->not->toContain(DN_OUTSIDER_FIRST)
        ->and($json)->not->toContain('1988')
        ->and($json)->not->toContain('1999')
        ->and($json)->not->toContain(DN_OUTSIDER_DOB_YEAR)
        ->and($json)->not->toContain('dob');
})->with(['manager', 'employee']);

it('walks every page prop of each role and finds only the allowed keys', function (string $role, array $keys) {
    dnCompany();
    $user = userWithRole($role);

    $props = dnPageProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect(array_keys($props))->toEqualCanonicalizing($keys);
    if (isset($props['employeeCount'])) {
        expect(array_keys($props['employeeCount']))->toBe(['scope', 'total', 'probation']);
    }
})->with([
    ['admin', ['hasEmployee', 'employeeCount', 'birthdays', 'attendanceDay', 'attendanceToday', 'lateToday', 'attendanceTrend', 'pendingLeaves', 'onLeaveToday', 'leaveByStatus', 'payrollThisMonth', 'exitsWaiting', 'upcomingLeavers']],
    ['hr', ['hasEmployee', 'employeeCount', 'birthdays', 'attendanceDay', 'attendanceToday', 'lateToday', 'attendanceTrend', 'pendingLeaves', 'onLeaveToday', 'leaveByStatus', 'payrollThisMonth', 'exitsWaiting', 'upcomingLeavers']],
    ['manager', ['hasEmployee', 'employeeCount', 'attendanceDay', 'attendanceToday', 'lateToday', 'attendanceTrend', 'pendingLeaves', 'onLeaveToday', 'leaveByStatus', 'exitsWaiting']],
    ['employee', ['hasEmployee']],
]);
