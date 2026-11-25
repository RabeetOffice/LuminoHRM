<?php

use App\Models\Employee;
use App\Models\PayrollItem;
use App\Models\User;
use App\Support\Payroll\PayrollAccess;

/** A user holding $role with an employee record of their own. */
function paPerson(string $role): User
{
    $user = userWithRole($role);
    employeeFor($user);

    return $user->refresh();
}

// ---------------------------------------------------------------- mayEditSalary

it('lets HR edit the salary of an Employee-role or Manager-role person and of someone with no login', function (string $role) {
    $hr = paPerson('hr');
    $target = paPerson($role)->employee;

    expect(PayrollAccess::mayEditSalary($hr, $target))->toBeTrue()
        ->and(PayrollAccess::mayEditSalary($hr, Employee::factory()->create()))->toBeTrue();
})->with(['employee', 'manager']);

it('never lets HR edit their own salary, an Admin\'s or another HR person\'s', function () {
    $hr = paPerson('hr');

    expect(PayrollAccess::mayEditSalary($hr, $hr->employee))->toBeFalse()
        ->and(PayrollAccess::mayEditSalary($hr, paPerson('admin')->employee))->toBeFalse()
        ->and(PayrollAccess::mayEditSalary($hr, paPerson('hr')->employee))->toBeFalse();
});

it('lets an Admin edit anyone\'s salary, their own included', function () {
    $admin = paPerson('admin');

    expect(PayrollAccess::mayEditSalary($admin, $admin->employee))->toBeTrue()
        ->and(PayrollAccess::mayEditSalary($admin, paPerson('admin')->employee))->toBeTrue()
        ->and(PayrollAccess::mayEditSalary($admin, paPerson('hr')->employee))->toBeTrue()
        ->and(PayrollAccess::mayEditSalary($admin, paPerson('employee')->employee))->toBeTrue();
});

it('never lets a Manager or an Employee edit any salary, their own included', function (string $role) {
    $user = paPerson($role);

    expect(PayrollAccess::mayEditSalary($user, $user->employee))->toBeFalse()
        ->and(PayrollAccess::mayEditSalary($user, paPerson('employee')->employee))->toBeFalse()
        ->and(PayrollAccess::mayEditSalary($user, Employee::factory()->create(['manager_id' => $user->employee_id])))->toBeFalse();
})->with(['manager', 'employee']);

// ---------------------------------------------------------------- approval

it('never lets HR approve a run', function () {
    $hr = paPerson('hr');
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id]);

    expect(PayrollAccess::mayApprove($hr, $run))->toBeFalse()
        ->and(PayrollAccess::mayApprove(paPerson('manager'), $run))->toBeFalse()
        ->and(PayrollAccess::mayApprove(paPerson('employee'), $run))->toBeFalse();
});

it('lets an Admin approve a run without their own line', function () {
    $admin = paPerson('admin');
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id]);

    expect(PayrollAccess::approvesOwn($admin, $run))->toBeFalse()
        ->and(PayrollAccess::mayApprove($admin, $run))->toBeTrue();
});

it('lets an Admin approve a run with their own line only while they are the sole active Admin', function () {
    $admin = paPerson('admin');
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $admin->employee_id]);

    expect(PayrollAccess::approvesOwn($admin, $run))->toBeTrue()
        ->and(PayrollAccess::soleActiveAdmin($admin))->toBeTrue()
        ->and(PayrollAccess::mayApprove($admin, $run))->toBeTrue();

    // A second, disabled Admin changes nothing.
    $disabled = userWithRole('admin');
    $disabled->forceFill(['is_active' => false])->save();
    expect(PayrollAccess::mayApprove($admin, $run))->toBeTrue();

    // A second active Admin: now the other Admin must approve.
    $other = userWithRole('admin');
    expect(PayrollAccess::soleActiveAdmin($admin))->toBeFalse()
        ->and(PayrollAccess::mayApprove($admin, $run))->toBeFalse()
        ->and(PayrollAccess::mayApprove($other, $run))->toBeTrue();
});

it('counts an excluded own line as own', function () {
    $admin = paPerson('admin');
    userWithRole('admin');
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id, 'employee_id' => $admin->employee_id, 'excluded' => true]);

    expect(PayrollAccess::approvesOwn($admin, $run))->toBeTrue()
        ->and(PayrollAccess::mayApprove($admin, $run))->toBeFalse();
});

it('treats a user without an employee record as never approving their own line', function () {
    $admin = userWithRole('admin');
    $run = payrollRun('2026-11');
    PayrollItem::factory()->create(['payroll_run_id' => $run->id]);

    expect(PayrollAccess::approvesOwn($admin, $run))->toBeFalse();
});

it('is never the sole active Admin without the admin role', function () {
    expect(PayrollAccess::soleActiveAdmin(paPerson('hr')))->toBeFalse();
});
