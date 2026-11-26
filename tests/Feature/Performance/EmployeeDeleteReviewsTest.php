<?php

use App\Actions\Employees\DeleteEmployee;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\Kpi;
use App\Support\EmployeeAccess;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/review-helpers.php';

/*
| An employee with a review (as the person reviewed or as the reviewer) or a personal KPI (active or not) cannot be
| deleted: the history stays. The restrict foreign keys are only the backstop (they would be a 500).
*/

beforeEach(function () {
    $this->withoutVite();
});

it('has the exact refusal text', function () {
    expect(EmployeeAccess::REVIEWS_MESSAGE)->toBe('This employee has performance reviews or KPIs. Set the status to Inactive, Resigned or Terminated instead.');
});

it('refuses an employee who has a review', function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    rvReview($e, 'self_review');

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([EmployeeAccess::REVIEWS_MESSAGE])
        ->and((new EmployeeAccess($admin))->canDelete($e))->toBeFalse();

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertSessionHas('error', EmployeeAccess::REVIEWS_MESSAGE);
    expect(Employee::query()->find($e->id))->not->toBeNull();
});

it('refuses an employee who only reviews others', function () {
    $admin = userWithRole('admin');
    $boss = Employee::factory()->create();
    $report = Employee::factory()->create();
    rvReview($report, 'manager_review', ['reviewer_id' => $boss->id]); // the report is not managed by the boss in the record

    expect((new EmployeeAccess($admin))->deleteBlockers($boss))->toBe([EmployeeAccess::REVIEWS_MESSAGE]);

    $this->actingAs($admin)->delete("/employees/{$boss->id}")->assertSessionHas('error', EmployeeAccess::REVIEWS_MESSAGE);
    expect(Employee::query()->find($boss->id))->not->toBeNull();
});

it('refuses an employee with a personal KPI, active or inactive', function (bool $active) {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    kpiRow(['employee' => $e, 'is_active' => $active]);

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([EmployeeAccess::REVIEWS_MESSAGE]);

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertSessionHas('error', EmployeeAccess::REVIEWS_MESSAGE);
    expect(Employee::query()->find($e->id))->not->toBeNull()
        ->and(Kpi::query()->where('employee_id', $e->id)->count())->toBe(1);
})->with([true, false]);

it('deletes an employee with no review and no personal KPI', function () {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    rvReview(Employee::factory()->create(), 'self_review'); // somebody else's
    kpiRow(['employee' => Employee::factory()->create()]);
    kpiRow(); // a designation KPI

    expect((new EmployeeAccess($admin))->deleteBlockers($e))->toBe([]);

    $this->actingAs($admin)->delete("/employees/{$e->id}")->assertRedirect('/employees')->assertSessionHas('success');
    expect(Employee::query()->find($e->id))->toBeNull();
});

it("refuses in the action when a review or KPI appears after the controller's check", function (string $kind) {
    $admin = userWithRole('admin');
    $e = Employee::factory()->create();
    $stale = Employee::query()->find($e->id);

    if ($kind === 'review') {
        rvReview($e, 'self_review');
    } else {
        kpiRow(['employee' => $e]);
    }

    $before = Activity::query()->where('description', 'Employee deleted')->count();

    expect(fn () => app(DeleteEmployee::class)->handle($stale, $admin))
        ->toThrow(AccountRuleViolation::class, EmployeeAccess::REVIEWS_MESSAGE);

    expect(Employee::query()->find($e->id))->not->toBeNull()
        ->and(Activity::query()->where('description', 'Employee deleted')->count())->toBe($before);
})->with(['review', 'kpi']);
