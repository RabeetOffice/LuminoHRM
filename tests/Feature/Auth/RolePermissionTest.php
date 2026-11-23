<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

it('seeds four roles with the mapped permissions', function () {
    $this->seed(RolePermissionSeeder::class);
    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'employee', 'hr', 'manager']);
    expect(Role::findByName('hr')->hasPermissionTo('payroll.run'))->toBeTrue();
    expect(Role::findByName('hr')->hasPermissionTo('payroll.approve'))->toBeFalse();
    expect(Role::findByName('manager')->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, 'payroll.')))->toBeEmpty();
    expect(Role::findByName('employee')->permissions)->toBeEmpty();
});

it('is safe to run the seeder twice', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
    expect(Role::count())->toBe(4);
});

it('gives admin every permission in the map', function () {
    $this->seed(RolePermissionSeeder::class);
    $all = array_keys(config('hrm.permissions'));
    expect(Role::findByName('admin')->permissions->pluck('name')->sort()->values()->all())
        ->toBe(collect($all)->sort()->values()->all());
});

it('shares role and permissions with the page', function () {
    $this->actingAs(userWithRole('hr'))->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('auth.role', 'hr')->where('auth.permissions', fn ($list) => collect($list)->contains('org.manage')));
});

it('does not expose public registration', function () {
    $this->get('/register')->assertNotFound();
    $this->post('/register', [])->assertNotFound();
});

it('creates the first admin from config only when none exists', function () {
    config(['hrm.admin.email' => 'boss@example.com', 'hrm.admin.password' => 'a-long-secret-1']);
    $this->seed(RolePermissionSeeder::class);

    $admin = App\Models\User::where('email', 'boss@example.com')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull();

    config(['hrm.admin.email' => 'second@example.com']);
    $this->seed(RolePermissionSeeder::class);
    expect(App\Models\User::where('email', 'second@example.com')->exists())->toBeFalse();
});

it('creates no admin when the env values are missing', function () {
    config(['hrm.admin.email' => 'boss@example.com', 'hrm.admin.password' => null]);
    $this->seed(RolePermissionSeeder::class);
    expect(App\Models\User::count())->toBe(0);
});
