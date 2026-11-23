<?php

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Role => sorted permission names it must hold, derived from config('hrm.permissions'). */
function expectedMap(): array
{
    $out = [];
    foreach (config('hrm.roles') as $role) {
        $out[$role] = collect(config('hrm.permissions'))
            ->filter(fn ($roles) => in_array($role, $roles, true))
            ->keys()->sort()->values()->all();
    }

    return $out;
}

function dbMap(): array
{
    return Role::with('permissions')->get()
        ->mapWithKeys(fn ($r) => [$r->name => $r->permissions->pluck('name')->sort()->values()->all()])
        ->all();
}

it('seeds four roles with the mapped permissions', function () {
    // The data migration already ran; start from nothing to prove the seeder itself.
    Role::query()->delete();
    Permission::query()->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->seed(RolePermissionSeeder::class);

    expect(Role::pluck('name')->sort()->values()->all())->toBe(['admin', 'employee', 'hr', 'manager']);
    expect(Permission::count())->toBe(count(config('hrm.permissions')));
    expect(Role::findByName('hr')->hasPermissionTo('payroll.run'))->toBeTrue();
    expect(Role::findByName('hr')->hasPermissionTo('payroll.approve'))->toBeFalse();
    expect(Role::findByName('manager')->permissions->pluck('name')->filter(fn ($p) => str_starts_with($p, 'payroll.')))->toBeEmpty();
    expect(Role::findByName('employee')->permissions)->toBeEmpty();
});

it('matches every cell of the permission map for all four roles', function () {
    $this->seed(RolePermissionSeeder::class);

    $expected = expectedMap();
    expect(array_keys($expected))->toBe(['admin', 'hr', 'manager', 'employee']);
    $actual = dbMap();
    foreach ($expected as $role => $perms) {
        expect($actual[$role])->toBe($perms, "role $role");
    }
    expect(count(config('hrm.permissions')))->toBe(25);
});

it('repairs a permission removed from a role when the sync runs again', function () {
    $this->seed(RolePermissionSeeder::class);
    Role::findByName('hr')->revokePermissionTo('payroll.run');
    Role::findByName('manager')->givePermissionTo('payroll.approve');
    expect(Role::findByName('hr')->hasPermissionTo('payroll.run'))->toBeFalse();

    $this->seed(RolePermissionSeeder::class);

    expect(dbMap())->toBe(expectedMap());
});

it('is safe to run the seeder twice', function () {
    $this->seed(RolePermissionSeeder::class);
    $this->seed(RolePermissionSeeder::class);
    expect(Role::count())->toBe(4);
    expect(Permission::count())->toBe(25);
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

    $admin = User::where('email', 'boss@example.com')->first();
    expect($admin)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue()
        ->and($admin->is_active)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->password)->not->toBe('a-long-secret-1')
        ->and(Hash::check('a-long-secret-1', $admin->password))->toBeTrue();

    config(['hrm.admin.email' => 'second@example.com']);
    $this->seed(RolePermissionSeeder::class);
    expect(User::where('email', 'second@example.com')->exists())->toBeFalse();
});

it('creates no admin when the env values are missing', function () {
    config(['hrm.admin.email' => 'boss@example.com', 'hrm.admin.password' => null]);
    $this->seed(RolePermissionSeeder::class);
    expect(User::count())->toBe(0);
});

it('does not take over an existing non-admin account with the same email', function () {
    $existing = User::factory()->create(['email' => 'boss@example.com']);
    $oldHash = $existing->password;
    config(['hrm.admin.email' => 'Boss@Example.com', 'hrm.admin.password' => 'a-long-secret-1']);

    $this->seed(RolePermissionSeeder::class);

    expect(User::count())->toBe(1)
        ->and($existing->fresh()->hasRole('admin'))->toBeFalse()
        ->and($existing->fresh()->password)->toBe($oldHash);
});

/** Runs hrm:sync-access and returns [exitCode, output]. */
function syncAccess(): array
{
    $code = Artisan::call('hrm:sync-access');

    return [$code, trim(Artisan::output())];
}

it('reports each admin state on one safe line from hrm:sync-access', function () {
    [$code, $line] = syncAccess();
    expect($code)->toBe(0)->and($line)->toBe('access_sync roles=4 permissions=25 admin=skipped_no_env');

    config(['hrm.admin.email' => 'not-an-email', 'hrm.admin.password' => 'a-long-secret-1']);
    [$code, $line] = syncAccess();
    expect($code)->toBe(0)->and($line)->toBe('access_sync roles=4 permissions=25 admin=skipped_invalid_email');

    User::factory()->create(['email' => 'taken@example.com']);
    config(['hrm.admin.email' => 'taken@example.com']);
    [$code, $line] = syncAccess();
    expect($code)->toBe(0)->and($line)->toBe('access_sync roles=4 permissions=25 admin=skipped_email_in_use');

    config(['hrm.admin.email' => 'boss@example.com']);
    [$code, $line] = syncAccess();
    expect($code)->toBe(0)->and($line)->toBe('access_sync roles=4 permissions=25 admin=created');
    expect($line)->not->toContain('boss@example.com')->not->toContain('a-long-secret-1')
        ->and($line)->toMatch('/^[a-z0-9 =_]+$/');

    [$code, $line] = syncAccess();
    expect($code)->toBe(0)->and($line)->toBe('access_sync roles=4 permissions=25 admin=exists');
    expect(User::role('admin')->count())->toBe(1);
});

it('is a no-op when hrm:sync-access runs twice', function () {
    config(['hrm.admin.email' => 'boss@example.com', 'hrm.admin.password' => 'a-long-secret-1']);
    syncAccess();
    $before = [User::count(), Role::count(), Permission::count(), dbMap()];
    syncAccess();
    expect([User::count(), Role::count(), Permission::count(), dbMap()])->toBe($before);
});
