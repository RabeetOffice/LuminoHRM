<?php

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveRouting;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
});

/**
 * A login holding $role with its own employee record.
 *
 * @param  array<string, mixed>  $attrs  employee overrides
 * @return array{0: User, 1: Employee}
 */
function lrPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

function lrRouting(): LeaveRouting
{
    return new LeaveRouting;
}

// ---------------------------------------------------------------- the manager step (table-driven)

dataset('manager step cases', [
    'reporting manager with the Manager role' => ['manager', true],
    'no manager' => ['none', false],
    'manager without a login' => ['no-login', false],
    'manager with a disabled login' => ['disabled', false],
    'manager with the Employee role' => ['employee', false],
    'manager with the HR role (decides once, as final)' => ['hr', false],
    'manager with the Admin role (decides once, as final)' => ['admin', false],
    'the employee is their own manager' => ['self', false],
]);

it('routes the manager step', function (string $case, bool $hasManagerStep) {
    $managerUser = null;
    $managerId = null;

    if ($case === 'no-login') {
        $managerId = Employee::factory()->create()->id;
    } elseif (! in_array($case, ['none', 'self'], true)) {
        [$managerUser, $manager] = lrPerson($case === 'disabled' ? 'manager' : $case);
        $managerId = $manager->id;

        if ($case === 'disabled') {
            $managerUser->forceFill(['is_active' => false])->save();
        }
    }

    [, $employee] = lrPerson('employee', ['manager_id' => $managerId]);

    if ($case === 'self') {
        $employee->forceFill(['manager_id' => $employee->id])->save();
    }

    $request = leaveRequest($employee);
    $routing = lrRouting();

    expect($routing->stage($request))->toBe($hasManagerStep ? LeaveRouting::STAGE_MANAGER : LeaveRouting::STAGE_FINAL);

    if ($hasManagerStep) {
        expect($routing->managerApprover($employee)?->is($managerUser))->toBeTrue()
            ->and($routing->decidableStage($managerUser, $request))->toBe(LeaveRouting::STAGE_MANAGER);
    } else {
        expect($routing->managerApprover($employee))->toBeNull();
    }

    if (in_array($case, ['hr', 'admin'], true)) {
        expect($routing->decidableStage($managerUser, $request))->toBe(LeaveRouting::STAGE_FINAL)
            ->and($routing->skipsManager($managerUser, $request))->toBeFalse();
    }
})->with('manager step cases');

