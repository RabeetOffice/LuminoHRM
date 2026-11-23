<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

it('refuses sign-in for an inactive user', function () {
    $u = userWithRole('employee');
    $u->update(['is_active' => false]);
    $this->post('/login', ['email' => $u->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been disabled. Contact HR.']);
    $this->assertGuest();
});

it('signs out a user deactivated mid-session on their next request', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->get('/dashboard')->assertOk();
    $u->update(['is_active' => false]);
    $this->get('/dashboard')->assertRedirect('/login');
    $this->assertGuest();
});

it('records the last sign-in time', function () {
    $u = userWithRole('employee');
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    expect($u->fresh()->last_login_at)->not->toBeNull();
});

it('does not record a sign-in for an inactive user', function () {
    $u = userWithRole('employee');
    $u->update(['is_active' => false]);
    $this->post('/login', ['email' => $u->email, 'password' => 'password']);
    expect($u->fresh()->last_login_at)->toBeNull();
});

it('answers a deactivated user Inertia PATCH with a 303 to the login page and signs them out', function () {
    $u = userWithRole('employee');
    $this->actingAs($u)->get('/dashboard')->assertOk();
    $u->update(['is_active' => false]);

    $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->patch('/settings/profile', ['name' => 'x', 'email' => $u->email])
        ->assertStatus(303)
        ->assertRedirect('/login');
    $this->assertGuest();
});

it('refuses an inactive user on the two-factor path (Fortify calls the same callback)', function () {
    $u = User::factory()->withTwoFactor()->create(['is_active' => false]);
    $u->assignRole('employee');

    $this->post('/login', ['email' => $u->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => 'This account has been disabled. Contact HR.'])
        ->assertSessionMissing('login.id');
    $this->assertGuest();
});

it('does not record last_login_at when Login fires outside the sign-in routes (remember-cookie restore)', function () {
    $u = userWithRole('employee');
    Event::dispatch(new Login('web', $u, true));
    expect($u->fresh()->last_login_at)->toBeNull();
});

it('rehashes the stored password at sign-in when the cost changed', function () {
    $u = userWithRole('employee');
    $old = $u->fresh()->password;
    Hash::driver()->setRounds(6);

    $this->post('/login', ['email' => $u->email, 'password' => 'password']);

    expect($u->fresh()->password)->not->toBe($old);
});
