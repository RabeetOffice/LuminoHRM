<?php

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('refuses to delete a department that employees belong to', function () {
    $department = Department::factory()->create();
    // The designation belongs to another department, so only the employee stands in the way.
    $e = Employee::factory()->create(['department_id' => $department->id, 'designation_id' => Designation::factory()->create()->id]);
    $admin = userWithRole('admin');

    $this->actingAs($admin)->delete("/organization/departments/{$e->department_id}")
        ->assertRedirect()
        ->assertSessionHas('error', "Move this department's employees first.");

    expect(Department::find($e->department_id))->not->toBeNull();
});

it('refuses to delete a designation that employees hold', function () {
    $e = Employee::factory()->create();
    $admin = userWithRole('admin');

    $this->actingAs($admin)->delete("/organization/designations/{$e->designation_id}")
        ->assertRedirect()
        ->assertSessionHas('error', "Move this designation's employees first.");

    expect(Designation::find($e->designation_id))->not->toBeNull();
});

it('checks the blocker before the database', function () {
    $e = Employee::factory()->create();

    expect($e->designation->deleteBlocker())->toBe("Move this designation's employees first.");

    $empty = Department::factory()->create();
    expect($empty->deleteBlocker())->toBeNull();
});

it('still deletes a designation and a department nobody uses', function () {
    $admin = userWithRole('admin');
    $department = Department::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $department->id]);

    $this->actingAs($admin)->delete("/organization/designations/{$designation->id}")->assertSessionHas('success');
    $this->actingAs($admin)->delete("/organization/departments/{$department->id}")->assertSessionHas('success');

    expect(Department::find($department->id))->toBeNull();
});

it('shows the employee message when an employee appears after the check and the foreign key refuses a designation delete', function () {
    $e = Employee::factory()->create();
    $designation = Designation::factory()->create(['department_id' => $e->department_id]);
    $admin = userWithRole('admin');

    // Runs after deleteBlocker() has passed, just before the DELETE statement; the employee commits "elsewhere".
    Designation::deleting(function (Designation $d) use ($e) {
        DB::table('employees')->where('id', $e->id)->update(['designation_id' => $d->id]);
    });

    try {
        $this->actingAs($admin)->delete("/organization/designations/{$designation->id}")
            ->assertRedirect()
            ->assertSessionHas('error');
    } finally {
        Designation::flushEventListeners();
    }

    expect(Designation::find($designation->id))->not->toBeNull();
});

it('does not hide an unrelated database error behind the friendly message', function () {
    $department = Department::factory()->create();
    $admin = userWithRole('admin');

    Department::deleting(function () {
        DB::statement('SELECT * FROM table_that_does_not_exist');
    });

    try {
        $this->withoutExceptionHandling();
        expect(fn () => $this->actingAs($admin)->delete("/organization/departments/{$department->id}"))
            ->toThrow(QueryException::class);
    } finally {
        Department::flushEventListeners();
    }
});
