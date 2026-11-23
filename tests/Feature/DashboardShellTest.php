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
