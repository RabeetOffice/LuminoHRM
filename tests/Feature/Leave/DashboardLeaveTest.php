<?php

use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\DashboardLeave;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveRouting;
use App\Support\Leave\WaitingForMe;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
| The dashboard's leave figures: Pending Leaves, On Leave Today and this month's requests by status for leave.approve-all
| (everyone) / leave.approve-team (own record + direct reports), and the viewer's own Leave Balance for everyone with
| an employee record who lacks payroll.view. "Now" is 2026-11-24 (a Tuesday) 14:00 in Karachi: the attendance day is
| 2026-11-24.
*/

const DL_OUTSIDER = 'Zephyrinus';
const DL_REASON = 'Confidential family reason text';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function dlPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', 'status' => 'active', ...$attrs]);
}

/** The dashboard's own props (shared props left out). */
function dlProps($response): array
{
    return array_diff_key($response->inertiaProps(), array_flip(['auth', 'app', 'flash', 'errors', 'name', 'quote', 'sidebarOpen', 'updateFlag']));
}

/**
 * A manager, two direct reports, a report of a report and an outsider (someone else's report).
 *
 * @return array{0: User, 1: Employee, 2: Employee, 3: Employee, 4: Employee, 5: Employee}
 */
function dlTeam(): array
{
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01', 'status' => 'active']);
    $a = dlPerson(['manager_id' => $manager->id, 'first_name' => 'Amina']);
    $b = dlPerson(['manager_id' => $manager->id, 'first_name' => 'Bilal']);
    $indirect = dlPerson(['manager_id' => $a->id, 'first_name' => 'Indirectus']);
    $outsiderManager = dlPerson();
    $outsider = dlPerson(['manager_id' => $outsiderManager->id, 'first_name' => DL_OUTSIDER]);

    return [$managerUser, $manager, $a, $b, $indirect, $outsider];
}

// ---------------------------------------------------------------- who gets what

it('gives a plain employee only their own leave balance, never the company leave figures', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    approvedLeave($me, '2026-11-24'); // 1 Annual day used
    leaveRequest($me, ['leave_type_id' => leaveType('Sick')->id, 'reason' => DL_REASON]); // 1 Sick day pending
    $colleague = dlPerson(['first_name' => DL_OUTSIDER]);
    approvedLeave($colleague, '2026-11-24');

    $response = $this->actingAs($user)->get('/dashboard')->assertOk();
    $props = dlProps($response);

    expect($props)->not->toHaveKeys(['pendingLeaves', 'onLeaveToday', 'leaveByStatus'])
        ->and($props)->toHaveKey('myLeave')
        ->and($props['myLeave']['year'])->toBe(2026)
        // Annual 14 − 1 used = 13, Sick 8 − 1 pending = 7, Casual 10: unpaid is never in the total.
        ->and($props['myLeave']['total_available'])->toEqual(30)
        ->and(collect($props['myLeave']['lines'])->pluck('type_name')->all())->toBe(['Annual', 'Sick', 'Casual', 'Unpaid']);

    $lines = collect($props['myLeave']['lines'])->keyBy('type_name');
    expect($lines['Annual'])->toMatchArray(['used' => 1.0, 'remaining' => 13.0, 'available' => 13.0, 'unlimited' => false])
        ->and($lines['Sick'])->toMatchArray(['pending' => 1.0, 'available' => 7.0])
        ->and($lines['Unpaid'])->toMatchArray(['unlimited' => true, 'available' => null, 'remaining' => null]);

    $json = json_encode($props, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain(DL_OUTSIDER)->not->toContain(DL_REASON);
});

it('gives no leave balance to an account without an employee record', function () {
    $props = dlProps($this->actingAs(userWithRole('employee'))->get('/dashboard')->assertOk());

    expect($props)->not->toHaveKeys(['myLeave', 'pendingLeaves', 'onLeaveToday', 'leaveByStatus']);
});

it('gives Admin and HR the company figures and no leave balance tile (they see payroll)', function (string $role) {
    $viewer = userWithRole($role);
    employeeFor($viewer, ['joining_date' => '2026-01-01']);
    $x = dlPerson(['first_name' => 'Xavier']);
    $y = dlPerson(['first_name' => 'Yasmin']);
    approvedLeave($x, '2026-11-24');
    leaveRequest($y, ['from_date' => '2026-11-26', 'to_date' => '2026-11-26']);

    $props = dlProps($this->actingAs($viewer)->get('/dashboard')->assertOk());

    expect($props)->toHaveKeys(['pendingLeaves', 'onLeaveToday', 'leaveByStatus'])
        ->and($props)->not->toHaveKey('myLeave')
        ->and($props['pendingLeaves'])->toBe(['count' => 1])
        ->and(collect($props['onLeaveToday'])->pluck('name')->all())->toBe([$x->full_name])
        ->and($props['leaveByStatus']['counts'])->toMatchArray(['pending' => 1, 'approved' => 1]);
})->with(['admin', 'hr']);

