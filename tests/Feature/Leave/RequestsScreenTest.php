<?php

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveDays;
use App\Support\Leave\WaitingForMe;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
| "Now" is 2026-11-25 (Wednesday) 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function rsPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

function rsRequest(Employee $e, string $from, ?string $to = null, array $attrs = []): LeaveRequest
{
    $to ??= $from;

    return leaveRequest($e, [
        'from_date' => $from,
        'to_date' => $to,
        'days' => LeaveDays::plan($e, $from, $to, null)->total,
        'reason' => 'Personal errands to run.',
        ...$attrs,
    ]);
}

it('forbids the list to a plain employee but shows them their own request and nobody else\'s', function () {
    [$user, $employee] = rsPerson();
    [, $other] = rsPerson();
    $own = rsRequest($employee, '2026-12-01');
    $theirs = rsRequest($other, '2026-12-01');

    $this->actingAs($user)->get('/leave/requests')->assertForbidden();
    $this->actingAs($user)->get("/leave/requests/{$own->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('leave/request-show')->where('request.id', $own->id)
            ->where('request.can.decide', null)->where('request.can.cancel_open', true)
            ->where('request.is_own', true));
    $this->actingAs($user)->get("/leave/requests/{$theirs->id}")->assertForbidden();
    $this->actingAs($user)->get('/leave/requests/999999')->assertForbidden();
});

it('sends guests to sign in', function () {
    $this->get('/leave/requests')->assertRedirect('/login');
});

it('shows a manager their own team only, on the list and the detail page', function () {
    [$managerUser, $manager] = rsPerson('manager');
    [, $report] = rsPerson('employee', ['manager_id' => $manager->id]);
    [, $stranger] = rsPerson();
    $mine = rsRequest($report, '2026-12-01');
    $notMine = rsRequest($stranger, '2026-12-01');

    $this->actingAs($managerUser)->get('/leave/requests?tab=all')->assertOk()
        ->assertInertia(fn ($p) => $p->component('leave/requests')->where('tab', 'all')
            ->has('requests.data', 1)->where('requests.data.0.id', $mine->id)
            ->where('counts', ['waiting' => 1, 'all' => 1])
            ->where('can.apply_on_behalf', false)->where('on_behalf', null));

    $this->actingAs($managerUser)->get("/leave/requests/{$mine->id}")->assertOk();
    $this->actingAs($managerUser)->get("/leave/requests/{$notMine->id}")->assertForbidden();
});

