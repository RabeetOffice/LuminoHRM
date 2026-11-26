<?php

use App\Models\Employee;
use App\Support\Performance\ReviewsWaiting;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/review-helpers.php';

// The dashboard card's figures: what waits on this person, in open cycles only, never their own review for rating or closing.

it('counts the own review waiting for a self-review', function () {
    $p = rvPeople();
    $review = rvReview($p['employeeEmp'], 'self_review', ['cycle_attrs' => ['name' => '2026 Q3']]);

    $w = ReviewsWaiting::for($p['employee']);

    expect($w)->toMatchArray(['self' => 1, 'rate' => 0, 'close' => 0])
        ->and($w['items'])->toHaveCount(1)
        ->and($w['items'][0]['label'])->toBe('Write your self-review for 2026 Q3')
        ->and($w['items'][0]['url'])->toBe('/performance/reviews/'.$review->id);
});

it('counts the reviews a manager rates, not other managers reviews', function () {
    $p = rvPeople();
    $other = Employee::factory()->create(['manager_id' => $p['managerEmp']->id]);
    rvReview($p['employeeEmp'], 'manager_review');
    rvReview($other, 'manager_review');
    rvReview(Employee::factory()->create(['manager_id' => $p['hrEmp']->id]), 'manager_review'); // someone else's report
    rvReview($p['employeeEmp'], 'self_review'); // not waiting on the manager yet

    $w = ReviewsWaiting::for($p['manager']);

    expect($w)->toMatchArray(['self' => 0, 'rate' => 2, 'close' => 0])->and($w['items'])->toHaveCount(2);
});

it('counts reviews ready to close for HR and never for a manager', function () {
    $p = rvPeople();

    foreach (range(1, 3) as $i) {
        rvReview(Employee::factory()->create(['manager_id' => $p['managerEmp']->id]), 'ready_to_close');
    }

    expect(ReviewsWaiting::for($p['hr']))->toMatchArray(['self' => 0, 'rate' => 0, 'close' => 3])
        ->and(ReviewsWaiting::for($p['manager']))->toMatchArray(['self' => 0, 'rate' => 0, 'close' => 0]);
});

it('does not count the own review in manager_review or ready_to_close for HR', function () {
    $p = rvPeople();
    rvReview($p['hrEmp'], 'manager_review', ['reviewer_id' => null]);
    rvReview($p['hrEmp'], 'ready_to_close');

    expect(ReviewsWaiting::for($p['hr']))->toMatchArray(['self' => 0, 'rate' => 0, 'close' => 0]);
});

it('counts an HR review without a reviewer for every performance.manage user except the reviewee', function () {
    $p = rvPeople();
    $admin = userWithRole('admin');
    employeeFor($admin);
    rvReview($p['employeeEmp'], 'manager_review', ['reviewer_id' => null]);
    rvReview($p['hrEmp'], 'manager_review', ['reviewer_id' => null]);

    expect(ReviewsWaiting::for($p['hr'])['rate'])->toBe(1)
        ->and(ReviewsWaiting::for($admin->refresh())['rate'])->toBe(2)
        ->and(ReviewsWaiting::for($p['manager'])['rate'])->toBe(0)
        ->and(ReviewsWaiting::for($p['employee'])['rate'])->toBe(0);
});

it('does not count a review in a closed cycle', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'manager_review', ['cycle' => reviewCycleRow(['status' => 'closed'])]);

    expect(ReviewsWaiting::for($p['manager']))->toMatchArray(['self' => 0, 'rate' => 0, 'close' => 0]);
});

it('does not count a review for a reviewer who no longer holds performance.review-team', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'manager_review');
    $p['manager']->syncRoles(['employee']);

    expect(ReviewsWaiting::for($p['manager']->refresh())['rate'])->toBe(0);
});

it('gives zeros and no items to an account without an employee record', function () {
    $user = userWithRole('hr');

    expect(ReviewsWaiting::for($user))->toBe(['self' => 0, 'rate' => 0, 'close' => 0, 'items' => []]);
});

it('lists at most five items, the latest due first, and counts them all', function () {
    $p = rvPeople();

    foreach (range(1, 7) as $i) {
        rvReview(Employee::factory()->create(['manager_id' => $p['managerEmp']->id]), 'manager_review', [
            'cycle_attrs' => ['name' => "Cycle {$i}", 'due_on' => sprintf('2030-0%d-15', $i)],
        ]);
    }

    $w = ReviewsWaiting::for($p['manager']);

    expect($w['rate'])->toBe(7)
        ->and($w['items'])->toHaveCount(5)
        ->and($w['items'][0]['label'])->toEndWith('for Cycle 7')
        ->and($w['items'][4]['label'])->toEndWith('for Cycle 3');
});

it('names the reviewee only on reviews the viewer rates or closes, and carries no score or comment', function () {
    $p = rvPeople();
    rvReview($p['employeeEmp'], 'manager_review', ['manager' => [5, 1, 5], 'manager_comment' => 'MGR-SECRET-COMMENT']);

    $w = ReviewsWaiting::for($p['manager']);

    expect($w['items'][0]['label'])->toStartWith('Rate Sara Ahmed for ')
        ->and(json_encode($w))->not->toContain('MGR-SECRET-COMMENT')->not->toContain('score');
});

it('runs a fixed number of queries whatever the counts', function () {
    $p = rvPeople();
    $p['hr']->can('performance.manage'); // warm the permission cache: the figures themselves are what is counted

    foreach (range(1, 4) as $i) {
        rvReview(Employee::factory()->create(['manager_id' => $p['managerEmp']->id]), 'ready_to_close');
    }

    DB::enableQueryLog();
    ReviewsWaiting::for($p['hr']);
    $few = count(DB::getQueryLog());
    DB::flushQueryLog();

    foreach (range(1, 6) as $i) {
        rvReview(Employee::factory()->create(['manager_id' => $p['managerEmp']->id]), 'ready_to_close');
    }

    DB::flushQueryLog();
    ReviewsWaiting::for($p['hr']);
    $many = count(DB::getQueryLog());

    expect($few)->toBeLessThanOrEqual(4)->and($many)->toBe($few);
});
