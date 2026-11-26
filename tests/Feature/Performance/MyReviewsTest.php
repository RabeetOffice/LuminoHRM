<?php

use App\Models\Employee;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

require_once __DIR__.'/Support/review-helpers.php';

/*
| My Reviews: the signed-in person's own reviews, whoever they are. No manager rating, comment, score or skip reason
| before the review is closed.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
});

it('shows an employee only their own open, closed and skipped reviews', function () {
    $emp = $this->p['employeeEmp'];
    $open = rvReview($emp, 'self_review', ['cycle_attrs' => ['name' => 'Open cycle', 'due_on' => '2026-12-15']]);
    $closed = rvReview($emp, 'closed', [
        'final_score' => 412, 'closed_at' => '2026-10-05 10:00:00',
        'cycle_attrs' => ['name' => 'Closed cycle', 'status' => 'closed'],
    ]);
    $skipped = rvReview($emp, 'skipped', ['skip_reason' => 'HR-SKIP-REASON', 'cycle_attrs' => ['name' => 'Skipped cycle']]);
    rvReview(Employee::factory()->create(), 'self_review', ['cycle_attrs' => ['name' => 'Someone elses']]);

    $this->actingAs($this->p['employee'])->get('/my/reviews')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('my/reviews')
        ->where('employee.name', $emp->full_name)
        ->has('open', 1)
        ->where('open.0.id', $open->id)
        ->where('open.0.cycle', 'Open cycle')
        ->where('open.0.due_on', '2026-12-15')
        ->where('open.0.state', 'Self-review')
        ->where('open.0.action', 'Write your self-review')
        ->where('open.0.url', "/performance/reviews/{$open->id}")
        ->has('closed', 1)
        ->where('closed.0.id', $closed->id)
        ->where('closed.0.final_score', '4.12')
        ->where('closed.0.label', 'Exceeds')
        ->where('closed.0.closed_on', '2026-10-05')
        ->has('skipped', 1)
        ->where('skipped.0', ['id' => $skipped->id, 'cycle' => 'Skipped cycle', 'state' => 'Skipped']));

    expect(json_encode($this->actingAs($this->p['employee'])->get('/my/reviews')->inertiaProps()))
        ->not->toContain('HR-SKIP-REASON')->not->toContain('Someone elses');
});

it('words the next step for each open state', function () {
    rvReview($this->p['employeeEmp'], 'manager_review');
    rvReview($this->p['employeeEmp'], 'ready_to_close');

    $actions = array_column($this->actingAs($this->p['employee'])->get('/my/reviews')->inertiaProps()['open'], 'action');

    expect($actions)->toEqualCanonicalizing(['With your manager', 'Ready to close']);
});

it('never carries a manager rating, comment, note or score on an open review', function (string $state) {
    $rv = rvReview($this->p['employeeEmp'], $state, [
        'manager' => [5, 1, 5], 'manager_comment' => 'MGR-SECRET-COMMENT', 'send_back_note' => 'HR-SECRET-NOTE',
        'self_score' => 377, 'final_score' => 433, 'skip_reason' => 'HR-SKIP-REASON',
    ]);
    $rv->scores()->first()->forceFill(['manager_comment' => 'MGR-LINE-COMMENT'])->save();

    $props = $this->actingAs($this->p['employee'])->get('/my/reviews')->assertOk()->inertiaProps();
    $json = json_encode($props);

    foreach (['MGR-SECRET-COMMENT', 'HR-SECRET-NOTE', 'MGR-LINE-COMMENT', 'HR-SKIP-REASON', '4.33', '3.77', 'manager_rating', 'manager_comment', 'manager_score', 'send_back_note', 'skip_reason'] as $secret) {
        expect($json)->not->toContain($secret);
    }

    expect($props['open'][0])->not->toHaveKey('final_score')->not->toHaveKey('label');
})->with(['self_review', 'manager_review', 'ready_to_close']);

it('gives an HR user their own reviews in the reviewee shape', function () {
    $own = rvReview($this->p['hrEmp'], 'manager_review', ['reviewer_id' => null, 'manager' => [5, 5, 5], 'manager_comment' => 'MGR-SECRET-COMMENT']);
    rvReview($this->p['employeeEmp'], 'ready_to_close'); // not theirs: HR may close it, but it is not in My Reviews

    $props = $this->actingAs($this->p['hr'])->get('/my/reviews')->assertOk()->inertiaProps();

    expect(array_column($props['open'], 'id'))->toBe([$own->id])
        ->and($props['open'][0]['action'])->toBe('With your manager')
        ->and(json_encode($props))->not->toContain('MGR-SECRET-COMMENT');
});

it('orders the closed history newest first', function () {
    $older = rvReview($this->p['employeeEmp'], 'closed', ['final_score' => 300, 'closed_at' => '2026-07-05 10:00:00', 'cycle_attrs' => ['status' => 'closed']]);
    $newer = rvReview($this->p['employeeEmp'], 'closed', ['final_score' => 450, 'closed_at' => '2026-10-05 10:00:00', 'cycle_attrs' => ['status' => 'closed']]);

    $closed = $this->actingAs($this->p['employee'])->get('/my/reviews')->inertiaProps()['closed'];

    expect(array_column($closed, 'id'))->toBe([$newer->id, $older->id])
        ->and($closed[0]['label'])->toBe('Outstanding');
});

it('gives an account without an employee record empty lists', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->get('/my/reviews')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('my/reviews')
        ->where('employee', null)
        ->where('open', [])
        ->where('closed', [])
        ->where('skipped', []));
});

it('takes no person from the URL', function () {
    rvReview($this->p['employeeEmp'], 'self_review', ['cycle_attrs' => ['name' => 'Sara cycle']]);
    $other = userWithRole('employee');
    employeeFor($other);

    $props = $this->actingAs($other->refresh())->get('/my/reviews?employee='.$this->p['employeeEmp']->id.'&employee_id='.$this->p['employeeEmp']->id)->assertOk()->inertiaProps();

    expect($props['open'])->toBe([])->and(json_encode($props))->not->toContain('Sara cycle');
});

it('needs a signed-in user', function () {
    $this->get('/my/reviews')->assertRedirect('/login');
});