it('runs employee -> manager -> HR, and HR may decide while the request waits for the manager', function () {
    [$managerUser, $manager] = lrPerson('manager');
    [$user, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);
    [$hrUser] = lrPerson('hr');
    [$adminUser] = lrPerson('admin');

    $request = leaveRequest($employee);
    $routing = lrRouting();

    expect($routing->decidableStage($managerUser, $request))->toBe('manager')
        ->and($routing->skipsManager($managerUser, $request))->toBeFalse()
        ->and($routing->decidableStage($hrUser, $request))->toBe('final')
        ->and($routing->skipsManager($hrUser, $request))->toBeTrue()
        ->and($routing->decidableStage($adminUser, $request))->toBe('final')
        ->and($routing->decidableStage($user, $request))->toBeNull()
        ->and($routing->isApprover($managerUser, $request))->toBeTrue()
        ->and($routing->isApprover($hrUser, $request))->toBeTrue()
        ->and($routing->isApprover($user, $request))->toBeFalse();

    $request->forceFill(['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id, 'manager_decided_at' => now()])->save();

    expect($routing->stage($request))->toBe('final')
        ->and($routing->decidableStage($managerUser, $request))->toBeNull()
        ->and($routing->isApprover($managerUser, $request))->toBeTrue()
        ->and($routing->decidableStage($hrUser, $request))->toBe('final')
        ->and($routing->skipsManager($hrUser, $request))->toBeFalse();

    foreach (['approved', 'rejected', 'cancelled'] as $status) {
        $request->status = $status;
        expect($routing->stage($request))->toBeNull()
            ->and($routing->decidableStage($hrUser, $request))->toBeNull();
    }
});

it('keeps the manager step when the HR step is switched off and reports the setting', function () {
    [, $manager] = lrPerson('manager');
    [, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);
    $routing = lrRouting();

    expect($routing->hrStepEnabled())->toBeTrue();

    Settings::set('leave', ['hr_step_enabled' => false]);

    expect($routing->hrStepEnabled())->toBeFalse()
        ->and($routing->stage(leaveRequest($employee)))->toBe('manager')
        ->and($routing->stage(leaveRequest($employee, ['status' => 'manager_approved'])))->toBe('final');
});

it('makes a manager approval final only with the HR step off and a requester who is not HR or Admin', function () {
    [, $manager] = lrPerson('manager');
    [, $staff] = lrPerson('employee', ['manager_id' => $manager->id]);
    [, $hrPerson] = lrPerson('hr', ['manager_id' => $manager->id]);
    [, $adminPerson] = lrPerson('admin', ['manager_id' => $manager->id]);

    $staffRequest = leaveRequest($staff, ['status' => 'manager_approved']);
    $hrRequest = leaveRequest($hrPerson, ['status' => 'manager_approved']);
    $adminRequest = leaveRequest($adminPerson, ['status' => 'manager_approved']);

    // HR step on: never final.
    foreach ([$staffRequest, $hrRequest, $adminRequest] as $request) {
        expect(lrRouting()->managerApprovalIsFinal($request))->toBeFalse();
    }

    Settings::set('leave', ['hr_step_enabled' => false]);

    expect(lrRouting()->managerApprovalIsFinal($staffRequest))->toBeTrue()
        ->and(lrRouting()->managerApprovalIsFinal($hrRequest))->toBeFalse()
        ->and(lrRouting()->managerApprovalIsFinal($adminRequest))->toBeFalse();
});

it('routes an HR person with a Manager-role manager: manager step first, then an Admin decides the final step', function () {
    [$managerUser, $manager] = lrPerson('manager');
    [$hrUser, $hrPerson] = lrPerson('hr', ['manager_id' => $manager->id]);
    [$otherHr] = lrPerson('hr');
    [$adminUser] = lrPerson('admin');

    $request = leaveRequest($hrPerson);
    $routing = lrRouting();

    expect($routing->stage($request))->toBe('manager')
        ->and($routing->decidableStage($managerUser, $request))->toBe('manager')
        ->and($routing->decidableStage($otherHr, $request))->toBeNull()
        ->and($routing->decidableStage($hrUser, $request))->toBeNull()
        ->and($routing->decidableStage($adminUser, $request))->toBe('final');

    $request->forceFill(['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id, 'manager_decided_at' => now()])->save();

    expect($routing->stage($request))->toBe('final')
        ->and($routing->decidableStage($managerUser, $request))->toBeNull()
        ->and($routing->decidableStage($otherHr, $request))->toBeNull()
        ->and($routing->decidableStage($adminUser, $request))->toBe('final')
        ->and($routing->managerApprovalIsFinal($request))->toBeFalse();
});

it('with the HR step off, still leaves the final step of an HR or Admin requester to an Admin', function () {
    Settings::set('leave', ['hr_step_enabled' => false]);

    [$managerUser, $manager] = lrPerson('manager');
    [, $hrPerson] = lrPerson('hr', ['manager_id' => $manager->id]);
    [$adminUser, $adminPerson] = lrPerson('admin', ['manager_id' => $manager->id]);
    [$secondAdmin] = lrPerson('admin');
    [$otherHr] = lrPerson('hr');

    foreach ([$hrPerson, $adminPerson] as $person) {
        $request = leaveRequest($person, ['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id]);
        $routing = lrRouting();

        expect($routing->managerApprovalIsFinal($request))->toBeFalse()
            ->and($routing->stage($request))->toBe('final')
            ->and($routing->decidableStage($otherHr, $request))->toBeNull()
            ->and($routing->decidableStage($secondAdmin, $request))->toBe('final');
    }

    expect(lrRouting()->decidableStage($adminUser, leaveRequest($hrPerson, ['status' => 'manager_approved'])))->toBe('final');
});

it('primes manager logins for a list in one batch, with no query per row', function () {
    [, $managerA] = lrPerson('manager');
    [, $managerB] = lrPerson('manager');
    $requests = collect();

    foreach ([$managerA, $managerB, $managerA, $managerB] as $manager) {
        [, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);
        $requests->push(leaveRequest($employee));
    }

    $requests = LeaveRequest::query()->with('employee')->whereKey($requests->pluck('id'))->get();
    $routing = lrRouting();
    $routing->prime($requests);
    app(PermissionRegistrar::class)->getPermissions(); // the package's one-off global cache, not per row

    DB::enableQueryLog();
    DB::flushQueryLog();

    $stages = $requests->map(fn (LeaveRequest $r) => $routing->stage($r))->all();

    expect($stages)->toBe(['manager', 'manager', 'manager', 'manager'])
        ->and(DB::getQueryLog())->toBe([]);
});

// ---------------------------------------------------------------- who may decide the final step

it('never lets the requester decide any step', function () {
    [$hrUser, $hrPerson] = lrPerson('hr');
    [$managerUser, $manager] = lrPerson('manager');
    $manager->forceFill(['manager_id' => $hrPerson->id])->save();

    $routing = lrRouting();

    foreach ([[$hrUser, $hrPerson], [$managerUser, $manager]] as [$user, $employee]) {
        $request = leaveRequest($employee);

        expect($routing->decidableStage($user, $request))->toBeNull()
            ->and($routing->mayDecideFinal($user, $request))->toBeFalse()
            ->and($routing->isApprover($user, $request))->toBeFalse()
            ->and($routing->isRequester($user, $request))->toBeTrue();
    }
});

it('never lets the user who approved as manager also decide the final step', function () {
    [$managerUser, $manager] = lrPerson('manager');
    [, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);

    $request = leaveRequest($employee, ['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id]);

    // The manager is promoted to HR after approving: still not the final approver of this request.
    $managerUser->syncRoles(['hr']);
    $managerUser = $managerUser->fresh();

    expect(lrRouting()->mayDecideFinal($managerUser, $request))->toBeFalse()
        ->and(lrRouting()->decidableStage($managerUser, $request))->toBeNull()
        ->and(lrRouting()->isApprover($managerUser, $request))->toBeTrue();
});

it('lets only an Admin decide an HR person\'s request', function () {
    [, $hrPerson] = lrPerson('hr');
    [$otherHr] = lrPerson('hr');
    [$adminUser] = lrPerson('admin');

    $request = leaveRequest($hrPerson);
    $routing = lrRouting();

    expect($routing->stage($request))->toBe('final')
        ->and($routing->decidableStage($otherHr, $request))->toBeNull()
        ->and($routing->isApprover($otherHr, $request))->toBeFalse()
        ->and($routing->decidableStage($adminUser, $request))->toBe('final');
});

it('lets another Admin decide an Admin\'s request, or the requester only as the sole active Admin', function () {
    [$adminUser, $adminPerson] = lrPerson('admin');
    [$otherAdmin] = lrPerson('admin');
    [$hrUser] = lrPerson('hr');

    $request = leaveRequest($adminPerson);

    expect(lrRouting()->decidableStage($otherAdmin, $request))->toBe('final')
        ->and(lrRouting()->decidableStage($adminUser, $request))->toBeNull()
        ->and(lrRouting()->decidableStage($hrUser, $request))->toBeNull();

    $otherAdmin->forceFill(['is_active' => false])->save();

    expect(lrRouting()->decidableStage($adminUser, $request))->toBe('final')
        ->and(lrRouting()->isApprover($adminUser, $request))->toBeTrue()
        ->and(lrRouting()->decidableStage($hrUser, $request))->toBeNull();
});

it('lets a new manager see and decide a request made under the old one; the old manager loses it', function () {
    [$oldUser, $old] = lrPerson('manager');
    [$newUser, $new] = lrPerson('manager');
    [, $employee] = lrPerson('employee', ['manager_id' => $old->id]);

    $request = leaveRequest($employee);

    expect(lrRouting()->decidableStage($oldUser, $request))->toBe('manager');

    $employee->forceFill(['manager_id' => $new->id])->save();
    $request = $request->fresh();

    expect(lrRouting()->decidableStage($newUser, $request))->toBe('manager')
        ->and(lrRouting()->canSee($newUser, $request))->toBeTrue()
        ->and(lrRouting()->decidableStage($oldUser, $request))->toBeNull()
        ->and(lrRouting()->canSee($oldUser, $request))->toBeFalse();
});

// ---------------------------------------------------------------- scope

it('scopes requests by role: HR everyone, a Manager own and direct reports, an Employee own only', function () {
    [$managerUser, $manager] = lrPerson('manager');
    [$user, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);
    [, $outsider] = lrPerson('employee');
    [$hrUser] = lrPerson('hr');
    $noRecord = userWithRole('employee');

    $mine = leaveRequest($manager);
    $report = leaveRequest($employee);
    $theirs = leaveRequest($outsider);

    $ids = fn (User $u) => lrRouting()->scope(LeaveRequest::query(), $u)->orderBy('id')->pluck('id')->all();

    expect($ids($hrUser))->toBe([$mine->id, $report->id, $theirs->id])
        ->and($ids($managerUser))->toBe([$mine->id, $report->id])
        ->and($ids($user))->toBe([$report->id])
        ->and($ids($noRecord))->toBe([])
        ->and(lrRouting()->findVisible($managerUser, $theirs->id))->toBeNull()
        ->and(lrRouting()->findVisible($managerUser, $report->id)?->is($report))->toBeTrue()
        ->and(lrRouting()->canSee($user, $mine))->toBeFalse()
        ->and(lrRouting()->canSee($user, $report))->toBeTrue();
});

// ---------------------------------------------------------------- administering and cancelling

it('lets HR administer staff but not their own record or another HR person\'s; an Admin anyone', function () {
    [$hrUser, $hrPerson] = lrPerson('hr');
    [, $otherHr] = lrPerson('hr');
    [$adminUser, $adminPerson] = lrPerson('admin');
    [$managerUser, $manager] = lrPerson('manager');
    [, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);

    $routing = lrRouting();

    expect($routing->mayAdminister($hrUser, $employee))->toBeTrue()
        ->and($routing->mayAdminister($hrUser, $manager))->toBeTrue()
        ->and($routing->mayAdminister($hrUser, $hrPerson))->toBeFalse()
        ->and($routing->mayAdminister($hrUser, $otherHr))->toBeFalse()
        ->and($routing->mayAdminister($hrUser, $adminPerson))->toBeFalse()
        ->and($routing->mayAdminister($adminUser, $adminPerson))->toBeTrue()
        ->and($routing->mayAdminister($adminUser, $otherHr))->toBeTrue()
        ->and($routing->mayAdminister($managerUser, $employee))->toBeFalse();
});

it('lets the requester or an administrator cancel an open request', function () {
    [$managerUser, $manager] = lrPerson('manager');
    [$user, $employee] = lrPerson('employee', ['manager_id' => $manager->id]);
    [$hrUser] = lrPerson('hr');

    $routing = lrRouting();
    $open = leaveRequest($employee);

    expect($routing->canCancelOpen($user, $open))->toBeTrue()
        ->and($routing->canCancelOpen($hrUser, $open))->toBeTrue()
        ->and($routing->canCancelOpen($managerUser, $open))->toBeFalse()
        ->and($routing->canCancelOpen($user, leaveRequest($employee, ['status' => 'manager_approved'])))->toBeTrue()
        ->and($routing->canCancelOpen($user, leaveRequest($employee, ['status' => 'rejected'])))->toBeFalse();
});

it('lets only an administrator who is not the requester (or the sole Admin) cancel approved leave that still has days', function () {
    [$user, $employee] = lrPerson('employee');
    [$hrUser, $hrPerson] = lrPerson('hr');
    [$adminUser, $adminPerson] = lrPerson('admin');

    $routing = lrRouting();
    $approved = approvedLeave($employee, '2026-12-01', '2026-12-02');

    expect($routing->canCancelApproved($hrUser, $approved))->toBeTrue()
        ->and($routing->canCancelApproved($user, $approved))->toBeFalse()
        ->and($routing->earliestCancellableDate($approved))->toBe('2026-12-01');

    // HR's own approved leave: HR may not cancel it; an Admin may.
    $own = approvedLeave($hrPerson, '2026-12-07');
    expect($routing->canCancelApproved($hrUser, $own))->toBeFalse()
        ->and($routing->canCancelApproved($adminUser, $own))->toBeTrue();

    // The only active Admin may cancel their own approved leave (the live rule, as LeaveDecisions::cancelApproved).
    $adminOwn = approvedLeave($adminPerson, '2026-12-08');
    expect(lrRouting()->canCancelApproved($adminUser, $adminOwn))->toBeTrue();
    [$secondAdmin] = lrPerson('admin');
    expect(lrRouting()->canCancelApproved($adminUser, $adminOwn))->toBeFalse()
        ->and(lrRouting()->canCancelApproved($secondAdmin, $adminOwn))->toBeTrue();

    // No days left (all refunded): nothing to cancel.
    $approved->days()->delete();
    expect($routing->canCancelApproved($hrUser, $approved->fresh()))->toBeFalse()
        ->and($routing->earliestCancellableDate($approved->fresh()))->toBeNull()
        ->and($routing->canCancelApproved($hrUser, leaveRequest($employee)))->toBeFalse();
});

// ---------------------------------------------------------------- mail recipients

it('lists the active final approvers of an employee, never the requester or a disabled login', function () {
    [, $employee] = lrPerson('employee');
    [$hrUser, $hrPerson] = lrPerson('hr');
    [$disabledHr] = lrPerson('hr');
    $disabledHr->forceFill(['is_active' => false])->save();
    [$adminUser, $adminPerson] = lrPerson('admin');
    [$secondAdmin] = lrPerson('admin');

    $ids = fn ($users) => $users->pluck('id')->sort()->values()->all();
    $routing = lrRouting();

    expect($ids($routing->finalApprovers($employee)))->toBe(collect([$hrUser->id, $adminUser->id, $secondAdmin->id])->sort()->values()->all())
        ->and($ids($routing->finalApprovers($employee, $hrUser)))->toBe(collect([$adminUser->id, $secondAdmin->id])->sort()->values()->all())
        // An HR person's request goes to Admins only; an Admin's to the other Admins.
        ->and($ids($routing->finalApprovers($hrPerson)))->toBe(collect([$adminUser->id, $secondAdmin->id])->sort()->values()->all())
        ->and($ids($routing->finalApprovers($adminPerson)))->toBe([$secondAdmin->id]);
});
