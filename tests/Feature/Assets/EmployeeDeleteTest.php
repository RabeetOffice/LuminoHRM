<?php

use App\Actions\Employees\DeleteEmployee;
use App\Exceptions\AccountRuleViolation;
use App\Models\AssetAssignment;
use App\Models\Employee;
use App\Support\EmployeeAccess;
use Spatie\Activitylog\Models\Activity;

/*
| An employee who ever held an asset cannot be deleted: the history stays. Set the status instead.
*/

beforeEach(function () {
    $this->withoutVite();
});

it('has the exact refusal text', function () {
    expect(EmployeeAccess::ASSETS_MESSAGE)->toBe('This employee has asset history. Set the status to Inactive, Resigned or Terminated instead.');
});

it('refuses an employee with an open assignment', function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    assignedAsset($e);

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([EmployeeAccess::ASSETS_MESSAGE])
        ->and((new EmployeeAccess($admin))->canDelete($e))->toBeFalse();

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertSessionHas('error', EmployeeAccess::ASSETS_MESSAGE);
    expect(Employee::query()->find($e->id))->not->toBeNull();
});

it('refuses an employee whose only assignment is returned', function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    assignedAsset($e, ['returned_on' => '2026-11-22', 'return_condition' => 'good']);

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([EmployeeAccess::ASSETS_MESSAGE]);

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertSessionHas('error', EmployeeAccess::ASSETS_MESSAGE);
    expect(Employee::query()->find($e->id))->not->toBeNull()
        ->and(AssetAssignment::query()->where('employee_id', $e->id)->count())->toBe(1);
});

it('deletes an employee with no assignment', function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    assignedAsset(Employee::factory()->create()); // somebody else's

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([]);

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertRedirect('/employees')->assertSessionHas('success');
    expect(Employee::query()->find($e->id))->toBeNull();
});

it("refuses in the action when the assignment appears after the controller's check", function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    $stale = Employee::query()->find($e->id); // the controller's check saw no assignment ...
    assignedAsset($e);                        // ... then one was written

    $before = Activity::query()->where('description', 'Employee deleted')->count();

    expect(fn () => app(DeleteEmployee::class)->handle($stale, $admin))
        ->toThrow(AccountRuleViolation::class, EmployeeAccess::ASSETS_MESSAGE);

    expect(Employee::query()->find($e->id))->not->toBeNull()
        ->and(Activity::query()->where('description', 'Employee deleted')->count())->toBe($before);
});
