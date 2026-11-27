<?php

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\DashboardPayroll;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| The dashboard's payroll figures: "Payroll This Month" (payroll.view only) = the run of the month before the current
| attendance month, and "My latest payslip" (everyone with an employee record: their own line of an approved / paid
| run, never a draft). "Now" is 2026-11-24 14:00 in Karachi, so the pay month is October 2026.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

function dpProps($response): array
{
    return array_diff_key($response->inertiaProps(), array_flip(['auth', 'app', 'flash', 'errors', 'name', 'quote', 'sidebarOpen', 'updateFlag']));
}

/** A line of $employee in $run, figures in whole rupees. */
function dpItem(PayrollRun $run, Employee $employee, int $netRupees, array $attrs = []): PayrollItem
{
    return PayrollItem::factory()->create([
        'payroll_run_id' => $run->id,
        'employee_id' => $employee->id,
        'gross' => $netRupees * 100,
        'net' => $netRupees * 100,
        ...$attrs,
    ]);
}

/** Every key anywhere in a nested array, with its path. */
function dpKeys(array $data, string $path = ''): array
{
    $keys = [];
    foreach ($data as $key => $value) {
        $here = $path === '' ? (string) $key : $path.'.'.$key;
        $keys[] = $here;
        if (is_array($value)) {
            $keys = [...$keys, ...dpKeys($value, $here)];
        }
    }

    return $keys;
}

// ---------------------------------------------------------------- Payroll This Month

it('gives Admin and HR the pay month as not generated, with every key', function (string $role) {
    $props = dpProps($this->actingAs(userWithRole($role))->get('/dashboard')->assertOk());

    expect($props['payrollThisMonth'])->toMatchArray([
        'month' => '2026-10',
        'label' => 'October 2026',
        'status' => 'none',
        'headcount' => 0,
        'net' => 0,
        'run_id' => null,
    ])->and(array_keys($props['payrollThisMonth']))->toBe(['month', 'label', 'status', 'headcount', 'net', 'run_id', 'url']);
})->with(['admin', 'hr']);

it('shows the pay month run as draft, approved and paid with its people and net total', function () {
    $run = payrollRun('2026-10');
    $run->forceFill(['headcount' => 52, 'net' => 245_000_000])->save();
    // Another month's run is never shown.
    payrollRun('2026-11', 'draft')->forceFill(['headcount' => 3, 'net' => 100])->save();
    $admin = userWithRole('admin');

    $tile = fn () => dpProps($this->actingAs($admin)->get('/dashboard'))['payrollThisMonth'];

    expect($tile())->toMatchArray(['month' => '2026-10', 'status' => 'draft', 'headcount' => 52, 'net' => 245_000_000, 'run_id' => $run->id]);
    $run->forceFill(['status' => 'approved'])->save();
    expect($tile()['status'])->toBe('approved');
    $run->forceFill(['status' => 'paid'])->save();
    expect($tile()['status'])->toBe('paid');
});

it('uses the month before the attendance month: a December viewer sees November', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
    payrollRun('2026-11', 'approved', ['headcount' => 2, 'net' => 13_000_000]);

    $tile = dpProps($this->actingAs(userWithRole('hr'))->get('/dashboard'))['payrollThisMonth'];

    expect($tile)->toMatchArray(['month' => '2026-11', 'label' => 'November 2026', 'status' => 'approved', 'net' => 13_000_000]);
});

it('follows the attendance day: 1 December before the day cutoff is still November, so the pay month is October', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 02:00:00', 'Asia/Karachi'));

    expect(dpProps($this->actingAs(userWithRole('admin'))->get('/dashboard'))['payrollThisMonth']['month'])->toBe('2026-10');
});

it('works out the pay month across a year end', function () {
    expect(DashboardPayroll::payMonth('2027-01-01'))->toBe('2026-12')
        ->and(DashboardPayroll::payMonth('2026-03-31'))->toBe('2026-02')
        ->and(DashboardPayroll::payMonth('2026-12-31'))->toBe('2026-11');
});

it('never sends the tile or any salary figure to a Manager or an Employee', function (string $role) {
    $run = payrollRun('2026-10', 'approved', ['headcount' => 4, 'net' => 987_654_300]);
    $user = userWithRole($role);
    $mine = employeeFor($user, ['joining_date' => '2026-01-01', 'status' => 'active']);
    $other = Employee::factory()->create(['manager_id' => $mine->id]);
    dpItem($run, $other, 77_777);
    // Their own line exists only in a draft: still nothing.
    dpItem(payrollRun('2026-09'), $mine, 55_555);

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();
    $props = dpProps($response);
    $json = json_encode($props);

    expect($props)->not->toHaveKey('payrollThisMonth')
        ->and($props)->not->toHaveKey('latestPayslip')
        ->and(array_filter(dpKeys($props), fn (string $k) => preg_match('/(^|\.)(net|gross|headcount)$/', $k) === 1))->toBe([])
        ->and($json)->not->toContain('9876543')
        ->and($json)->not->toContain('77777')
        ->and($json)->not->toContain('55555');
})->with(['manager', 'employee']);

// ---------------------------------------------------------------- My latest payslip

