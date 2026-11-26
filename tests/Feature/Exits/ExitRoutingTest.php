<?php

use App\Actions\Exits\DecideExit;
use App\Actions\Exits\RecordExit;
use App\Actions\Exits\SubmitResignation;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Exits\ExitRouting;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ExitRouting: who decides which step, who sees what, and the skip rules. "Now" is 2026-11-25 14:00 in Karachi.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @return array{0: User, 1: Employee} */
function xrPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

// ---------------------------------------------------------------- the manager step and its skip reasons

it('routes a pending exit to the reporting manager', function () {
    [, $manager] = xrPerson('manager');
    $managerUser = $manager->user;
    [, $employee] = xrPerson('employee', ['manager_id' => $manager->id]);
    $exit = resignationRow($employee);
    $routing = new ExitRouting;

    expect($routing->managerApprover($employee)?->is($managerUser))->toBeTrue()
        ->and($routing->stage($exit))->toBe('manager')
        ->and($routing->skipsManager($exit))->toBeFalse()
        ->and($routing->mayDecideManager($managerUser, $exit))->toBeTrue()
        ->and($routing->decidableStage($managerUser, $exit))->toBe('manager');
});

it('skips the manager step for each of its reasons', function (string $reason) {
    [$managerUser, $manager] = xrPerson('manager');

    [, $employee] = match ($reason) {
        'no manager' => xrPerson(),
        'manager is the person' => (function () {
            [$u, $e] = xrPerson();
            $e->forceFill(['manager_id' => $e->id])->save();

            return [$u, $e];
        })(),
        'manager has no login' => (function () {
            $noLogin = Employee::factory()->create();

            return xrPerson('employee', ['manager_id' => $noLogin->id]);
        })(),
        'manager login disabled' => (function () use ($managerUser, $manager) {
            $managerUser->forceFill(['is_active' => false])->save();

            return xrPerson('employee', ['manager_id' => $manager->id]);
        })(),
        'manager lacks exit.approve-team' => (function () {
            [, $plain] = xrPerson('employee');

            return xrPerson('employee', ['manager_id' => $plain->id]);
        })(),
        'manager holds exit.clearance' => (function () {
            [, $hrManager] = xrPerson('hr');

            return xrPerson('employee', ['manager_id' => $hrManager->id]);
        })(),
    };

    $employee = $employee->fresh();
    $exit = resignationRow($employee);
    $routing = new ExitRouting;

    expect($routing->managerApprover($employee))->toBeNull()
        ->and($routing->skipsManager($exit))->toBeTrue()
        ->and($routing->stage($exit))->toBe('final');
})->with([
    'no manager', 'manager is the person', 'manager has no login', 'manager login disabled',
    'manager lacks exit.approve-team', 'manager holds exit.clearance',
]);

it('skips the manager step for a termination and for an exit HR recorded', function () {
    [, $manager] = xrPerson('manager');
    [, $employee] = xrPerson('employee', ['manager_id' => $manager->id]);
    $routing = new ExitRouting;

    $termination = resignationRow($employee, ['type' => 'termination', 'status' => 'manager_approved', 'manager_skipped' => true]);
    expect($routing->skipsManager($termination))->toBeTrue()->and($routing->stage($termination))->toBe('final');

    $termination->status = 'rejected';
    $termination->save();

    $recorded = app(RecordExit::class)->handle(userWithRole('hr'), $employee, 'resignation', '2026-12-10', 'Reason.', '2026-11-25', false);
    expect($recorded->manager_skipped)->toBeTrue()
        ->and($recorded->status)->toBe('manager_approved')
        ->and($routing->stage($recorded))->toBe('final');
});

it('moves a waiting exit to HR by itself when the manager login is later disabled', function () {
    [$managerUser, $manager] = xrPerson('manager');
    [, $employee] = xrPerson('employee', ['manager_id' => $manager->id]);
    $exit = resignationRow($employee);

    expect((new ExitRouting)->stage($exit))->toBe('manager');

    $managerUser->forceFill(['is_active' => false])->save();

    expect((new ExitRouting)->stage($exit->fresh()->load('employee')))->toBe('final');
});

