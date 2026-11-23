<?php

use App\Models\Department;
use App\Models\Designation;

it('allows the same designation name in two departments but not twice in one', function () {
    $a = Department::create(['name' => 'A']);
    $b = Department::create(['name' => 'B']);
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $a->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $b->id])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'Manager', 'department_id' => $a->id])->assertSessionHasErrors('name');
});

it('lets hr create a designation', function () {
    $a = Department::create(['name' => 'A']);
    $this->actingAs(userWithRole('hr'))->post('/organization/designations', ['name' => 'Developer', 'department_id' => $a->id])->assertRedirect();
    expect(Designation::where('name', 'Developer')->where('department_id', $a->id)->exists())->toBeTrue();
});

it('ignores case, outer and inner whitespace when checking duplicates within a department', function () {
    $a = Department::create(['name' => 'A']);
    $a->designations()->create(['name' => 'Senior Developer']);
    $this->actingAs(userWithRole('admin'))
        ->post('/organization/designations', ['name' => '  senior   DEVELOPER ', 'department_id' => $a->id])
        ->assertSessionHasErrors('name');
});

it('requires an existing department', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'X'])->assertSessionHasErrors('department_id');
    $this->actingAs($admin)->post('/organization/designations', ['name' => 'X', 'department_id' => 9999])->assertSessionHasErrors('department_id');
    expect(Designation::count())->toBe(0);
});

it('rejects empty and over-long designation names', function () {
    $a = Department::create(['name' => 'A']);
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/designations', ['name' => '  ', 'department_id' => $a->id])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/organization/designations', ['name' => str_repeat('b', 101), 'department_id' => $a->id])->assertSessionHasErrors('name');
});

it('updates a designation, allows keeping its own name and rejects a sibling clash', function () {
    $a = Department::create(['name' => 'A']);
    $b = Department::create(['name' => 'B']);
    $g = $a->designations()->create(['name' => 'Lead']);
    $a->designations()->create(['name' => 'Intern']);
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put("/organization/designations/{$g->id}", ['name' => 'LEAD', 'department_id' => $a->id])->assertSessionHasNoErrors();
    expect($g->fresh()->name)->toBe('LEAD');

    $this->actingAs($admin)->put("/organization/designations/{$g->id}", ['name' => 'intern', 'department_id' => $a->id])->assertSessionHasErrors('name');

    $this->actingAs($admin)->put("/organization/designations/{$g->id}", ['name' => 'Lead', 'department_id' => $b->id])->assertSessionHasNoErrors();
    expect($g->fresh()->department_id)->toBe($b->id);
});

it('deletes a designation', function () {
    $a = Department::create(['name' => 'A']);
    $g = $a->designations()->create(['name' => 'Lead']);
    $this->actingAs(userWithRole('admin'))->delete("/organization/designations/{$g->id}")->assertSessionHas('success');
    expect($g->fresh())->toBeNull();
});

it('filters designations by department and carries the department name', function () {
    $a = Department::create(['name' => 'Alpha Dept']);
    $b = Department::create(['name' => 'Beta Dept']);
    $a->designations()->create(['name' => 'One']);
    $b->designations()->create(['name' => 'Two']);
    $b->designations()->create(['name' => 'Three']);

    $this->actingAs(userWithRole('admin'))->get("/organization?tab=designations&department_id={$b->id}")
        ->assertInertia(fn ($p) => $p
            ->has('designations.data', 2)
            ->where('designations.data.0.department_name', 'Beta Dept')
            ->where('filters.department_id', $b->id)
            ->where('counts.designations', 3)
            ->has('departmentOptions', 2));
});

it('shows the designation count on each department row', function () {
    $a = Department::create(['name' => 'Alpha Dept']);
    $a->designations()->create(['name' => 'One']);
    $a->designations()->create(['name' => 'Two']);
    $this->actingAs(userWithRole('admin'))->get('/organization')
        ->assertInertia(fn ($p) => $p->where('departments.data.0.designations_count', 2));
});
