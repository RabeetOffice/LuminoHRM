<?php

use App\Models\Employee;

require_once __DIR__.'/Support/review-helpers.php';

// The dashboard's "Reviews waiting for you" figures reach the page as the waitingReviews prop (counts and links only).

beforeEach(function () {
    $this->withoutVite();
});

it('sends an employee with a pending self-review their count', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'self_review', ['cycle_attrs' => ['name' => '2026 Q3']]);

    $w = $this->actingAs($p['employee'])->get('/dashboard')->assertOk()->inertiaProps()['waitingReviews'];

    expect($w)->toMatchArray(['self' => 1, 'rate' => 0, 'close' => 0])->and($w['items'])->toHaveCount(1);
});

it('sends a manager the reviews to rate', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'manager_review');
    rvReview(Employee::factory()->create(['manager_id' => $p['managerEmp']->id]), 'manager_review');

    expect($this->actingAs($p['manager'])->get('/dashboard')->inertiaProps()['waitingReviews'])->toMatchArray(['rate' => 2, 'close' => 0]);
});

it('sends HR the reviews ready to close, not their own', function () {
    $p = rvPeople();

    foreach (range(1, 3) as $i) {
        rvReview(Employee::factory()->create(), 'ready_to_close');
    }

    rvReview($p['hrEmp'], 'manager_review', ['reviewer_id' => null]);

    expect($this->actingAs($p['hr'])->get('/dashboard')->inertiaProps()['waitingReviews'])->toMatchArray(['self' => 0, 'rate' => 0, 'close' => 3]);
});

it('counts a review without a reviewer for every performance.manage user except the reviewee', function () {
    $p = rvPeople();
    $admin = userWithRole('admin');
    employeeFor($admin);
    rvReview($p['employeeEmp'], 'manager_review', ['reviewer_id' => null]);

    expect($this->actingAs($p['hr'])->get('/dashboard')->inertiaProps()['waitingReviews']['rate'])->toBe(1)
        ->and($this->actingAs($admin->refresh())->get('/dashboard')->inertiaProps()['waitingReviews']['rate'])->toBe(1);
});

it('sends zeros when nothing waits and no key without an employee record', function () {
    $p = rvPeople();
    $unlinked = userWithRole('hr');

    expect($this->actingAs($p['employee'])->get('/dashboard')->inertiaProps()['waitingReviews'])
        ->toBe(['self' => 0, 'rate' => 0, 'close' => 0, 'items' => []])
        ->and($this->actingAs($unlinked)->get('/dashboard')->assertOk()->inertiaProps())->not->toHaveKey('waitingReviews');
});

it('never puts a score, rating or comment in the dashboard props of the employee', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'manager_review', ['manager' => [5, 1, 5], 'manager_comment' => 'MGR-SECRET-COMMENT', 'self_score' => 399]);

    $json = json_encode($this->actingAs($p['employee'])->get('/dashboard')->inertiaProps());

    expect($json)->not->toContain('MGR-SECRET-COMMENT')->not->toContain('manager_rating')->not->toContain('final_score');
});
