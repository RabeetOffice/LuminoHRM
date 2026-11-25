<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Spatie\Permission\Models\Role;

/*
| The profile's "Attendance" tab: the current month's summary for viewers allowed to see that person's attendance
| (the person, attendance.view-all, attendance.view-team for a direct report). Absent, tab and key, for anyone else.
| "Now" is 2026-11-24 14:00 in Karachi.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

function patPerson(array $attrs = []): Employee
{
    $e = Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-20', 'closed' => true]);
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-23', 'closed' => true, 'status' => 'half_day', 'worked_minutes' => 200]);
    Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-11-24', 'status' => null]);

    return $e;
}

function patProps($response): array
{
    return $response->inertiaProps();
}

it('gives Admin and HR the tab with this month’s summary and a link target for the monthly grid', function (string $role) {
    $person = patPerson();

    $props = patProps($this->actingAs(userWithRole($role))->get("/employees/{$person->id}")->assertOk());

    expect($props['tabs'])->toContain('attendance')
        ->and($props['employee']['attendance'])->toMatchArray([
            'month' => '2026-11', 'label' => 'November 2026', 'own' => false, 'code' => $person->employee_code,
        ])
        ->and($props['employee']['attendance']['totals'])->toMatchArray([
            'present' => 1, 'half_day' => 1, 'equivalent' => 1.5, 'working_days' => 2,
        ]);
})->with(['admin', 'hr']);

it('gives a manager the tab for a direct report, and their own record links to My Attendance', function () {
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $report = patPerson(['manager_id' => $manager->id]);

    $onReport = patProps($this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk());
    $onSelf = patProps($this->actingAs($managerUser)->get("/employees/{$manager->id}")->assertOk());

    expect($onReport['tabs'])->toBe(['basic', 'employment', 'attendance', 'leave'])
        ->and($onReport['employee']['attendance']['own'])->toBeFalse()
        ->and($onSelf['employee']['attendance']['own'])->toBeTrue();
});

it('shows a plain employee the tab on their own record (My Profile)', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    Attendance::factory()->create(['employee_id' => $me->id, 'date' => '2026-11-20', 'closed' => true]);

    $props = patProps($this->actingAs($user)->get('/my/profile')->assertOk());

    expect($props['tabs'])->toContain('attendance')
        ->and($props['employee']['attendance'])->toMatchArray(['own' => true])
        ->and($props['employee']['attendance']['totals']['present'])->toBe(1);
});

it('leaves the tab and the key out for a viewer who may see the person but not their attendance', function () {
    $role = Role::findOrCreate('directory', 'web');
    $role->givePermissionTo('employees.view-all');
    $viewer = User::factory()->create();
    $viewer->assignRole($role);
    $person = patPerson();

    $props = patProps($this->actingAs($viewer)->get("/employees/{$person->id}")->assertOk());

    expect($props['tabs'])->not->toContain('attendance')
        ->and($props['employee'])->not->toHaveKey('attendance');
});

it('leaves the tab and the key out on a report’s profile for a role with employees.view-team but not attendance.view-team', function () {
    $role = Role::findOrCreate('team-lead', 'web');
    $role->givePermissionTo('employees.view-team');
    $lead = User::factory()->create();
    $lead->assignRole($role);
    $leadRecord = employeeFor($lead, ['joining_date' => '2026-01-01']);
    $report = patPerson(['manager_id' => $leadRecord->id]);

    $props = patProps($this->actingAs($lead)->get("/employees/{$report->id}")->assertOk());

    expect($props['tabs'])->not->toContain('attendance')
        ->and($props['employee'])->not->toHaveKey('attendance');
});

it('leaves the tab out for a manager-with-view-team-only on someone else’s report, who cannot open the record at all', function () {
    $managerUser = userWithRole('manager');
    employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $other = patPerson();

    $this->actingAs($managerUser)->get("/employees/{$other->id}")->assertForbidden();
});
