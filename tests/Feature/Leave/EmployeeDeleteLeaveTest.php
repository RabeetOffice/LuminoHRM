<?php

use App\Actions\Employees\DeleteEmployee;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Support\EmployeeAccess;
use Carbon\CarbonImmutable;

/*
| Deleting a mistaken employee record while leave rows point at it: requests and hand-entered balance figures block the
| delete (status change instead); plain allocated balance rows go with the record.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
});

const EDL_REQUESTS = 'This employee has leave requests. Set the status to Inactive, Resigned or Terminated instead.';
const EDL_ADJUSTMENTS = 'This employee has leave balance adjustments. Set the status to Inactive, Resigned or Terminated instead.';

it('blocks the delete of an employee with any leave request, with a message instead of a database error', function (string $status) {
    $employee = Employee::factory()->create();
    leaveRequest($employee, ['status' => $status]);

    expect((new EmployeeAccess(userWithRole('admin')))->deleteBlockers($employee))->toBe([EDL_REQUESTS]);

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertSessionHas('error', EDL_REQUESTS);

    expect(Employee::find($employee->id))->not->toBeNull();
})->with(['pending', 'rejected', 'cancelled']);

it('blocks the delete of an employee with approved leave days', function () {
    $employee = Employee::factory()->create();
    approvedLeave($employee, '2026-12-01');

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertSessionHas('error', EDL_REQUESTS);

    expect(Employee::find($employee->id))->not->toBeNull();
});

it('deletes plain allocated balance rows with the employee', function () {
    $employee = Employee::factory()->create();
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Annual')->id, 'allocated' => 14]);
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Sick')->id, 'allocated' => 8]);
    $kept = LeaveBalance::factory()->create(['employee_id' => Employee::factory()->create()->id, 'leave_type_id' => leaveType('Annual')->id]);

    expect((new EmployeeAccess(userWithRole('admin')))->deleteBlockers($employee))->toBe([]);

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertRedirect('/employees')->assertSessionHas('success');

    expect(Employee::find($employee->id))->toBeNull()
        ->and(LeaveBalance::query()->where('employee_id', $employee->id)->exists())->toBeFalse()
        ->and($kept->fresh())->not->toBeNull();
});

it('blocks the delete while a balance row has a hand-entered adjustment or opening figure', function (array $figures) {
    $employee = Employee::factory()->create();
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Annual')->id, ...$figures]);

    expect((new EmployeeAccess(userWithRole('admin')))->deleteBlockers($employee))->toBe([EDL_ADJUSTMENTS]);

    $this->actingAs(userWithRole('admin'))->delete("/employees/{$employee->id}")
        ->assertSessionHas('error', EDL_ADJUSTMENTS);

    expect(Employee::find($employee->id))->not->toBeNull()
        ->and(LeaveBalance::query()->where('employee_id', $employee->id)->count())->toBe(1);
})->with([
    'adjustment' => [['adjustment' => -1.5]],
    'opening used' => [['opening_used' => 2]],
]);

it('re-checks the balance figures inside the delete action and rolls back', function () {
    $employee = Employee::factory()->create();
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Annual')->id, 'adjustment' => 1]);
    LeaveBalance::factory()->create(['employee_id' => $employee->id, 'leave_type_id' => leaveType('Sick')->id]);

    expect(fn () => app(DeleteEmployee::class)->handle($employee, userWithRole('admin')))
        ->toThrow(AccountRuleViolation::class, EDL_ADJUSTMENTS);

    expect(Employee::find($employee->id))->not->toBeNull()
        ->and(LeaveBalance::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('re-checks leave requests inside the delete action', function () {
    $employee = Employee::factory()->create();
    leaveRequest($employee);

    expect(fn () => app(DeleteEmployee::class)->handle($employee, userWithRole('admin')))
        ->toThrow(AccountRuleViolation::class, EDL_REQUESTS);

    expect(Employee::find($employee->id))->not->toBeNull();
});
