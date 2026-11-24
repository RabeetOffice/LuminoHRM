<?php

use Illuminate\Support\Facades\File;

it('renders the dashboard for every role', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/dashboard')->assertOk()
        ->assertInertia(fn ($p) => $p->component('dashboard'));
})->with(['admin', 'hr', 'manager', 'employee']);

it('never mentions the reference product', function () {
    $files = collect(File::allFiles(resource_path()))->merge(File::allFiles(app_path()));
    expect($files->filter(fn ($f) => str_contains(strtolower($f->getContents()), 'workdo')))->toBeEmpty();
});

it('shares the company timezone from settings in the app prop', function () {
    $this->actingAs(userWithRole('employee'))->get('/dashboard')
        ->assertInertia(fn ($p) => $p->where('app.timezone', settings('company.timezone')));
});

it('shares exactly four user fields and no relation loaded on the signed-in user', function () {
    $user = userWithRole('admin');
    $user->load('roles'); // would be serialised if the model itself were shared

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn ($p) => $p
            ->has('auth.user', 4)
            ->where('auth.user.id', $user->id)
            ->where('auth.user.name', $user->name)
            ->where('auth.user.email', $user->email)
            ->has('auth.user.email_verified_at')
            ->missing('auth.user.roles')
            ->missing('auth.user.password')
            ->where('auth.role', 'admin'));
});
