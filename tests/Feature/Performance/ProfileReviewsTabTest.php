<?php

use App\Models\Employee;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

/*
| The profile's "Reviews" tab: the person's CLOSED reviews, newest first, for whoever may open the profile. Open and
| skipped reviews never appear. The link only where ReviewAccess lets the viewer open that review.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
});

function prtClosed(Employee $employee, string $cycleName, int $score, string $closedAt, array $attrs = [])
{
    $periods = ['2026 Q2' => ['2026-04-01', '2026-06-30'], '2026 Q3' => ['2026-07-01', '2026-09-30']];
    [$start, $end] = $periods[$cycleName];

    return rvReview($employee, 'closed', array_merge([
        'final_score' => $score,
        'self_score' => 400,
        'closed_at' => $closedAt,
        'cycle_attrs' => ['name' => $cycleName, 'status' => 'closed', 'period_start' => $start, 'period_end' => $end],
        'manager' => [4, 4, 5],
        'manager_comment' => 'MGR-SECRET-COMMENT',
    ], $attrs));
}

it('lists closed history for HR and Admin, newest first, with a link', function (string $role) {
    $older = prtClosed($this->p['employeeEmp'], '2026 Q2', 380, '2026-07-05 10:00:00');
    $newer = prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00');
    $viewer = $role === 'hr' ? $this->p['hr'] : tap(userWithRole('admin'), fn ($u) => employeeFor($u))->refresh();

    $props = $this->actingAs($viewer)->get("/employees/{$this->p['employeeEmp']->id}")->assertOk()->inertiaProps();
    $closed = $props['employee']['reviews']['closed'];

    expect($props['tabs'])->toContain('reviews')
        ->and(array_column($closed, 'id'))->toBe([$newer->id, $older->id])
        ->and($closed[0])->toMatchArray([
            'cycle' => '2026 Q3',
            'period' => ['from' => '2026-07-01', 'to' => '2026-09-30'],
            'final_score' => '4.12',
            'label' => 'Exceeds',
            'reviewer' => 'Omar Khan',
            'closed_on' => '2026-10-05',
            'url' => "/performance/reviews/{$newer->id}",
        ]);
})->with(['hr', 'admin']);

it('names HR as the reviewer of a review without one', function () {
    prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00', ['reviewer_id' => null]);

    $closed = $this->actingAs($this->p['hr'])->get("/employees/{$this->p['employeeEmp']->id}")->inertiaProps()['employee']['reviews']['closed'];

    expect($closed[0]['reviewer'])->toBe('HR');
});

it('never lists an open or skipped review', function () {
    foreach (['self_review', 'manager_review', 'ready_to_close', 'skipped'] as $state) {
        rvReview($this->p['employeeEmp'], $state, ['skip_reason' => 'HR-SKIP-REASON']);
    }

    $props = $this->actingAs($this->p['hr'])->get("/employees/{$this->p['employeeEmp']->id}")->inertiaProps();

    expect($props['employee']['reviews'])->toBe(['closed' => []])
        ->and(json_encode($props))->not->toContain('HR-SKIP-REASON');
});

it('gives a Manager viewing a direct report the history with a link', function () {
    $review = prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00');

    $closed = $this->actingAs($this->p['manager'])->get("/employees/{$this->p['employeeEmp']->id}")->assertOk()->inertiaProps()['employee']['reviews']['closed'];

    expect($closed)->toHaveCount(1)->and($closed[0]['url'])->toBe("/performance/reviews/{$review->id}");
});

it('leaves the link out when the viewer may open the profile but not the review', function () {
    // Someone with employees.view-all but no performance permission (a custom role) sees the history without a link.
    $review = prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00');
    $viewer = userWithRole('employee');
    employeeFor($viewer);
    $viewer->givePermissionTo('employees.view-all');

    $closed = $this->actingAs($viewer->refresh())->get("/employees/{$this->p['employeeEmp']->id}")->assertOk()->inertiaProps()['employee']['reviews']['closed'];

    expect($closed)->toHaveCount(1)
        ->and($closed[0])->not->toHaveKey('url')
        ->and($closed[0]['id'])->toBe($review->id);
});

it('keeps the existing 403 for a Manager viewing someone who is not their report', function () {
    prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00');
    $viewer = userWithRole('manager');
    employeeFor($viewer);

    $this->actingAs($viewer->refresh())->get("/employees/{$this->p['employeeEmp']->id}")->assertForbidden();
});

it('gives an Employee on My Profile their own closed reviews, with a link', function () {
    $review = prtClosed($this->p['employeeEmp'], '2026 Q3', 412, '2026-10-05 10:00:00');
    rvReview($this->p['employeeEmp'], 'manager_review');

    $props = $this->actingAs($this->p['employee'])->get('/my/profile')->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('reviews')
        ->and($props['employee']['reviews']['closed'])->toHaveCount(1)
        ->and($props['employee']['reviews']['closed'][0])->toMatchArray(['final_score' => '4.12', 'label' => 'Exceeds', 'url' => "/performance/reviews/{$review->id}"]);
});

it('shows the empty list when there is no closed review', function () {
    $props = $this->actingAs($this->p['hr'])->get("/employees/{$this->p['employeeEmp']->id}")->inertiaProps();

    expect($props['employee']['reviews'])->toBe(['closed' => []]);
});

it('puts the Reviews tab after Assets', function () {
    $tabs = $this->actingAs($this->p['hr'])->get("/employees/{$this->p['employeeEmp']->id}")->inertiaProps()['tabs'];

    expect(array_slice($tabs, -2))->toBe(['assets', 'reviews']);
});