it('scopes a manager’s figures to their own record and direct reports, naming nobody else', function () {
    [$managerUser, $manager, $a, $b, $indirect, $outsider] = dlTeam();
    approvedLeave($a, '2026-11-24');
    approvedLeave($indirect, '2026-11-24');
    approvedLeave($outsider, '2026-11-24');
    leaveRequest($b, ['from_date' => '2026-11-27', 'to_date' => '2026-11-27', 'reason' => DL_REASON]);
    leaveRequest($outsider, ['from_date' => '2026-11-27', 'to_date' => '2026-11-27']);
    leaveRequest($indirect, ['from_date' => '2026-11-27', 'to_date' => '2026-11-27']);

    $props = dlProps($this->actingAs($managerUser)->get('/dashboard')->assertOk());

    expect($props['pendingLeaves'])->toBe(['count' => 1])
        ->and(collect($props['onLeaveToday'])->pluck('id')->all())->toBe([$a->id])
        ->and($props['leaveByStatus']['counts'])->toBe(['pending' => 1, 'manager_approved' => 0, 'approved' => 1, 'rejected' => 0, 'cancelled' => 0])
        // A Manager does not see payroll: they get their own balance too.
        ->and($props)->toHaveKey('myLeave');

    $json = json_encode($props, JSON_THROW_ON_ERROR);
    expect($json)->not->toContain(DL_OUTSIDER)->not->toContain('Indirectus')->not->toContain(DL_REASON);
});

// ---------------------------------------------------------------- pending leaves

it('counts for a manager only the requests waiting at their own step', function () {
    [$managerUser, $manager, $a, $b, , $outsider] = dlTeam();
    leaveRequest($a);                                           // waiting for the manager: counts
    leaveRequest($b, ['from_date' => '2026-12-02', 'to_date' => '2026-12-02']); // counts
    leaveRequest($a, ['status' => 'manager_approved', 'from_date' => '2026-12-03', 'to_date' => '2026-12-03']); // HR's step
    leaveRequest($a, ['status' => 'rejected', 'from_date' => '2026-12-04', 'to_date' => '2026-12-04']);
    leaveRequest($manager);                                     // their own request: never theirs to decide
    leaveRequest($outsider);                                    // out of scope

    $props = dlProps($this->actingAs($managerUser)->get('/dashboard')->assertOk());

    expect($props['pendingLeaves'])->toBe(['count' => 2]);
});

it('counts for HR only the open requests at their own (final) step, never their own', function () {
    [$managerUser, $manager, $a, $b, , $outsider] = dlTeam();
    $hr = userWithRole('hr');
    $hrRecord = employeeFor($hr, ['joining_date' => '2026-01-01']);
    leaveRequest($a);                                                          // at the manager step: under "All", not counted
    leaveRequest($b, ['status' => 'manager_approved']);
    leaveRequest($outsider);
    leaveRequest($manager, ['status' => 'approved']);
    leaveRequest($hrRecord);                                                   // own request: an Admin decides it

    $props = dlProps($this->actingAs($hr)->get('/dashboard')->assertOk());

    // $b (manager approved) and $outsider (no reporting manager, so straight to the final step).
    expect($props['pendingLeaves'])->toBe(['count' => 2]);
});

it('matches the "waiting for me" rule of the requests screen', function (string $role) {
    $this->withoutVite();
    [$managerUser, $manager, $a, $b, $indirect, $outsider] = dlTeam();
    $viewer = $role === 'manager' ? $managerUser : userWithRole($role);
    foreach ([$a, $b, $indirect, $outsider, $manager] as $i => $person) {
        leaveRequest($person, ['status' => $i % 2 === 0 ? 'pending' : 'manager_approved']);
    }

    $routing = new LeaveRouting;
    $expected = $routing->scope(LeaveRequest::query()->with('employee'), $viewer)->get()
        ->filter(fn (LeaveRequest $r) => WaitingForMe::includes($routing, $viewer, $r))
        ->count();

    $props = dlProps($this->actingAs($viewer)->get('/dashboard')->assertOk());
    // The Leave -> Requests "Waiting for me" tab counts the same requests.
    $tabs = $this->actingAs($viewer)->get('/leave/requests')->assertOk()->inertiaProps('counts');

    expect($props['pendingLeaves']['count'])->toBe($expected)->and($expected)->toBeGreaterThan(0)
        ->and($tabs['waiting'])->toBe($props['pendingLeaves']['count']);
})->with(['admin', 'hr', 'manager']);

// ---------------------------------------------------------------- on leave today

