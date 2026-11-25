<?php

use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
// never used; keeps the file free of accidental global names
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
| The profile's "Leave" tab: the current leave year's balance lines and the last 5 requests, for the person themself,
| leave.approve-all, and leave.approve-team on a direct report. Absent, tab and key, for anyone else. "Now" is
| 2026-11-24 14:00 in Karachi.
*/

const PLT_REASON = 'Private medical reason text';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function pltPerson(array $attrs = []): Employee
{
    $e = Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
    approvedLeave($e, '2026-11-23');
    leaveRequest($e, ['reason' => PLT_REASON, 'attachment_path' => 'employees/1/leave/secret.pdf']);

    return $e;
}

it('gives Admin and HR the tab with this year’s balance lines and recent requests', function (string $role) {
    $person = pltPerson();

    $props = $this->actingAs(userWithRole($role))->get("/employees/{$person->id}")->assertOk()->inertiaProps();
    $leave = $props['employee']['leave'];

    expect($props['tabs'])->toContain('leave')
        ->and(array_keys($leave))->toBe(['year', 'own', 'code', 'lines', 'recent'])
        ->and($leave)->toMatchArray(['year' => 2026, 'own' => false, 'code' => $person->employee_code])
        ->and(collect($leave['lines'])->pluck('type_name')->all())->toBe(['Annual', 'Sick', 'Casual', 'Unpaid'])
        ->and(collect($leave['lines'])->firstWhere('type_name', 'Annual'))->toMatchArray(['used' => 1, 'pending' => 1, 'available' => 12])
        ->and(collect($leave['lines'])->firstWhere('type_name', 'Unpaid'))->toMatchArray(['unlimited' => true, 'available' => null]);

    expect($leave['recent'])->toHaveCount(2)
        ->and($leave['recent'][0])->toBe([
            'id' => $leave['recent'][0]['id'],
            'type' => 'Annual',
            'colour' => 'emerald',
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-01',
            'half' => null,
            'dates' => 'Tue 1 Dec 2026',
            'days' => 1,
            'status' => 'pending',
            'partly_cancelled' => false,
        ])
        ->and($leave['recent'][1])->toMatchArray(['status' => 'approved', 'dates' => 'Mon 23 Nov 2026']);

    $json = json_encode($props['employee'], JSON_THROW_ON_ERROR);
    expect($json)->not->toContain(PLT_REASON)->not->toContain('secret.pdf')->not->toContain('reason')->not->toContain('attachment');
})->with(['admin', 'hr']);

it('gives a manager the tab for a direct report, and their own record is marked own', function () {
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $report = pltPerson(['manager_id' => $manager->id]);

    $onReport = $this->actingAs($managerUser)->get("/employees/{$report->id}")->assertOk()->inertiaProps();
    $onSelf = $this->actingAs($managerUser)->get("/employees/{$manager->id}")->assertOk()->inertiaProps();

    expect($onReport['tabs'])->toBe(['basic', 'employment', 'attendance', 'leave'])
        ->and($onReport['employee']['leave']['own'])->toBeFalse()
        ->and($onSelf['tabs'])->toContain('leave')
        ->and($onSelf['employee']['leave']['own'])->toBeTrue();
});

it('shows a plain employee the tab on My Profile', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    approvedLeave($me, '2026-11-23', half: 'pm', type: 'Casual');

    $props = $this->actingAs($user)->get('/my/profile')->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('leave')
        ->and($props['employee']['leave']['own'])->toBeTrue()
        ->and($props['employee']['leave']['recent'][0])->toMatchArray([
            'type' => 'Casual', 'half' => 'pm', 'dates' => 'Mon 23 Nov 2026, second half', 'days' => 0.5,
        ])
        ->and(collect($props['employee']['leave']['lines'])->firstWhere('type_name', 'Casual')['available'])->toBe(9.5);
});

it('leaves the tab and the key out for a viewer who may see the person but not their leave', function () {
    $role = Role::findOrCreate('directory', 'web');
    $role->givePermissionTo('employees.view-all');
    $viewer = User::factory()->create();
    $viewer->assignRole($role);
    $person = pltPerson();

    $props = $this->actingAs($viewer)->get("/employees/{$person->id}")->assertOk()->inertiaProps();

    expect($props['tabs'])->not->toContain('leave')
        ->and($props['employee'])->not->toHaveKey('leave')
        ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain('type_name');
});

it('leaves the tab out for a team lead with employees.view-team but not leave.approve-team', function () {
    $role = Role::findOrCreate('team-lead', 'web');
    $role->givePermissionTo('employees.view-team');
    $lead = User::factory()->create();
    $lead->assignRole($role);
    $leadRecord = employeeFor($lead, ['joining_date' => '2026-01-01']);
    $report = pltPerson(['manager_id' => $leadRecord->id]);

    $props = $this->actingAs($lead)->get("/employees/{$report->id}")->assertOk()->inertiaProps();

    expect($props['employee'])->not->toHaveKey('leave')->and($props['tabs'])->not->toContain('leave');
});

it('never lets a manager open someone else’s report, so no leave leaks', function () {
    $managerUser = userWithRole('manager');
    employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $other = pltPerson();

    $this->actingAs($managerUser)->get("/employees/{$other->id}")->assertForbidden();
});

it('lists at most the last 5 requests, newest first, and flags a partly cancelled one', function () {
    $person = Employee::factory()->create(['joining_date' => '2026-01-01']);
    foreach (range(1, 6) as $i) {
        $day = sprintf('2026-12-%02d', $i);
        leaveRequest($person, ['from_date' => $day, 'to_date' => $day, 'status' => 'rejected', 'created_at' => "2026-11-0{$i} 10:00:00"]);
    }
    $partly = approvedLeave($person, '2026-11-16', '2026-11-18');
    $partly->forceFill(['cancelled_from' => '2026-11-18', 'created_at' => '2026-11-10 10:00:00'])->save();

    $recent = $this->actingAs(userWithRole('hr'))->get("/employees/{$person->id}")->assertOk()->inertiaProps()['employee']['leave']['recent'];

    expect($recent)->toHaveCount(5)
        ->and($recent[0])->toMatchArray(['status' => 'approved', 'partly_cancelled' => true, 'dates' => 'Mon 16 Nov 2026 – Wed 18 Nov 2026'])
        ->and(collect($recent)->pluck('from_date')->all())->toBe(['2026-11-16', '2026-12-06', '2026-12-05', '2026-12-04', '2026-12-03']);
});

it('reads a fixed number of queries for the tab whatever the number of requests', function () {
    $hr = userWithRole('hr');
    $person = Employee::factory()->create(['joining_date' => '2026-01-01']);
    $count = function () use ($hr, $person): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get("/employees/{$person->id}")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    leaveRequest($person);
    $count();
    $small = $count();
    foreach (range(1, 8) as $i) {
        approvedLeave($person, sprintf('2026-10-%02d', $i), type: $i % 2 ? 'Sick' : 'Annual');
    }
    $large = $count();

    expect($large)->toBe($small);
});

it('builds the dates label from text, never a time zone', function () {
    expect(EmployeeProfileResource::leaveDates('2026-12-31', '2027-01-04', null))->toBe('Thu 31 Dec 2026 – Mon 4 Jan 2027')
        ->and(EmployeeProfileResource::leaveDates('2026-12-01', '2026-12-01', 'am'))->toBe('Tue 1 Dec 2026, first half');
});
