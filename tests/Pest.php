<?php

use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Shift;
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
        'start_date' => null,
        'duplicate_punch_minutes' => 5,
        'day_cutoff_time' => '04:00',
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

/**
 * A new (non-default) shift with $attrs over the factory values (09:00-18:00, 15 min grace, 4 h half day, Sat/Sun off),
 * assigned to $employee when one is given.
 *
 * @param  array<string, mixed>  $attrs
 */
function shiftFor(?Employee $employee = null, array $attrs = []): Shift
{
    $shift = Shift::factory()->create($attrs);

    if ($employee !== null) {
        $employee->forceFill(['shift_id' => $shift->id])->save();
    }

    return $shift;
}

/**
 * Stores one punch as the machine would: $who is an employee (matched by their device_user_id, given one if they have
 * none) or a bare machine user ID (linked to the employee holding it, if any). $at is the naive "Y-m-d H:i[:s]" time.
 * $device defaults to the first device, created when there is none.
 */
function punch(Employee|string $who, string $at, ?Device $device = null): DevicePunch
{
    if ($who instanceof Employee) {
        if ($who->device_user_id === null || $who->device_user_id === '') {
            // The first number from 1000 + id up that no employee holds and no stored punch uses (collision-free).
            $candidate = 1000 + $who->id;

            while (Employee::query()->where('device_user_id', (string) $candidate)->exists()
                || DevicePunch::query()->where('device_user_id', (string) $candidate)->exists()) {
                $candidate++;
            }

            $who->forceFill(['device_user_id' => (string) $candidate])->save();
        }

        $employee = $who;
        $userId = (string) $who->device_user_id;
    } else {
        $userId = $who;
        $employee = Employee::query()->where('device_user_id', $who)->first();
    }

    $device ??= Device::query()->orderBy('id')->first() ?? Device::factory()->create();

    return DevicePunch::query()->create([
        'device_id' => $device->id,
        'device_user_id' => $userId,
        'employee_id' => $employee?->id,
        'punch_time' => $at,
        'verify_type' => '1',
        'status_code' => '0',
        'source' => 'device',
    ]);
}
