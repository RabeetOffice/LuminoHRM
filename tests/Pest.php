<?php

use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature and Unit tests extend the application's base test case so that
| Pest tests and the kit's existing class-based tests share one bootstrap.
|
*/

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

/**
 * Seeds roles and permissions (idempotent) and returns an active, verified user holding $role.
 */
function userWithRole(string $role): User
{
    test()->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

/**
 * Creates an employee record for $user (same email and name) and links the account to it.
 *
 * @param  array<string, mixed>  $attrs  Overrides for the employee row
 */
function employeeFor(User $user, array $attrs = []): Employee
{
    $name = explode(' ', trim($user->name).' ', 2);

    $employee = Employee::factory()->create(array_merge([
        'first_name' => $name[0] !== '' ? $name[0] : 'Test',
        'last_name' => trim($name[1]) !== '' ? trim($name[1]) : 'User',
        'email' => $user->email,
    ], $attrs));

    $user->forceFill(['employee_id' => $employee->id])->save();

    return $employee;
}

/**
 * A complete valid payload for PUT /hrm-settings/attendance, merged with $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validAttendance(array $overrides = []): array
{
    return array_merge([
        'shift_start' => '09:00',
        'shift_end' => '18:00',
        'grace_minutes' => 15,
        'weekly_offs' => ['saturday', 'sunday'],
        'late_rule_count' => 3,
        'half_day_hours' => 4,
        'overtime_enabled' => false,
        'overtime_min_minutes' => 30,
    ], $overrides);
}

/**
 * A complete valid payload for PUT /hrm-settings/email, merged with $overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validEmail(array $overrides = []): array
{
    return array_merge([
        'host' => 'smtp.example.com',
        'port' => 587,
        'username' => 'mailer',
        'password' => '',
        'encryption' => 'tls',
        'from_address' => 'hr@example.com',
        'from_name' => 'Lumino HR',
    ], $overrides);
}
