<?php

use App\Models\ActivityEntry;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * The first Admin is made from HRM_ADMIN_EMAIL / HRM_ADMIN_PASSWORD, a temporary password that lives in an env seed.
 * Until they pick their own, every page leads to "Welcome — choose your own password".
 */

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator
});

function mustChange(string $role = 'admin'): User
{
    $user = userWithRole($role);
    $user->forceFill(['must_change_password' => true])->save();

    return $user;
}

it('creates the first admin from the env with the flag set and the email verified', function () {
    config(['hrm.admin.email' => 'boss@example.com', 'hrm.admin.password' => 'a-long-secret-1']);
    $this->seed(RolePermissionSeeder::class);

    $admin = User::query()->where('email', 'boss@example.com')->sole();

    expect($admin->must_change_password)->toBeTrue()
        ->and($admin->email_verified_at)->not->toBeNull()
        ->and($admin->hasRole('admin'))->toBeTrue();
});

it('leaves every other account without the flag', function () {
    expect(User::factory()->create()->refresh()->must_change_password)->toBeFalse();
});

it('sends every page to the change screen while the flag is set', function (string $method, string $url) {
    $this->actingAs(mustChange())->call($method, $url)->assertRedirect('/first-login/password');
})->with([
    ['GET', '/dashboard'],
    ['GET', '/employees'],
    ['GET', '/system/updates'],
    ['GET', '/settings/profile'],
    ['PUT', '/settings/password'],
    ['POST', '/system/updates'],
]);

it('answers a JSON request with 403 while the flag is set', function () {
    $this->actingAs(mustChange())->getJson('/dashboard')->assertForbidden();
});

it('keeps the change screen and sign-out open', function () {
    $user = mustChange();

    $this->actingAs($user)->get('/first-login/password')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/first-login-password')->where('email', $user->email));

    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

it('rejects a wrong temporary password, a mismatch, a weak or an unchanged password', function (array $input, string $field) {
    $user = mustChange();

    $this->actingAs($user)->put('/first-login/password', $input)->assertSessionHasErrors($field);

    expect($user->refresh()->must_change_password)->toBeTrue()
        ->and(Hash::check('password', $user->password))->toBeTrue();
})->with([
    'wrong current' => [['current_password' => 'nope', 'password' => 'A-new-pass-123', 'password_confirmation' => 'A-new-pass-123'], 'current_password'],
    'mismatch' => [['current_password' => 'password', 'password' => 'A-new-pass-123', 'password_confirmation' => 'other-pass-123'], 'password'],
    'unchanged' => [['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'], 'password'],
    'empty' => [['current_password' => 'password', 'password' => '', 'password_confirmation' => ''], 'password'],
]);

it('saves the new password, clears the flag, audits it and opens the dashboard', function () {
    $user = mustChange();

    $this->actingAs($user)->put('/first-login/password', [
        'current_password' => 'password',
        'password' => 'A-new-pass-123',
        'password_confirmation' => 'A-new-pass-123',
    ])->assertRedirect('/dashboard')->assertSessionHas('success');

    $user->refresh();
    expect($user->must_change_password)->toBeFalse()
        ->and(Hash::check('A-new-pass-123', $user->password))->toBeTrue();

    $entry = ActivityEntry::query()->where('description', 'Password changed at first sign-in')->sole();
    expect($entry->log_name)->toBe('auth')
        ->and($entry->causer_id)->toBe($user->id)
        ->and(json_encode($entry->properties))->not->toContain('A-new-pass-123');

    $this->get('/dashboard')->assertOk();
    $this->get('/first-login/password')->assertRedirect('/dashboard');
});

it('sends an account without the flag from the change screen to the dashboard', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->get('/first-login/password')->assertRedirect('/dashboard');
    $this->actingAs($user)->put('/first-login/password', [
        'current_password' => 'password',
        'password' => 'A-new-pass-123',
        'password_confirmation' => 'A-new-pass-123',
    ])->assertRedirect('/dashboard');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

it('sends guests to sign-in', function () {
    $this->get('/first-login/password')->assertRedirect('/login');
});
