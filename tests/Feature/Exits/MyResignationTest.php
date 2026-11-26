<?php

use App\Models\Resignation;

require_once __DIR__.'/Support/screen-helpers.php';

// My Resignation: the form, submit, withdraw, the person's own downloads only when available, and nobody else's exit.

beforeEach(function () {
    $this->withoutVite();
    xsFreeze();
});

it('shows the form to an active employee with no open exit', function () {
    $p = xsPeople();

    $props = $this->actingAs($p['employee'])->get('/my/resignation')->assertOk()->inertiaProps();

    expect($props['can_submit'])->toBeTrue()
        ->and($props['current'])->toBeNull()
        ->and($props['form'])->toBe(['today' => '2026-11-25', 'max_days' => 365, 'notice_days' => 30])
        ->and($props['employee']['name'])->toBe('Sara Tester');
});

it('shows an empty state to an account with no employee record', function () {
    xsPeople();
    $user = userWithRole('employee');

    $props = $this->actingAs($user)->get('/my/resignation')->assertOk()->inertiaProps();

    expect($props['employee'])->toBeNull()
        ->and($props['can_submit'])->toBeFalse()
        ->and($props['why_not'])->toBe('Only employees with an employee record can resign.');
});

it('submits a resignation that waits for the manager', function () {
    $p = xsPeople();

    $this->actingAs($p['employee'])->post('/my/resignation', ['last_working_day' => '2026-12-25', 'reason' => 'A new chapter.'])
        ->assertRedirect();

    $exit = Resignation::query()->where('employee_id', $p['employeeEmp']->id)->firstOrFail();
    $props = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps();

    expect($exit->status)->toBe('pending')
        ->and($exit->submitted_on->format('Y-m-d'))->toBe('2026-11-25')
        ->and($props['can_submit'])->toBeFalse()
        ->and($props['why_not'])->toBe('You already have an open resignation.')
        ->and($props['current']['resignation']['stage'])->toBe('With manager')
        ->and($props['current']['can']['withdraw'])->toBeTrue();
});

it('shows the refusals of the form as errors', function () {
    $p = xsPeople();

    $this->actingAs($p['employee'])->post('/my/resignation', ['last_working_day' => '2026-11-01', 'reason' => 'Late.'])
        ->assertSessionHasErrors('last_working_day');
    $this->actingAs($p['employee'])->post('/my/resignation', ['last_working_day' => '2026-12-25', 'reason' => ''])
        ->assertSessionHasErrors('reason');
    $this->actingAs($p['employee'])->post('/my/resignation', ['last_working_day' => 'soon', 'reason' => 'x'])
        ->assertSessionHasErrors('last_working_day');

    expect(Resignation::query()->count())->toBe(0);
});

it('refuses a second resignation while one is open', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp']);

    $this->actingAs($p['employee'])->post('/my/resignation', ['last_working_day' => '2026-12-25', 'reason' => 'Again.'])
        ->assertSessionHasErrors('exit');
});

it('lets the employee withdraw before the final approval and not after', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $this->actingAs($p['employee'])->post("/my/resignation/{$exit->id}/withdraw")->assertRedirect();
    expect($exit->fresh()->status)->toBe('withdrawn');

    $approved = approvedExit($p['otherEmp'], '2026-12-10');
    $this->actingAs($p['other'])->post("/my/resignation/{$approved->id}/withdraw")->assertSessionHasErrors('exit');
    expect($approved->fresh()->status)->toBe('approved');
});

it('gives 403 when withdrawing someone else\'s resignation', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $this->actingAs($p['other'])->post("/my/resignation/{$exit->id}/withdraw")->assertForbidden();
    $this->actingAs($p['manager'])->post("/my/resignation/{$exit->id}/withdraw")->assertForbidden();
    expect($exit->fresh()->status)->toBe('pending');
});

it('lists ended requests with the rejection note', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp'], ['status' => 'rejected', 'decision_note' => 'Please stay until January.']);

    $props = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps();

    expect($props['current'])->toBeNull()
        ->and($props['can_submit'])->toBeTrue()
        ->and($props['ended'])->toHaveCount(1)
        ->and($props['ended'][0]['decision_note'])->toBe('Please stay until January.');
});

it('sends the letter link only once the letter is issued', function () {
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');

    $before = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps()['current'];
    $exit->forceFill(['letter_text' => 'To whom it may concern.', 'letter_issued_by' => $p['hr']->id, 'letter_issued_at' => now()])->save();
    $after = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps()['current'];

    expect($before)->not->toHaveKey('letter_url')
        ->and($after['letter_url'])->toBe("/my/resignation/{$exit->id}/letter");
});

it('shows only the signed-in person\'s own exits', function () {
    $p = xsPeople();
    resignationRow($p['otherEmp']);

    $props = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps();

    expect($props['current'])->toBeNull()
        ->and($props['ended'])->toBe([]);
});