it('lists people on leave today with full and half days, and leaves out other days, cancelled days and leavers', function () {
    $admin = userWithRole('admin');
    $full = dlPerson(['first_name' => 'Fatima']);
    $am = dlPerson(['first_name' => 'Hamza']);
    $both = dlPerson(['first_name' => 'Iqra']);
    $yesterday = dlPerson(['first_name' => 'Junaid']);
    $cancelled = dlPerson(['first_name' => 'Kamran']);
    $leaver = dlPerson(['first_name' => 'Laila', 'status' => 'resigned', 'last_working_day' => null]);
    $gone = dlPerson(['first_name' => 'Maryam', 'status' => 'resigned', 'last_working_day' => '2026-11-20']);

    approvedLeave($full, '2026-11-23', '2026-11-25', type: 'Sick');
    approvedLeave($am, '2026-11-24', half: 'am');
    approvedLeave($both, '2026-11-24', half: 'am');
    approvedLeave($both, '2026-11-24', half: 'pm', type: 'Casual');
    approvedLeave($yesterday, '2026-11-23');
    // Cancelled from today: today's day was removed, the request stays approved and partly cancelled.
    $part = approvedLeave($cancelled, '2026-11-23', '2026-11-25');
    LeaveDay::query()->where('leave_request_id', $part->id)->where('date', '>=', '2026-11-24')->delete();
    $part->forceFill(['cancelled_from' => '2026-11-24'])->save();
    approvedLeave($leaver, '2026-11-24');
    approvedLeave($gone, '2026-11-24');

    $props = dlProps($this->actingAs($admin)->get('/dashboard')->assertOk());

    expect($props['onLeaveToday'])->toBe([
        ['id' => $full->id, 'name' => $full->full_name, 'type' => 'Sick', 'colour' => 'rose', 'half' => 'full', 'photo_url' => null],
        ['id' => $am->id, 'name' => $am->full_name, 'type' => 'Annual', 'colour' => 'emerald', 'half' => 'am', 'photo_url' => null],
        ['id' => $both->id, 'name' => $both->full_name, 'type' => 'Annual', 'colour' => 'emerald', 'half' => 'full', 'photo_url' => null],
    ]);
});

it('uses the attendance day, not the calendar date, after midnight and before the day cutoff', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 02:00:00', 'Asia/Karachi')); // before the 04:00 cutoff
    $p = dlPerson();
    approvedLeave($p, '2026-11-24');
    approvedLeave(dlPerson(), '2026-11-25');

    $props = dlProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect(collect($props['onLeaveToday'])->pluck('id')->all())->toBe([$p->id]);
});

// ---------------------------------------------------------------- requests by status

it('counts this month’s requests by status, by the month of their first date', function () {
    $admin = userWithRole('admin');
    $p = dlPerson();
    foreach (['pending', 'pending', 'manager_approved', 'approved', 'rejected', 'cancelled'] as $i => $status) {
        $day = sprintf('2026-11-%02d', 2 + $i);
        leaveRequest($p, ['status' => $status, 'from_date' => $day, 'to_date' => $day]);
    }
    leaveRequest($p, ['from_date' => '2026-11-30', 'to_date' => '2026-12-02', 'days' => 3]); // starts in November: counts
    leaveRequest($p, ['from_date' => '2026-10-30', 'to_date' => '2026-11-02', 'days' => 2]); // starts in October: no
    leaveRequest($p, ['from_date' => '2026-12-01', 'to_date' => '2026-12-01']);              // December: no

    $props = dlProps($this->actingAs($admin)->get('/dashboard')->assertOk());

    expect($props['leaveByStatus'])->toBe([
        'month' => '2026-11',
        'label' => 'November 2026',
        'counts' => ['pending' => 3, 'manager_approved' => 1, 'approved' => 1, 'rejected' => 1, 'cancelled' => 1],
    ]);
});

it('sends the status counts with zeros, never a missing key, to an approver with no requests', function () {
    $props = dlProps($this->actingAs(userWithRole('hr'))->get('/dashboard')->assertOk());

    expect($props['leaveByStatus']['counts'])->toBe(['pending' => 0, 'manager_approved' => 0, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0])
        ->and($props['pendingLeaves'])->toBe(['count' => 0])
        ->and($props['onLeaveToday'])->toBe([]);
});

// ---------------------------------------------------------------- permissions, not roles

it('follows the permissions: leave.approve-team without payroll.view gets team figures and a balance', function () {
    $role = Role::findOrCreate('team-lead', 'web');
    $role->givePermissionTo(['leave.approve-team', 'employees.view-team']);
    $lead = User::factory()->create();
    $lead->assignRole($role);
    $leadRecord = employeeFor($lead, ['joining_date' => '2026-01-01']);
    $report = dlPerson(['manager_id' => $leadRecord->id]);
    $stranger = dlPerson(['first_name' => DL_OUTSIDER]);
    approvedLeave($report, '2026-11-24');
    approvedLeave($stranger, '2026-11-24');

    $props = dlProps($this->actingAs($lead)->get('/dashboard')->assertOk());

    expect(collect($props['onLeaveToday'])->pluck('id')->all())->toBe([$report->id])
        ->and($props)->toHaveKey('myLeave')
        ->and(json_encode($props, JSON_THROW_ON_ERROR))->not->toContain(DL_OUTSIDER);
});

