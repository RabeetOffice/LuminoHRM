<?php

use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\EmployeeAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const ALL_GROUPS = ['basic', 'employment', 'contact', 'banking', 'documents', 'identity'];

/**
 * A small company: a manager M with a direct report R, R has a report I (indirect to M), and a stranger S.
 *
 * @return array{manager: Employee, report: Employee, indirect: Employee, stranger: Employee}
 */
function team(): array
{
    $manager = Employee::factory()->create();
    $report = Employee::factory()->create(['manager_id' => $manager->id]);
    $indirect = Employee::factory()->create(['manager_id' => $report->id]);
    $stranger = Employee::factory()->create();

    return compact('manager', 'report', 'indirect', 'stranger');
}

/** @return list<int> */
function visibleIds(User $user): array
{
    return (new EmployeeAccess($user))->scope(Employee::query())->orderBy('id')->pluck('id')->all();
}

it('lets admin and hr see everyone with every field group', function (string $role) {
    $t = team();
    $user = userWithRole($role);
    $access = new EmployeeAccess($user);

    expect(visibleIds($user))->toHaveCount(4);

    foreach ($t as $employee) {
        expect($access->canView($employee))->toBeTrue()
            ->and($access->fieldGroups($employee))->toBe(ALL_GROUPS);
    }
    expect($access->canManage())->toBeTrue();
})->with(['admin', 'hr']);

it('lets a manager see self and direct reports, with basic and employment only for a report', function () {
    $t = team();
    $user = userWithRole('manager');
    $t['manager']->forceFill([])->save();
    $user->forceFill(['employee_id' => $t['manager']->id])->save();
    $access = new EmployeeAccess($user->refresh());

    expect(visibleIds($user))->toBe([$t['manager']->id, $t['report']->id])
        ->and($access->canView($t['report']))->toBeTrue()
        ->and($access->fieldGroups($t['report']))->toBe(['basic', 'employment'])
        ->and($access->canView($t['indirect']))->toBeFalse()
        ->and($access->fieldGroups($t['indirect']))->toBe([])
        ->and($access->canView($t['stranger']))->toBeFalse()
        ->and($access->fieldGroups($t['stranger']))->toBe([])
        ->and($access->canView($t['manager']))->toBeTrue()
        ->and($access->fieldGroups($t['manager']))->toBe(ALL_GROUPS)
        ->and($access->canManage())->toBeFalse()
        ->and($access->canDelete($t['report']))->toBeFalse();
});

it('shows a manager with no employee record nothing', function () {
    team();
    $user = userWithRole('manager');
    $access = new EmployeeAccess($user);

    expect(visibleIds($user))->toBe([]);
    foreach (Employee::all() as $employee) {
        expect($access->canView($employee))->toBeFalse()->and($access->fieldGroups($employee))->toBe([]);
    }
});

it('shows a plain employee only their own record, with every field group', function () {
    $t = team();
    $user = userWithRole('employee');
    $mine = employeeFor($user, ['manager_id' => $t['manager']->id]);
    $access = new EmployeeAccess($user->refresh());

    expect(visibleIds($user))->toBe([$mine->id])
        ->and($access->canView($mine))->toBeTrue()
        ->and($access->fieldGroups($mine))->toBe(ALL_GROUPS)
        ->and($access->canView($t['manager']))->toBeFalse()
        ->and($access->fieldGroups($t['manager']))->toBe([])
        ->and($access->canView($t['report']))->toBeFalse()
        ->and($access->canManage())->toBeFalse()
        ->and($access->canDelete($mine))->toBeFalse();
});

it('shows a plain employee without a record nothing', function () {
    team();
    $user = userWithRole('employee');

    expect(visibleIds($user))->toBe([]);
});

it('does not let a manager see their manager, or a peer under the same manager', function () {
    $boss = Employee::factory()->create();
    $user = userWithRole('manager');
    $mine = employeeFor($user, ['manager_id' => $boss->id]);
    $peer = Employee::factory()->create(['manager_id' => $boss->id]);
    $access = new EmployeeAccess($user->refresh());

    expect(visibleIds($user))->toBe([$mine->id])
        ->and($access->canView($boss))->toBeFalse()
        ->and($access->canView($peer))->toBeFalse();
});