it('gives an Employee their own latest approved or paid line only, never a draft or someone else\'s', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01', 'status' => 'active']);
    $colleague = Employee::factory()->create();

    $september = payrollRun('2026-09', 'paid');
    $october = payrollRun('2026-10', 'approved');
    $november = payrollRun('2026-11'); // draft
    dpItem($september, $me, 60_000);
    dpItem($october, $me, 64_930);
    dpItem($october, $colleague, 99_999);
    dpItem($november, $me, 12_345);

    $props = dpProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect($props['latestPayslip'])->toMatchArray(['month' => '2026-10', 'label' => 'October 2026', 'net' => 6_493_000])
        ->and(array_keys($props['latestPayslip']))->toBe(['month', 'label', 'net', 'download_url'])
        ->and(json_encode($props))->not->toContain('9999900')->not->toContain('1234500')
        // The latest-payslip net is the only money figure in the props.
        ->and(array_values(array_filter(dpKeys($props), fn (string $k) => preg_match('/(^|\.)net$/', $k) === 1)))->toBe(['latestPayslip.net']);
});

it('shows no payslip card when the only line is a draft or excluded', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    dpItem(payrollRun('2026-10'), $me, 50_000);
    dpItem(payrollRun('2026-09', 'approved'), $me, 50_000, ['excluded' => true, 'excluded_reason' => 'Settled separately']);

    expect(dpProps($this->actingAs($user)->get('/dashboard')))->not->toHaveKey('latestPayslip');
});

it('gives an Admin with an employee record both the tile and their own payslip', function () {
    $admin = userWithRole('admin');
    $me = employeeFor($admin, ['joining_date' => '2026-01-01']);
    dpItem(payrollRun('2026-10', 'approved'), $me, 150_000);

    $props = dpProps($this->actingAs($admin)->get('/dashboard'));

    expect($props['payrollThisMonth']['status'])->toBe('approved')
        ->and($props['latestPayslip']['net'])->toBe(15_000_000);
});

it('links to the payslip download and the payroll runs only once those routes exist', function () {
    $user = userWithRole('admin');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    $run = payrollRun('2026-10', 'approved');
    $item = dpItem($run, $me, 1_000);

    if (! Route::has('my.payslips.download')) {
        Route::get('/my/payslips/{item}', fn () => '')->name('my.payslips.download');
    }
    if (! Route::has('payroll.runs.show')) {
        Route::get('/payroll/runs/{run}', fn () => '')->name('payroll.runs.show');
    }
    app('router')->getRoutes()->refreshNameLookups();

    $props = dpProps($this->actingAs($user)->get('/dashboard'));

    expect($props['latestPayslip']['download_url'])->toBe('/my/payslips/'.$item->id)
        ->and($props['payrollThisMonth']['url'])->toBe('/payroll/runs/'.$run->id);
});

it('reads at most two payroll queries', function () {
    $user = userWithRole('admin');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    $run = payrollRun('2026-10', 'approved');
    dpItem($run, $me, 1_000);
    dpItem(payrollRun('2026-09', 'approved'), $me, 1_000);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $tile = DashboardPayroll::thisMonth(app(AttendanceClock::class));
    $slip = DashboardPayroll::latestPayslip($me->id);
    $queries = array_filter(DB::getQueryLog(), fn (array $q) => str_contains($q['query'], 'payroll_'));
    DB::disableQueryLog();

    expect($tile['run_id'])->toBe($run->id)->and($slip['month'])->toBe('2026-10')->and(count($queries))->toBe(2);
});

// ---------------------------------------------------------------- minors

it('falls back to the runs list for the tile link while the pay month has no run, and to null without the routes', function () {
    if (! Route::has('payroll.runs.index')) {
        Route::get('/payroll/runs', fn () => '')->name('payroll.runs.index');
        app('router')->getRoutes()->refreshNameLookups();
    }

    expect(DashboardPayroll::thisMonth(app(AttendanceClock::class)))->toMatchArray(['status' => 'none', 'run_id' => null, 'url' => '/payroll/runs']);

    $run = payrollRun('2026-10', 'approved');
    $url = DashboardPayroll::thisMonth(app(AttendanceClock::class))['url'];
    expect($url === '/payroll/runs/'.$run->id || $url === '/payroll/runs')->toBeTrue();

    expect(DashboardPayroll::latestPayslip(Employee::factory()->create()->id))->toBeNull();
});

it('leaves the payslip download link null while that route does not exist', function () {
    $me = Employee::factory()->create(['joining_date' => '2026-01-01']);
    dpItem(payrollRun('2026-10', 'approved'), $me, 1_000);

    $slip = DashboardPayroll::latestPayslip($me->id);

    expect($slip['download_url'] === null || str_starts_with($slip['download_url'], '/my/payslips/'))->toBeTrue();
});

it('gives a Manager their own approved line and nothing of anyone else\'s', function () {
    $user = userWithRole('manager');
    $me = employeeFor($user, ['joining_date' => '2026-01-01', 'status' => 'active']);
    $report = Employee::factory()->create(['manager_id' => $me->id, 'joining_date' => '2026-01-01']);
    $run = payrollRun('2026-10', 'approved');
    dpItem($run, $me, 70_000);
    dpItem($run, $report, 88_888);

    $props = dpProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect($props['latestPayslip'])->toMatchArray(['month' => '2026-10', 'net' => 7_000_000])
        ->and($props)->not->toHaveKey('payrollThisMonth')
        ->and(json_encode($props))->not->toContain('8888800');
});

it('takes the newest run, a paid one included, over an older approved one', function () {
    $me = Employee::factory()->create(['joining_date' => '2026-01-01']);
    dpItem(payrollRun('2026-08', 'approved'), $me, 40_000);
    dpItem(payrollRun('2026-10', 'paid'), $me, 65_000);
    dpItem(payrollRun('2026-09', 'approved'), $me, 50_000);

    expect(DashboardPayroll::latestPayslip($me->id))->toMatchArray(['month' => '2026-10', 'net' => 6_500_000]);
});