it('sums only the paid lines into the total available, exact to the half day', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    approvedLeave($me, '2026-11-24', half: 'am');
    approvedLeave($me, '2026-11-23', type: 'Unpaid');

    $mine = DashboardLeave::mine(app(LeaveBalances::class), app(AttendanceClock::class), $me->id);

    // 13.5 + 8 + 10; the unpaid day changes nothing.
    expect($mine['total_available'])->toBe(31.5)
        ->and(DashboardLeave::mine(app(LeaveBalances::class), app(AttendanceClock::class), 999999))->toBeNull();
});

it('does not net an overdrawn type against the others in the total available', function () {
    $line = fn (bool $paid, float $available) => new BalanceLine(1, 'T', '#000', 2026, $paid, 0, 0, 0, 0, 0, 0, $available, $available, $available < 0);

    expect(DashboardLeave::totalAvailable([$line(true, 10), $line(true, -2)]))->toBe(10.0)
        ->and(DashboardLeave::totalAvailable([$line(true, 10.5), $line(true, -2.5), $line(false, INF)]))->toBe(10.5);
});

it('counts the pending days of every year in pending_total, a request dated next January included', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    leaveRequest($me, ['from_date' => '2026-11-26', 'to_date' => '2026-11-26', 'days' => 1]);
    leaveRequest($me, ['from_date' => '2027-01-05', 'to_date' => '2027-01-05', 'days' => 0.5, 'half' => 'am', 'is_half_day' => true]);
    leaveRequest($me, ['from_date' => '2027-01-06', 'to_date' => '2027-01-06', 'days' => 1, 'status' => 'rejected']);

    $mine = DashboardLeave::mine(app(LeaveBalances::class), app(AttendanceClock::class), $me->id);

    expect($mine['pending_total'])->toBe(1.5);
});

it('hasEmployee tells the page whether the account is linked to an employee record', function () {
    expect(dlProps($this->actingAs(userWithRole('employee'))->get('/dashboard')->assertOk())['hasEmployee'])->toBeFalse();

    $user = userWithRole('employee');
    employeeFor($user, ['joining_date' => '2026-01-01']);

    expect(dlProps($this->actingAs($user)->get('/dashboard')->assertOk())['hasEmployee'])->toBeTrue();
});

// ---------------------------------------------------------------- query count

it('reads a fixed number of queries for the leave figures whatever the number of people', function (string $role) {
    [$managerUser, $manager] = dlTeam();
    $viewer = $role === 'manager' ? $managerUser : userWithRole($role);
    if ($role === 'employee') {
        employeeFor($viewer, ['joining_date' => '2026-01-01']);
    }

    $count = function () use ($viewer): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->get('/dashboard')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $seed = function (int $people) use ($manager, $viewer): void {
        for ($i = 0; $i < $people; $i++) {
            $boss = dlPerson(['manager_id' => $manager->id]);
            $p = dlPerson(['manager_id' => $i % 2 === 0 ? $manager->id : $boss->id]);
            userWithRole('manager')->forceFill(['employee_id' => $boss->id])->save();
            approvedLeave($p, '2026-11-24', half: $i % 3 === 0 ? 'am' : null);
            leaveRequest($p, ['from_date' => '2026-11-26', 'to_date' => '2026-11-26']);
            leaveRequest($boss, ['status' => 'manager_approved', 'from_date' => '2026-11-27', 'to_date' => '2026-11-27']);
        }
        if ($viewer->employee_id !== null) {
            leaveRequest(Employee::find($viewer->employee_id), ['from_date' => '2026-12-0'.(1 + $people % 5), 'to_date' => '2026-12-0'.(1 + $people % 5)]);
        }
    };

    $seed(2);
    $count(); // warm the per-process caches
    $small = $count();
    $seed(8);
    $count(); // the seeder behind userWithRole() resets the permission cache: warm it again
    $large = $count();

    // Measured with leave data present (approved + pending requests, a leave day today): admin 22, hr 22, manager 24,
    // employee 11 queries for the whole dashboard (Phase 5 added one payroll query each: the "Payroll This Month" run
    // for admin / hr, the own latest payslip for viewers with an employee record; Phase 6b added the two waiting-reviews queries, so a manager now reads 26;
    // Phase 6c added the exit cards: admin 29, manager 28). Still fixed whatever the number of people.
    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(29);
})->with(['admin', 'hr', 'manager', 'employee']);