describe('delete rules', function () {
    it('lets an admin delete an employee with no reports and no sign-in history', function () {
        $admin = userWithRole('admin');
        $target = Employee::factory()->create();
        $access = new EmployeeAccess($admin);

        expect($access->deleteBlockers($target))->toBe([])->and($access->canDelete($target))->toBeTrue();
    });

    it('refuses while the employee has direct reports', function () {
        $admin = userWithRole('admin');
        $boss = Employee::factory()->create();
        Employee::factory()->create(['manager_id' => $boss->id]);
        $access = new EmployeeAccess($admin);

        expect($access->deleteBlockers($boss))->toBe(['This employee has direct reports. Reassign them first.'])
            ->and($access->canDelete($boss))->toBeFalse();
    });

    it('refuses once the linked account has signed in, and allows an account that never has', function () {
        $admin = userWithRole('admin');
        $access = new EmployeeAccess($admin);

        $neverIn = User::factory()->create(['last_login_at' => null]);
        $e1 = employeeFor($neverIn);
        expect($access->canDelete($e1))->toBeTrue();

        $signedIn = User::factory()->create(['last_login_at' => now()]);
        $e2 = employeeFor($signedIn);
        expect($access->deleteBlockers($e2))->toBe(['This employee has signed in before. Set the status to Inactive, Resigned or Terminated instead.'])
            ->and($access->canDelete($e2))->toBeFalse();
    });

    it('needs the delete permission: hr cannot delete even a clean record', function () {
        $hr = userWithRole('hr');
        $target = Employee::factory()->create();
        $access = new EmployeeAccess($hr);

        expect($access->deleteBlockers($target))->toBe([])->and($access->canDelete($target))->toBeFalse();
    });

    it('refuses while the employee has payroll history of any kind', function (Closure $history) {
        $admin = userWithRole('admin');
        $target = Employee::factory()->create();
        $history($target);
        $access = new EmployeeAccess($admin);

        expect($access->deleteBlockers($target))->toBe([EmployeeAccess::PAYROLL_MESSAGE])
            ->and(EmployeeAccess::PAYROLL_MESSAGE)->toBe('This employee has payroll history. Set the status to Inactive, Resigned or Terminated instead.')
            ->and($access->canDelete($target))->toBeFalse();

        $this->actingAs($admin)->delete("/employees/{$target->id}")->assertSessionHas('error', EmployeeAccess::PAYROLL_MESSAGE);
        expect(Employee::query()->find($target->id))->not->toBeNull();
    })->with([
        'a salary profile' => [fn (Employee $e) => salaryProfile($e)],
        'a loan' => [fn (Employee $e) => loanFor($e, 10000, 5000, '2026-11')],
        'opening tax figures' => [fn (Employee $e) => OpeningBalance::factory()->create(['employee_id' => $e->id])],
        'a payroll line' => [fn (Employee $e) => PayrollItem::factory()->create(['employee_id' => $e->id])],
    ]);

    it('lists every blocker, so later phases can add more', function () {
        $admin = userWithRole('admin');
        $boss = Employee::factory()->create();
        Employee::factory()->create(['manager_id' => $boss->id]);
        $user = User::factory()->create(['last_login_at' => now()]);
        $user->forceFill(['employee_id' => $boss->id])->save();

        expect((new EmployeeAccess($admin))->deleteBlockers($boss->refresh()))->toHaveCount(2);
    });
});

describe('string-typed ids (a driver that returns ids as strings)', function () {
    it('agrees with the list scope on own record and direct reports', function () {
        $t = team();
        $user = userWithRole('manager');
        $user->forceFill(['employee_id' => $t['manager']->id])->save();
        $access = new EmployeeAccess($user->refresh());

        // What a driver without native types hands back: every id as a string.
        $own = (clone $t['manager'])->setRawAttributes(['id' => (string) $t['manager']->id, 'manager_id' => null], true);
        $report = (clone $t['report'])->setRawAttributes(['id' => (string) $t['report']->id, 'manager_id' => (string) $t['manager']->id], true);
        $indirect = (clone $t['indirect'])->setRawAttributes(['id' => (string) $t['indirect']->id, 'manager_id' => (string) $t['report']->id], true);

        expect($access->canView($own))->toBeTrue()
            ->and($access->fieldGroups($own))->toBe(ALL_GROUPS)
            ->and($access->canView($report))->toBeTrue()
            ->and($access->fieldGroups($report))->toBe(['basic', 'employment'])
            ->and($access->canView($indirect))->toBeFalse();
    });
});
