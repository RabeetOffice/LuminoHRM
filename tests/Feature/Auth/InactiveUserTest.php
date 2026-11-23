<?php

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
