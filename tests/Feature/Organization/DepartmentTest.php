<?php

use App\Models\Department;
use App\Models\Designation;

it('lets admin and hr manage departments', function (string $role) {
    $this->actingAs(userWithRole($role))->post('/organization/departments', ['name' => 'Engineering'])->assertRedirect();
    expect(Department::where('name', 'Engineering')->exists())->toBeTrue();
})->with(['admin', 'hr']);

it('forbids managers and employees on every organization route', function (string $role) {
    $user = userWithRole($role);
    $d = Department::create(['name' => 'Keep']);
    $g = $d->designations()->create(['name' => 'Keep']);

    $this->actingAs($user)->get('/organization')->assertForbidden();
    $this->actingAs($user)->post('/organization/departments', ['name' => 'X'])->assertForbidden();
    $this->actingAs($user)->put("/organization/departments/{$d->id}", ['name' => 'Y'])->assertForbidden();
    $this->actingAs($user)->delete("/organization/departments/{$d->id}")->assertForbidden();
    $this->actingAs($user)->post('/organization/designations', ['name' => 'X', 'department_id' => $d->id])->assertForbidden();
    $this->actingAs($user)->put("/organization/designations/{$g->id}", ['name' => 'Y', 'department_id' => $d->id])->assertForbidden();
    $this->actingAs($user)->delete("/organization/designations/{$g->id}")->assertForbidden();

    expect(Department::count())->toBe(1)
        ->and($d->fresh()->name)->toBe('Keep')
        ->and($g->fresh()->name)->toBe('Keep');
})->with(['manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $this->get('/organization')->assertRedirect('/login');
    $this->post('/organization/departments', ['name' => 'X'])->assertRedirect('/login');
});

it('rejects a duplicate department name, ignoring case and outer spaces', function () {
    Department::create(['name' => 'Engineering']);
    $this->actingAs(userWithRole('admin'))->post('/organization/departments', ['name' => '  engineering '])->assertSessionHasErrors('name');
});

it('treats inner runs of whitespace as one space', function () {
    Department::create(['name' => 'Customer Support']);
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/departments', ['name' => 'customer   support'])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/organization/departments', ['name' => " Quality \t  Assurance "]);
    expect(Department::where('name', 'Quality Assurance')->exists())->toBeTrue();
});

it('rejects empty and over-long names', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/organization/departments', ['name' => '   '])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/organization/departments', ['name' => str_repeat('a', 101)])->assertSessionHasErrors('name');
    $this->actingAs($admin)->post('/organization/departments', ['name' => str_repeat('a', 100)])->assertSessionHasNoErrors();
});

it('renames a department and allows keeping its own name', function () {
    $d = Department::create(['name' => 'Engineering']);
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'ENGINEERING'])->assertSessionHasNoErrors();
    expect($d->fresh()->name)->toBe('ENGINEERING');
    Department::create(['name' => 'Sales']);
    $this->actingAs($admin)->put("/organization/departments/{$d->id}", ['name' => 'sales'])->assertSessionHasErrors('name');
});

it('deletes an empty department', function () {
    $d = Department::create(['name' => 'Engineering']);
    $this->actingAs(userWithRole('admin'))->delete("/organization/departments/{$d->id}")->assertSessionHas('success');
    expect($d->fresh())->toBeNull();
});

it('will not delete a department that still has designations', function () {
    $d = Department::create(['name' => 'Engineering']);
    $d->designations()->create(['name' => 'Developer']);
    $this->actingAs(userWithRole('admin'))->delete("/organization/departments/{$d->id}")
        ->assertSessionHas('error', 'Move or delete this department\'s designations first.');
    expect($d->fresh())->not->toBeNull();
});

it('searches and paginates the list', function () {
    Department::factory()->count(30)->create();
    Department::create(['name' => 'Zebra Team']);
    $this->actingAs(userWithRole('hr'))->get('/organization?search=zebra')
        ->assertInertia(fn ($p) => $p->has('departments.data', 1));
});

it('paginates departments and designations independently', function () {
    $d = Department::factory()->create();
    Department::factory()->count(24)->create();
    Designation::factory()->count(12)->create(['department_id' => $d->id]);

    $this->actingAs(userWithRole('admin'))->get('/organization?dept_page=2&desig_page=2')
        ->assertInertia(fn ($p) => $p
            ->component('organization/index')
            ->where('departments.current_page', 2)
            ->has('departments.data', 10)
            ->where('designations.current_page', 2)
            ->has('designations.data', 2)
            ->where('counts.departments', 25)
            ->where('counts.designations', 12));
});

it('limits rows per page to 10, 25 or 50 and falls back to 10', function (mixed $input, int $expected) {
    Department::factory()->count(60)->create();
    $this->actingAs(userWithRole('admin'))->get('/organization?per_page='.$input)
        ->assertInertia(fn ($p) => $p->where('departments.per_page', $expected)->where('filters.per_page', $expected));
})->with([[25, 25], [50, 50], [10, 10], [7, 10], [1000, 10], ['abc', 10]]);

it('keeps tab counts as totals while searching', function () {
    Department::factory()->count(5)->create();
    Department::create(['name' => 'Zebra Team']);
    $this->actingAs(userWithRole('admin'))->get('/organization?search=zebra')
        ->assertInertia(fn ($p) => $p->where('counts.departments', 6)->has('departments.data', 1));
});

it('applies search to the active tab only', function () {
    $d = Department::create(['name' => 'Operations']);
    $d->designations()->create(['name' => 'Zebra Keeper']);
    $this->actingAs(userWithRole('admin'))->get('/organization?tab=designations&search=zebra')
        ->assertInertia(fn ($p) => $p
            ->where('filters.tab', 'designations')
            ->has('designations.data', 1)
            ->has('departments.data', 1));
});

it('falls back to the departments tab for an unknown tab', function () {
    $this->actingAs(userWithRole('admin'))->get('/organization?tab=nonsense')
        ->assertInertia(fn ($p) => $p->where('filters.tab', 'departments'));
});

it('sorts departments by name', function () {
    Department::create(['name' => 'Beta']);
    Department::create(['name' => 'Alpha']);
    $this->actingAs(userWithRole('admin'))->get('/organization')
        ->assertInertia(fn ($p) => $p->where('departments.data.0.name', 'Alpha'));
});
