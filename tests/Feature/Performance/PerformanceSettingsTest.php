<?php

use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite();
});

it('defaults to quarterly reviews with self-review on', function () {
    expect(settings('performance.review_period'))->toBe('quarterly')
        ->and(settings('performance.self_review_enabled'))->toBeTrue();
});

it('shows the Performance group to Admin and HR with the stored values', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/hrm-settings?group=performance')
        ->assertInertia(fn ($p) => $p->where('active', 'performance')
            ->where('groups', fn ($groups) => collect($groups)->contains('performance'))
            ->where('values.performance', ['review_period' => 'quarterly', 'self_review_enabled' => true]));
})->with(['admin', 'hr']);

it('lets Admin and HR save the group and writes one settings entry', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->put('/hrm-settings/performance', ['review_period' => 'monthly', 'self_review_enabled' => false])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(settings('performance.review_period'))->toBe('monthly')
        ->and(settings('performance.self_review_enabled'))->toBeFalse();

    $entries = Activity::query()->where('log_name', 'settings')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->description)->toBe('Performance settings changed')
        ->and($entries->first()->causer_id)->toBe($user->id);
})->with(['admin', 'hr']);

it('saves one field and keeps the other', function () {
    $this->actingAs(userWithRole('hr'))->put('/hrm-settings/performance', ['self_review_enabled' => false])->assertSessionHasNoErrors();

    expect(settings('performance.review_period'))->toBe('quarterly')
        ->and(settings('performance.self_review_enabled'))->toBeFalse();
});

it('refuses an unknown period and a non-boolean flag', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put('/hrm-settings/performance', ['review_period' => 'weekly', 'self_review_enabled' => true])->assertSessionHasErrors('review_period');
    $this->actingAs($hr)->put('/hrm-settings/performance', ['review_period' => 'monthly', 'self_review_enabled' => 'maybe'])->assertSessionHasErrors('self_review_enabled');

    expect(settings('performance.review_period'))->toBe('quarterly');
});

it('keeps Manager and Employee out of the group and its save', function (string $role) {
    $user = userWithRole($role);

    $this->actingAs($user)->get('/hrm-settings?group=performance')->assertForbidden();
    $this->actingAs($user)->put('/hrm-settings/performance', ['review_period' => 'monthly', 'self_review_enabled' => false])->assertForbidden();

    expect(settings('performance.review_period'))->toBe('quarterly');
})->with(['manager', 'employee']);

it('keeps out a person who holds the attendance and leave settings permission but not performance.manage', function () {
    // settings.attendance-leave opens the attendance and leave groups; the performance group follows performance.manage alone.
    $user = userWithRole('employee');
    $user->givePermissionTo('settings.attendance-leave');

    expect($user->can('settings.attendance-leave'))->toBeTrue()->and($user->can('performance.manage'))->toBeFalse();

    // The page opens for the groups the person may edit and never lists or shows performance, even when asked for it by name.
    $this->actingAs($user)->get('/hrm-settings?group=performance')
        ->assertInertia(fn ($p) => $p->where('groups', fn ($groups) => ! collect($groups)->contains('performance') && collect($groups)->contains('attendance'))
            ->where('active', fn ($active) => $active !== 'performance')
            ->missing('values.performance'));
    $this->actingAs($user)->put('/hrm-settings/performance', ['review_period' => 'monthly', 'self_review_enabled' => false])->assertForbidden();

    expect(settings('performance.review_period'))->toBe('quarterly')
        ->and(settings('performance.self_review_enabled'))->toBeTrue();
});