it('lists what each user may decide now under "Waiting for me", with counts that match the rows', function () {
    [$managerUser, $manager] = rsPerson('manager', ['first_name' => 'Ahmed', 'last_name' => 'Khan']);
    $managerUser->forceFill(['name' => 'Ahmed Khan'])->save();
    [, $report] = rsPerson('employee', ['manager_id' => $manager->id]);
    [, $loner] = rsPerson();
    [$hrUser] = rsPerson('hr');

    $atManager = rsRequest($report, '2026-12-01');
    $atHr = rsRequest($report, '2026-12-03', null, ['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id]);
    $direct = rsRequest($loner, '2026-12-07');
    approvedLeave($loner, '2026-12-14');

    $this->actingAs($managerUser)->get('/leave/requests')->assertOk()
        ->assertInertia(fn ($p) => $p->where('tab', 'waiting')->has('requests.data', 1)
            ->where('requests.data.0.id', $atManager->id)
            ->where('requests.data.0.stage', 'manager')
            ->where('requests.data.0.manager_first_name', 'Ahmed')
            ->where('requests.data.0.can.decide', 'manager')
            ->where('requests.data.0.can.skips_manager', false)
            ->where('counts', ['waiting' => 1, 'all' => 2]));

    // HR: the final-step requests only; the one still with the manager is under All ("Decide as HR").
    $this->actingAs($hrUser)->get('/leave/requests')->assertOk()
        ->assertInertia(fn ($p) => $p->has('requests.data', 2)
            ->where('counts', ['waiting' => 2, 'all' => 4])
            ->where('requests.data.0.id', $atHr->id)
            ->where('requests.data.0.stage', 'final')
            ->where('requests.data.0.can.decide', 'final')
            ->where('requests.data.1.id', $direct->id)
            ->where('can.apply_on_behalf', true)
            ->has('on_behalf.employees')
            ->where('on_behalf.window', ['min' => '2026-10-26', 'max' => '2027-12-31']));

    $this->actingAs($hrUser)->get('/leave/requests?tab=all')->assertOk()
        ->assertInertia(fn ($p) => $p->has('requests.data', 4)->where('counts', ['waiting' => 2, 'all' => 4])
            ->where('requests.data', fn ($rows) => collect($rows)->firstWhere('id', $atManager->id)['can']['skips_manager'] === true
                && collect($rows)->firstWhere('id', $atManager->id)['can']['decide'] === 'final'));

    expect(WaitingForMe::count($hrUser))->toBe(2)->and(WaitingForMe::count($managerUser))->toBe(1);
});

it('offers on-behalf applications only for people the user may administer, never their own record', function () {
    [$admin, $adminEmployee] = rsPerson('admin');
    [$hrUser, $hrEmployee] = rsPerson('hr');
    [, $employee] = rsPerson();

    $ids = fn (User $u) => collect($this->actingAs($u)->get('/leave/requests')->assertOk()
        ->viewData('page')['props']['on_behalf']['employees'])->pluck('id')->all();

    expect($ids($admin))->toContain($hrEmployee->id, $employee->id)->not->toContain($adminEmployee->id)
        ->and($ids($hrUser))->toContain($employee->id)->not->toContain($hrEmployee->id, $adminEmployee->id);
});

it('sends the back link: My Leaves for the requester, the requests list for approvers', function () {
    [$user, $employee] = rsPerson();
    [$hrUser] = rsPerson('hr');
    $r = rsRequest($employee, '2026-12-01');

    $this->actingAs($user)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('back', ['url' => '/my/leaves', 'label' => 'My Leaves']));
    $this->actingAs($hrUser)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('back', ['url' => '/leave/requests', 'label' => 'Leave Requests']));
});

it('shows the manager\'s note and HR\'s note separately on the detail page', function () {
    [$managerUser, $manager] = rsPerson('manager');
    [$user, $employee] = rsPerson('employee', ['manager_id' => $manager->id]);
    [$hrUser] = rsPerson('hr');
    $r = rsRequest($employee, '2026-12-01');

    $this->actingAs($managerUser)->put("/leave/requests/{$r->id}/manager-decide", ['decision' => 'approve', 'note' => 'Covered by Sara.'])
        ->assertSessionHas('success');
    $this->actingAs($hrUser)->put("/leave/requests/{$r->id}/final-decide", ['decision' => 'approve', 'note' => 'Fine by HR.'])
        ->assertSessionHas('success');

    $this->actingAs($user)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('request.manager_note', 'Covered by Sara.')
            ->where('request.hr_note', 'Fine by HR.')
            ->where('request.decision_note', 'Fine by HR.')
            ->missing('history.0.step'));
});

it('filters by status, type, department, overlapping dates and search', function () {
    [$hrUser] = rsPerson('hr');
    $sales = Department::factory()->create(['name' => 'Sales']);
    [, $ann] = rsPerson('employee', ['first_name' => 'Annabel', 'last_name' => 'Smith', 'department_id' => $sales->id]);
    [, $bob] = rsPerson('employee', ['first_name' => 'Bob', 'last_name' => 'Jones']);
    $a = rsRequest($ann, '2026-12-01', '2026-12-04');
    $b = rsRequest($bob, '2026-12-10', null, ['leave_type_id' => leaveType('Sick')->id]);
    $c = approvedLeave($bob, '2026-12-21');

    $ids = fn (string $query) => collect($this->actingAs($hrUser)->get("/leave/requests?tab=all&{$query}")->assertOk()
        ->viewData('page')['props']['requests']['data'])->pluck('id')->sort()->values()->all();

    expect($ids('status=approved'))->toBe([$c->id])
        ->and($ids('type='.leaveType('Sick')->id))->toBe([$b->id])
        ->and($ids("department={$sales->id}"))->toBe([$a->id])
        ->and($ids('from=2026-12-04&to=2026-12-10'))->toBe([$a->id, $b->id])
        ->and($ids('from=2026-12-05&to=2026-12-09'))->toBe([])
        ->and($ids('search=annab'))->toBe([$a->id])
        ->and($ids('status=bogus&from=nonsense'))->toHaveCount(3);
});