it('has no step once the exit is approved or ended', function () {
    [, $employee] = xrPerson();
    $routing = new ExitRouting;

    foreach (['approved', 'rejected', 'withdrawn', 'cancelled'] as $status) {
        $exit = Resignation::factory()->make(['employee_id' => $employee->id, 'status' => $status]);
        $exit->setRelation('employee', $employee);
        expect($routing->stage($exit))->toBeNull();
    }
});

// ---------------------------------------------------------------- the final step

it('lets HR decide anyone\'s exit except their own, and an HR person\'s only an Admin', function () {
    $routing = new ExitRouting;
    [$hr, $hrEmployee] = xrPerson('hr');
    [$otherHr] = xrPerson('hr');
    [$admin] = xrPerson('admin');
    [, $worker] = xrPerson();

    $workerExit = resignationRow($worker, ['status' => 'manager_approved']);
    expect($routing->mayDecideFinal($hr, $workerExit))->toBeTrue()
        ->and($routing->mayDecideFinal($admin, $workerExit))->toBeTrue();

    $hrExit = resignationRow($hrEmployee, ['status' => 'manager_approved']);
    expect($routing->mayDecideFinal($hr, $hrExit))->toBeFalse()
        ->and($routing->finalRefusal($hr, $hrExit))->toBe('You cannot decide your own exit.')
        ->and($routing->mayDecideFinal($otherHr, $hrExit))->toBeFalse()
        ->and($routing->finalRefusal($otherHr, $hrExit))->toBe('Only an Admin can decide the exit of an Admin or HR person.')
        ->and($routing->mayDecideFinal($admin, $hrExit))->toBeTrue();
});

it('lets another Admin decide an Admin\'s exit, never the Admin themselves when another is active', function () {
    $routing = new ExitRouting;
    [$admin, $adminEmployee] = xrPerson('admin');
    [$other] = xrPerson('admin');
    [$hr] = xrPerson('hr');
    $exit = resignationRow($adminEmployee, ['status' => 'manager_approved']);

    expect($routing->mayDecideFinal($other, $exit))->toBeTrue()
        ->and($routing->mayDecideFinal($admin, $exit))->toBeFalse()
        ->and($routing->finalRefusal($admin, $exit))->toBe('You cannot decide your own exit.')
        ->and($routing->mayDecideFinal($hr, $exit))->toBeFalse()
        ->and($routing->finalRefusal($hr, $exit))->toBe('Only an Admin can decide the exit of an Admin or HR person.');
});

it('lets the sole active Admin decide their own exit, audited as own', function () {
    [$admin] = xrPerson('admin');
    $exit = app(SubmitResignation::class)->handle($admin, '2026-12-20', 'Handing over the company.');

    expect((new ExitRouting)->mayDecideFinal($admin, $exit))->toBeTrue();

    $exit = app(DecideExit::class)->approve($admin, $exit);

    expect($exit->status)->toBe('approved')->and($exit->own_decision)->toBeTrue();
    $entry = Activity::where('log_name', 'exit')->where('description', 'Exit approved')->sole();
    expect($entry->properties['own'])->toBeTrue();
});

it('does not let a manager who approved the manager step decide the final step too', function () {
    [$managerUser, $manager] = xrPerson('manager');
    [, $employee] = xrPerson('employee', ['manager_id' => $manager->id]);
    $exit = resignationRow($employee, ['status' => 'manager_approved', 'manager_decided_by' => $managerUser->id]);
    $managerUser->givePermissionTo('exit.clearance');

    expect((new ExitRouting)->mayDecideFinal($managerUser->fresh(), $exit))->toBeFalse();
});

it('refuses an own decision through the action with the exact text', function () {
    [$admin] = xrPerson('admin');
    xrPerson('admin'); // a second active Admin: the exception no longer applies
    $exit = app(SubmitResignation::class)->handle($admin, '2026-12-20', 'Reason.');

    try {
        app(DecideExit::class)->approve($admin, $exit);
        $this->fail('Expected a refusal.');
    } catch (ValidationException $e) {
        expect($e->errors()['exit'])->toBe(['You cannot decide your own exit.']);
    }
});

