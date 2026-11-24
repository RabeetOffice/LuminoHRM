<?php

namespace App\Support;

use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
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
    public const ADMIN_CREATED = 'created';

    public const ADMIN_EXISTS = 'exists';

    public const ADMIN_NO_ENV = 'skipped_no_env';

    public const ADMIN_INVALID_EMAIL = 'skipped_invalid_email';

    public const ADMIN_EMAIL_IN_USE = 'skipped_email_in_use';

    /**
     * @return string one of the ADMIN_* constants (never contains the email or password)
     */
    public function run(): string
    {
        $this->syncRolesAndPermissions();

        return $this->createFirstAdmin();
    }

    public function syncRolesAndPermissions(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $map = config('hrm.permissions');

        foreach (array_keys($map) as $name) {
            Permission::findOrCreate((string) $name, 'web');
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
    public function createFirstAdmin(): string
    {
        if (User::role('admin')->exists()) {
            return self::ADMIN_EXISTS;
        }

        $email = trim((string) config('hrm.admin.email'));
        $password = (string) config('hrm.admin.password');

        if ($email === '' || $password === '') {
            return self::ADMIN_NO_ENV;
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return self::ADMIN_INVALID_EMAIL;
        }

        // Never take over an existing account that is not an admin.
        if (User::whereRaw('lower(email) = ?', [strtolower($email)])->exists()) {
            return self::ADMIN_EMAIL_IN_USE;
        }

        // One transaction with its audit entry (no causer: this runs from a console command or a migration).
        DB::transaction(function () use ($email, $password): void {
            $user = new User(['name' => 'Administrator', 'email' => $email, 'password' => $password]);
            $user->email_verified_at = now();
            $user->is_active = true;
            $user->save();
            $user->assignRole('admin');

            Audit::record('users', 'First admin created by system', $user, [
                'attributes' => ['name' => $user->name, 'email' => $user->email, 'role' => 'admin'],
            ]);
        });

        return self::ADMIN_CREATED;
    }
}
