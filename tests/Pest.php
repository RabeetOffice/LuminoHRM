<?php

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
function userWithRole(string $role): App\Models\User
{
    test()->seed(Database\Seeders\RolePermissionSeeder::class);

    $user = App\Models\User::factory()->create();
    $user->assignRole($role);

    return $user;
}