it('runs a fixed number of queries for the list, whatever the number of rows', function () {
    [$hrUser] = rsPerson('hr');
    [$managerUser, $manager] = rsPerson('manager');

    $count = function (int $rows) use ($hrUser, $manager): int {
        LeaveRequest::query()->delete();
        for ($i = 0; $i < $rows; $i++) {
            [, $e] = rsPerson('employee', ['manager_id' => $manager->id]);
            rsRequest($e, '2026-12-01');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hrUser)->get('/leave/requests')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(1); // the first request warms the permission cache and the session

    expect($count(2))->toBe($count(6));
});

it('runs a fixed number of queries for HR viewing approved rows (cancel windows in one query)', function () {
    [$hrUser] = rsPerson('hr');

    $count = function (int $rows) use ($hrUser): int {
        LeaveRequest::query()->delete();
        for ($i = 0; $i < $rows; $i++) {
            [, $e] = rsPerson();
            approvedLeave($e, '2026-11-23', '2026-11-27');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $props = $this->actingAs($hrUser)->get('/leave/requests?tab=all')->assertOk()->viewData('page')['props'];
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($props['requests']['data'])->toHaveCount($rows)
            ->and($props['requests']['data'][0]['can']['cancel_approved'])->toBeTrue()
            ->and($props['requests']['data'][0]['cancel_range'])->toBe(['min' => '2026-11-23', 'max' => '2026-11-27', 'default' => '2026-11-25']);

        return $n;
    };

    $count(1);

    expect($count(2))->toBe($count(6));
});

it('shows the detail page: planned days while open, ledger days once approved, balances, needs attention and history', function () {
    [$user, $employee] = rsPerson();
    [$hrUser] = rsPerson('hr');
    [$otherManager] = rsPerson('manager');
    // balance_before: what applying stored; approval keeps it.
    $r = rsRequest($employee, '2026-11-23', '2026-11-24', ['balance_before' => ['2026' => 14]]);

    $this->actingAs($hrUser)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('days_source', 'plan')
            ->where('days', [['date' => '2026-11-23', 'half' => 'full', 'amount' => 1], ['date' => '2026-11-24', 'half' => 'full', 'amount' => 1]])
            ->where('request.can.decide', 'final')
            ->where('balance.0.year', 2026)
            ->where('balance.0.now.pending', 2)
            ->where('history', []));

    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24', 'status' => 'present',
        'closed' => true, 'is_manual' => true]);
    $this->actingAs($hrUser)->from("/leave/requests/{$r->id}")
        ->put("/leave/requests/{$r->id}/final-decide", ['decision' => 'approve', 'note' => 'Fine by HR.'])
        ->assertSessionHas('success');

    $this->actingAs($user)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('days_source', 'ledger')
            ->where('request.status', 'approved')
            ->where('request.hr_decider', $hrUser->name)
            ->where('balance.0.before', 14)
            ->where('balance.0.now.used', 2)
            ->where('needs_attention', ['2026-11-24'])
            ->has('history', 1)
            ->where('history.0.description', 'Leave approved')
            ->where('history.0.by', $hrUser->name)
            ->where('history.0.note', 'Fine by HR.')
            ->where('request.can.decide', null)
            ->where('request.can.cancel_approved', false));

    // HR may cancel it, from today at the earliest kept inside the leave days.
    $this->actingAs($hrUser)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('request.can.cancel_approved', true)
            ->where('request.cancel_range', ['min' => '2026-11-23', 'max' => '2026-11-24', 'default' => '2026-11-24']));

    $this->actingAs($otherManager)->get("/leave/requests/{$r->id}")->assertForbidden();
});
