<?php

namespace App\Support;

use App\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent creation of roles, permissions and (optionally) the first Admin.
 * Called by RolePermissionSeeder and by the data migration, so production gets
 * its access data from `migrate --force` without a manual seed step.
 */
class AccessSync
{
    public function run(): void
    {
        $this->syncRolesAndPermissions();
        $this->createFirstAdmin();
    }

    public function syncRolesAndPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $map = config('hrm.permissions');

        foreach (array_keys($map) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (config('hrm.roles') as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            $role->syncPermissions(
                array_keys(array_filter($map, fn (array $roles) => in_array($roleName, $roles, true))),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Creates the first Admin from HRM_ADMIN_EMAIL / HRM_ADMIN_PASSWORD, only when
     * no admin exists and both values are set. Never logs or prints the password.
     */
    public function createFirstAdmin(): void
    {
        $email = trim((string) config('hrm.admin.email'));
        $password = (string) config('hrm.admin.password');

        if ($email === '' || $password === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        if (User::role('admin')->exists()) {
            return;
        }

        // Never take over an existing account that is not an admin.
        if (User::where('email', $email)->exists()) {
            return;
        }

        $user = new User(['name' => 'Administrator', 'email' => $email, 'password' => $password]);
        $user->email_verified_at = now();
        $user->is_active = true;
        $user->save();
        $user->assignRole('admin');
    }
}