// ---------------------------------------------------------------- recording and administering

it('lets HR record and manage for ordinary people, never for themselves, and an Admin for privileged ones', function () {
    $routing = new ExitRouting;
    [$hr, $hrEmployee] = xrPerson('hr');
    [$admin] = xrPerson('admin');
    [$manager] = xrPerson('manager');
    [, $worker] = xrPerson();
    $hrExit = resignationRow($hrEmployee, ['status' => 'approved']);
    $workerExit = resignationRow($worker, ['status' => 'approved']);

    expect($routing->mayRecordFor($hr, $worker))->toBeTrue()
        ->and($routing->mayRecordFor($hr, $hrEmployee))->toBeFalse()
        ->and($routing->mayRecordFor($admin, $hrEmployee))->toBeTrue()
        ->and($routing->mayRecordFor($manager, $worker))->toBeFalse()
        ->and($routing->mayManage($hr, $workerExit))->toBeTrue()
        ->and($routing->mayManage($hr, $hrExit))->toBeFalse()
        ->and($routing->mayManage($admin, $hrExit))->toBeTrue()
        ->and($routing->mayManage($manager, $workerExit))->toBeFalse();
});

// ---------------------------------------------------------------- scope

it('shows a manager only their direct reports\' exits, an employee only their own, HR everything', function () {
    [$managerUser, $manager] = xrPerson('manager');
    [, $report] = xrPerson('employee', ['manager_id' => $manager->id]);
    [$outsiderUser, $outsider] = xrPerson();
    [$hr] = xrPerson('hr');
    [$reportUser] = [$report->user];
    $reportExit = resignationRow($report);
    $outsiderExit = resignationRow($outsider);
    $routing = new ExitRouting;

    expect($routing->findVisible($managerUser, $reportExit->id)?->is($reportExit))->toBeTrue()
        ->and($routing->findVisible($managerUser, $outsiderExit->id))->toBeNull()
        ->and($routing->findVisible($reportUser, $reportExit->id)?->is($reportExit))->toBeTrue()
        ->and($routing->findVisible($reportUser, $outsiderExit->id))->toBeNull()
        ->and($routing->findVisible($outsiderUser, $reportExit->id))->toBeNull()
        ->and($routing->findVisible($hr, $outsiderExit->id)?->is($outsiderExit))->toBeTrue()
        ->and($routing->findVisible($hr, 999999))->toBeNull();

    expect($routing->scope(Resignation::query(), $managerUser)->pluck('id')->all())->toBe([$reportExit->id])
        ->and($routing->scope(Resignation::query(), $hr)->count())->toBe(2);

    // A login with no employee record and no exit permission sees nothing.
    $nobody = userWithRole('employee');
    expect($routing->scope(Resignation::query(), $nobody)->count())->toBe(0);
});

it('lists what waits for each person', function () {
    [$managerUser, $manager] = xrPerson('manager');
    [, $report] = xrPerson('employee', ['manager_id' => $manager->id]);
    [, $noManager] = xrPerson();
    [$hr, $hrEmployee] = xrPerson('hr');
    [$admin] = xrPerson('admin');

    $withManager = resignationRow($report); // pending, with the manager
    $atHr = resignationRow($noManager, ['status' => 'manager_approved', 'manager_skipped' => true]);
    $hrsOwn = resignationRow($hrEmployee, ['status' => 'manager_approved', 'manager_skipped' => true]);
    $approved = resignationRow(Employee::factory()->create(), ['status' => 'approved']);

    $routing = new ExitRouting;
    $ids = fn (User $u) => $routing->waitingFor($u)->pluck('id')->sort()->values()->all();

    // The manager decides only the manager step of their report; HR decides every open step except their own exit and
    // an HR person's; an Admin decides all three; an approved exit waits for nobody.
    expect($ids($managerUser))->toBe([$withManager->id])
        ->and($ids($hr))->toBe(collect([$withManager->id, $atHr->id])->sort()->values()->all())
        ->and($ids($admin))->toBe(collect([$withManager->id, $atHr->id, $hrsOwn->id])->sort()->values()->all())
        ->and(in_array($approved->id, $ids($admin), true))->toBeFalse();
});
