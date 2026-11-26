<?php

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use Carbon\CarbonImmutable;

/*
| The profile's "Salary" tab: current salary, the last 5 profiles, active loans with what remains and the last 6
| approved payslip lines, for payroll.view viewers only (Admin, HR). Absent, key and tab, for a Manager or an Employee,
| their own record and My Profile included, and no salary figure anywhere in their props. "Now" is 2026-12-03.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
});

/** Someone with a salary history, a loan, and approved, paid and draft payroll lines. Distinctive figures. */
function pstPerson(array $attrs = []): Employee
{
    $e = Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
    salaryProfile($e, basicRupees: 51111, from: '2025-01-01');
    salaryProfile($e, basicRupees: 57777, from: '2026-07-01');
    loanFor($e, 48888, 4000, '2026-10');
    loanFor($e, 10000, 5000, '2026-01', openingRepaidRupees: 10000); // cleared: not shown

    foreach (['2026-09' => 'paid', '2026-10' => 'approved', '2026-11' => 'draft'] as $month => $status) {
        PayrollItem::factory()->create(['payroll_run_id' => payrollRun($month, $status)->id, 'employee_id' => $e->id, 'net' => 6_493_000]);
    }

    return $e;
}

function pstUser(string $role, array $attrs = []): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01', ...$attrs]);

    return $user->refresh();
}

it('gives Admin and HR the tab with the current salary, history, loans and approved payslips', function (string $role) {
    $person = pstPerson();

    $props = $this->actingAs(pstUser($role))->get("/employees/{$person->id}")->assertOk()->inertiaProps();
    $salary = $props['employee']['salary'];

    expect($props['tabs'])->toContain('salary')
        ->and($salary['code'])->toBe($person->employee_code)
        ->and($salary['current'])->toMatchArray(['effective_from' => '2026-07-01', 'basic' => 5_777_700, 'gross' => 8_777_700, 'tax' => '5% of gross taxable', 'upcoming' => false])
        ->and(array_column($salary['history'], 'effective_from'))->toBe(['2026-07-01', '2025-01-01'])
        ->and($salary['loans'])->toHaveCount(1)
        ->and($salary['loans'][0])->toMatchArray(['type' => 'loan', 'amount' => 4_888_800, 'installment' => 400_000, 'start_month' => '2026-10', 'remaining' => 4_888_800])
        ->and(array_column($salary['payslips'], 'label'))->toBe(['October 2026', 'September 2026'])
        ->and($salary['payslips'][0]['net'])->toBe(6_493_000);
})->with(['admin', 'hr']);

it('limits the history to 5 and the payslips to 6, newest first', function () {
    $person = Employee::factory()->create(['joining_date' => '2020-01-01']);
    foreach (range(2020, 2026) as $year) {
        salaryProfile($person, from: "{$year}-01-01");
    }
    foreach (range(1, 8) as $m) {
        PayrollItem::factory()->create(['payroll_run_id' => payrollRun(sprintf('2026-%02d', $m), 'approved')->id, 'employee_id' => $person->id]);
    }

    $salary = $this->actingAs(pstUser('hr'))->get("/employees/{$person->id}")->inertiaProps()['employee']['salary'];

    expect(array_column($salary['history'], 'effective_from'))->toBe(['2026-01-01', '2025-01-01', '2024-01-01', '2023-01-01', '2022-01-01'])
        ->and(array_column($salary['payslips'], 'month'))->toBe(['2026-08', '2026-07', '2026-06', '2026-05', '2026-04', '2026-03']);
});

it('leaves the tab out for a Manager viewing a report, with no salary figure in the props', function () {
    $manager = pstUser('manager');
    $report = pstPerson(['manager_id' => $manager->employee->id]);

    $response = $this->actingAs($manager)->get("/employees/{$report->id}")->assertOk();
    $props = $response->inertiaProps();
    $json = json_encode($props['employee'], JSON_THROW_ON_ERROR).json_encode($props['tabs']);

    expect($props['tabs'])->not->toContain('salary')
        ->and($props['employee'])->not->toHaveKey('salary');
    foreach (['5777700', '57,777', '4888800', '48,888', '6493000', '64,930', 'payslips', 'salary'] as $secret) {
        expect($json)->not->toContain($secret);
    }
});

it('leaves the tab out on a Manager\'s and an Employee\'s own record and My Profile', function (string $role) {
    $user = pstUser($role);
    salaryProfile($user->employee, basicRupees: 57777);

    $myProps = $this->actingAs($user)->get('/my/profile')->assertOk()->inertiaProps();

    expect($myProps['tabs'])->not->toContain('salary')
        ->and($myProps['employee'])->not->toHaveKey('salary')
        ->and(json_encode($myProps['employee']))->not->toContain('5777700');

    if ($role === 'manager') {
        $own = $this->actingAs($user)->get("/employees/{$user->employee->id}")->assertOk()->inertiaProps();
        expect($own['tabs'])->not->toContain('salary')->and($own['employee'])->not->toHaveKey('salary');
    }
})->with(['manager', 'employee']);

it('shows HR their own salary on My Profile (they see every salary)', function () {
    $hr = pstUser('hr');
    salaryProfile($hr->employee, basicRupees: 57777);

    $props = $this->actingAs($hr)->get('/my/profile')->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('salary')
        ->and($props['employee']['salary']['current']['basic'])->toBe(5_777_700);
});

it('shows an upcoming salary as current for someone joining later, and null when there is none', function () {
    $hr = pstUser('hr');
    $joiner = Employee::factory()->create(['joining_date' => '2027-01-10']);
    salaryProfile($joiner, from: '2027-01-10');
    $none = Employee::factory()->create(['joining_date' => '2024-03-01']);

    $joinerSalary = $this->actingAs($hr)->get("/employees/{$joiner->id}")->inertiaProps()['employee']['salary'];
    $noneSalary = $this->actingAs($hr)->get("/employees/{$none->id}")->inertiaProps()['employee']['salary'];

    expect($joinerSalary['current'])->toMatchArray(['effective_from' => '2027-01-10', 'upcoming' => true])
        ->and($noneSalary)->toMatchArray(['current' => null, 'history' => [], 'loans' => [], 'payslips' => []]);
});
