<?php

use App\Models\Review;
use App\Models\ReviewScore;
use App\Models\User;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewView;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $this->admin = userWithRole('admin');
    $this->adminEmp = employeeFor($this->admin, ['first_name' => 'Asad', 'last_name' => 'Ali']);
    $this->otherManager = userWithRole('manager');
    $this->otherManagerEmp = employeeFor($this->otherManager, ['first_name' => 'Bilal', 'last_name' => 'Shah']);
    $this->otherEmployee = userWithRole('employee');
    employeeFor($this->otherEmployee, ['first_name' => 'Nida', 'last_name' => 'Iqbal', 'manager_id' => $this->otherManagerEmp->id]);
});

/** A review of the employee, reviewed by the manager, with both sides rated and commented. */
function pageReview(string $status, array $attrs = []): Review
{
    $p = test()->p;
    $review = rvReview($p['employeeEmp'], $status, array_merge([
        'reviewer_id' => $p['managerEmp']->id,
        'self' => [4, 3, 2],
        'manager' => [5, 1, 5],
        'manager_comment' => 'Overall: reliable.',
        'self_submitted_at' => $status === 'self_review' ? null : now(),
        'self_score' => $status === 'self_review' ? null : 270,
    ], $attrs));

    foreach ($review->scores as $i => $score) {
        $score->forceFill(['self_comment' => 'Self note '.($i + 1), 'manager_comment' => 'Manager note '.($i + 1)])->save();
    }

    return $review->refresh();
}

/** The Inertia props the review page sends this user (the page's data-page payload). */
function pageProps(User $user, Review $review): array
{
    $response = test()->actingAs($user)->get(route('performance.reviews.show', $review->id))->assertOk();

    return $response->viewData('page')['props'];
}

// ---------------------------------------------------------------- the employee

it('gives the employee in self-review their form with their saved draft and no manager keys', function () {
    $review = pageReview('self_review');

    $props = pageProps($this->p['employee'], $review);

    expect($props['role'])->toBe('reviewee')
        ->and($props['review']['state'])->toBe('self_review')
        ->and($props['review']['state_label'])->toBe('Self-review')
        ->and($props['review']['employee']['name'])->toBe('Sara Ahmed')
        ->and($props['review']['cycle']['due_on'])->toBe($review->cycle->due_on->format('Y-m-d'))
        ->and($props['review']['reviewer'])->toBe('Omar Khan')
        ->and($props['can']['save_self'])->toBeTrue()
        ->and($props['can']['submit_self'])->toBeTrue()
        ->and($props['can']['save_manager'])->toBeFalse()
        ->and(collect($props['rows'])->pluck('self_rating')->all())->toBe([4, 3, 2])
        ->and(collect($props['rows'])->pluck('self_comment')->all())->toBe(['Self note 1', 'Self note 2', 'Self note 3'])
        ->and($props['rows'][0])->not->toHaveKeys(['manager_rating', 'manager_comment'])
        ->and($props)->not->toHaveKeys(['manager_comment', 'send_back_note', 'skip_reason', 'reviewer_options', 'reviewer_problem']);
});

it('keeps the manager side away from the employee before close', function (string $state) {
    $review = pageReview($state, ['final_score' => 412, 'send_back_note' => 'Check KPI two.']);

    $props = pageProps($this->p['employee'], $review);
    $json = json_encode($props);

    expect($props['rows'][0])->toHaveKeys(['self_rating', 'self_comment'])
        ->and($props['rows'][0])->not->toHaveKeys(['manager_rating', 'manager_comment'])
        ->and($props)->not->toHaveKeys(['manager_comment', 'send_back_note', 'skip_reason'])
        ->and($props['score'])->toBe(['self_score' => 270])
        ->and(array_filter($props['can']))->toBe([])
        ->and($json)->not->toContain('Manager note')->not->toContain('Overall: reliable')->not->toContain('Check KPI two');
})->with(['manager_review', 'ready_to_close']);

it('shows the employee both sides, the comments, the score and its label once closed, but never the notes', function () {
    $review = pageReview('closed', ['final_score' => 380, 'closed_at' => now(), 'send_back_note' => 'Old note', 'skip_reason' => 'Old reason']);

    $props = pageProps($this->p['employee'], $review);

    expect($props['rows'][1])->toMatchArray(['self_rating' => 3, 'manager_rating' => 1, 'manager_comment' => 'Manager note 2'])
        ->and($props['manager_comment'])->toBe('Overall: reliable.')
        ->and($props['score'])->toEqual(['self_score' => 270, 'manager_score' => 380, 'final_score' => 380, 'label' => 'Exceeds'])
        ->and($props)->not->toHaveKeys(['send_back_note', 'skip_reason'])
        ->and(array_filter($props['can']))->toBe([]);
});

it('shows the employee only "Skipped" on a skipped review', function () {
    $review = pageReview('skipped', ['skip_reason' => 'On long leave', 'skipped_from' => 'manager_review']);

    $props = pageProps($this->p['employee'], $review);

    expect($props['review']['state_label'])->toBe('Skipped')
        ->and($props['rows'])->toBe([])
        ->and($props['score'])->toBe([])
        ->and(json_encode($props))->not->toContain('On long leave')->not->toContain('Manager note');
});

it('gives an HR user the employee shape on their own review', function () {
    $review = rvReview($this->p['hrEmp'], 'ready_to_close', ['reviewer_id' => $this->p['managerEmp']->id, 'manager' => [5, 5, 5], 'manager_comment' => 'HR own secret']);

    $props = pageProps($this->p['hr'], $review);

    expect($props['role'])->toBe('reviewee')
        ->and($props['rows'][0])->not->toHaveKeys(['manager_rating', 'manager_comment'])
        ->and($props)->not->toHaveKeys(['manager_comment', 'send_back_note', 'skip_reason', 'reviewer_options'])
        ->and(array_filter($props['can']))->toBe([])
        ->and(json_encode($props))->not->toContain('HR own secret');
});

it('lets the only performance.manage user work their own review, which is their own work', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $this->p['hr']->removeRole('hr');
    $this->p['hr']->assignRole('admin');
    $review = rvReview($this->p['hrEmp'], 'manager_review', ['reviewer_id' => null]);

    $props = pageProps($this->p['hr'], $review);

    expect($props['role'])->toBe('reviewee')
        ->and($props['can']['save_manager'])->toBeTrue()
        ->and($props['can']['skip'])->toBeTrue()
        ->and($props['rows'][0])->toHaveKeys(['manager_rating', 'manager_comment']);
});

// ---------------------------------------------------------------- the reviewer

it('tells the reviewer to wait while the employee is rating, with no rating keys at all', function () {
    $review = pageReview('self_review', ['manager' => [null, null, null]]);

    $props = pageProps($this->p['manager'], $review);

    expect($props['role'])->toBe('reviewer')
        ->and($props['notice'])->toBe(ReviewView::WAITING)
        ->and($props['notice'])->toBe("Waiting for the employee's self-review.")
        ->and(array_keys($props['rows'][0]))->toBe(['id', 'name', 'description', 'weight'])
        ->and(array_filter($props['can']))->toBe([])
        ->and($props['score'])->toBe([])
        ->and(json_encode($props))->not->toContain('Self note');
});

it('gives the reviewer the self side after submit and their own draft, with the send-back note', function () {
    $review = pageReview('manager_review', ['send_back_note' => 'Please justify the 1.', 'manager_comment' => 'Draft overall']);

    $props = pageProps($this->p['manager'], $review);

    expect($props['rows'][1])->toMatchArray(['self_rating' => 3, 'self_comment' => 'Self note 2', 'manager_rating' => 1, 'manager_comment' => 'Manager note 2'])
        ->and($props['manager_comment'])->toBe('Draft overall')
        ->and($props['send_back_note'])->toBe('Please justify the 1.')
        ->and($props['can']['save_manager'])->toBeTrue()
        ->and($props['can']['submit_manager'])->toBeTrue()
        ->and($props['can']['send_back'])->toBeFalse()
        ->and($props['can']['close'])->toBeFalse()
        ->and($props)->not->toHaveKey('skip_reason')
        ->and($props['score'])->toBe(['self_score' => 270]);
});

it('does not give the reviewer a waived self-review draft', function () {
    $review = pageReview('manager_review', ['self_submitted_at' => null]);

    $props = pageProps($this->p['manager'], $review);

    expect($props['rows'][0])->not->toHaveKeys(['self_rating', 'self_comment'])
        ->and(json_encode($props))->not->toContain('Self note');
});

it('makes the reviewer read-only once the review is ready to close, with the live score', function () {
    $review = pageReview('ready_to_close');

    $props = pageProps($this->p['manager'], $review);

    expect($props['can']['save_manager'])->toBeFalse()
        ->and($props['can']['submit_manager'])->toBeFalse()
        ->and($props['score'])->toEqual(['self_score' => 270, 'manager_score' => 380, 'label' => 'Exceeds']);
});

it('lets the reviewer open the closed review of a current direct report after the cycle closes', function () {
    $cycle = reviewCycleRow(['status' => 'closed']);
    $review = pageReview('closed', ['cycle' => $cycle, 'reviewer_id' => $this->otherManagerEmp->id, 'final_score' => 380, 'closed_at' => now()]);

    $props = pageProps($this->p['manager'], $review);

    expect($props['role'])->toBe('reviewer')
        ->and($props['score']['final_score'])->toBe(380)
        ->and(array_filter($props['can']))->toBe([]);
});

// ---------------------------------------------------------------- HR

it('gives HR everything, drafts included, and the live preview at ready to close', function () {
    $review = pageReview('ready_to_close', ['send_back_note' => 'Earlier note', 'skip_reason' => 'Left over', 'self_submitted_at' => null]);

    $props = pageProps($this->p['hr'], $review);

    expect($props['role'])->toBe('hr')
        ->and($props['rows'][2])->toMatchArray(['self_rating' => 2, 'manager_rating' => 5, 'self_comment' => 'Self note 3', 'manager_comment' => 'Manager note 3'])
        ->and($props['manager_comment'])->toBe('Overall: reliable.')
        ->and($props['send_back_note'])->toBe('Earlier note')
        ->and($props['skip_reason'])->toBe('Left over')
        ->and($props['score'])->toBe(['self_score' => 270, 'manager_score' => 380, 'label' => 'Exceeds'])
        ->and($props['can'])->toMatchArray([
            'save_self' => false, 'save_manager' => false, 'submit_manager' => false,
            'send_back' => true, 'close' => true, 'reopen' => false, 'skip' => true, 'restore' => false, 'waive' => false, 'change_reviewer' => true,
        ])
        ->and(collect($props['reviewer_options'])->pluck('name')->all())->toContain('Omar Khan')->not->toContain('Sara Ahmed')
        ->and($props['reviewer_id'])->toBe($this->p['managerEmp']->id)
        ->and($props['reviewer_problem'])->toBeNull();
});

it('gives HR and Admin the final score once closed, and a way to reopen it', function (string $who) {
    $review = pageReview('closed', ['final_score' => 380, 'closed_at' => now()]);

    $props = pageProps($who === 'admin' ? $this->admin : $this->p['hr'], $review);

    expect($props['score'])->toEqual(['self_score' => 270, 'manager_score' => 380, 'final_score' => 380, 'label' => 'Exceeds'])
        ->and($props['can']['reopen'])->toBeTrue()
        ->and($props['can']['close'])->toBeFalse()
        ->and($props['can']['change_reviewer'])->toBeFalse();
})->with(['hr', 'admin']);

it('offers restore and no other action on a skipped review', function () {
    $review = pageReview('skipped', ['skip_reason' => 'On long leave', 'skipped_from' => 'manager_review']);

    $props = pageProps($this->p['hr'], $review);

    expect($props['skip_reason'])->toBe('On long leave')
        ->and($props['can']['restore'])->toBeTrue()
        ->and($props['can']['skip'])->toBeFalse()
        ->and($props['can']['change_reviewer'])->toBeTrue();
});

it('offers waive while the employee is still rating', function () {
    $props = pageProps($this->p['hr'], pageReview('self_review'));

    expect($props['can']['waive'])->toBeTrue()
        ->and($props['can']['skip'])->toBeTrue();
});

it('warns HR when the reviewer cannot review any more', function () {
    $review = pageReview('manager_review');
    $this->p['manager']->forceFill(['is_active' => false])->save();

    $props = pageProps($this->p['hr'], $review);

    expect($props['reviewer_problem'])->toBe('Omar Khan has no active login with review access. Choose another reviewer.')
        ->and(collect($props['reviewer_options'])->pluck('name')->all())->not->toContain('Omar Khan');
});

it('shows "HR" as the reviewer of a review without one', function () {
    $props = pageProps($this->p['hr'], pageReview('manager_review', ['reviewer_id' => null]));

    expect($props['review']['reviewer'])->toBe('HR')
        ->and($props['can']['save_manager'])->toBeTrue();
});

it('turns every action off in a closed cycle', function () {
    $cycle = reviewCycleRow(['status' => 'closed']);
    $review = pageReview('ready_to_close', ['cycle' => $cycle]);

    $props = pageProps($this->p['hr'], $review);

    expect(array_filter($props['can']))->toBe([])
        ->and($props['review']['cycle']['status'])->toBe('closed');

    // The reviewer's access to a review that is not closed ends with the cycle.
    $this->actingAs($this->p['manager'])->get(route('performance.reviews.show', $review->id))->assertForbidden();
});

// ---------------------------------------------------------------- 403

it('refuses people who may not open the review', function (string $who) {
    $review = pageReview('closed', ['final_score' => 380, 'closed_at' => now()]);
    $user = match ($who) {
        'another employee' => $this->otherEmployee,
        'another manager' => $this->otherManager,
        'a former reviewer' => tap($this->p['manager'], fn (User $m) => $m->removeRole('manager')),
    };

    $this->actingAs($user)->get(route('performance.reviews.show', $review->id))->assertForbidden();
})->with(['another employee', 'another manager', 'a former reviewer']);

it('refuses a reviewer who is no longer the manager once the cycle is closed', function () {
    $cycle = reviewCycleRow(['status' => 'closed']);
    $review = pageReview('closed', ['cycle' => $cycle, 'final_score' => 380, 'closed_at' => now()]);
    $this->p['employeeEmp']->forceFill(['manager_id' => $this->otherManagerEmp->id])->save();

    $this->actingAs($this->p['manager'])->get(route('performance.reviews.show', $review->id))->assertForbidden();
    $this->actingAs($this->otherManager)->get(route('performance.reviews.show', $review->id))->assertOk();
});

it('answers 403 for a review that does not exist, and sends guests to log in', function () {
    $this->actingAs($this->p['hr'])->get(route('performance.reviews.show', 999999))->assertForbidden();

    auth()->logout();
    $this->get('/performance/reviews/1')->assertRedirect('/login');
});

it('renders the review page component', function () {
    $review = pageReview('manager_review');

    $this->actingAs($this->p['manager'])->get(route('performance.reviews.show', $review->id))
        ->assertInertia(fn ($page) => $page->component('performance/review')->has('rows', 3)->where('role', 'reviewer'));
});

it('sends the score rows of this review only', function () {
    $review = pageReview('manager_review');
    $other = rvReview($this->p['hrEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);

    $ids = collect(pageProps($this->p['manager'], $review)['rows'])->pluck('id')->all();

    expect($ids)->toBe(ReviewScore::query()->where('review_id', $review->id)->orderBy('sort_order')->pluck('id')->all())
        ->and($ids)->not->toContain($other->scores()->first()->id);
});

it('gives an HR user who is the stored reviewer the HR role and the rating form (mayRate decides, not the role)', function () {
    $review = pageReview('manager_review', ['reviewer_id' => $this->p['hrEmp']->id]);

    $props = pageProps($this->p['hr'], $review);

    expect(ReviewAccess::mayRate($this->p['hr'], $review))->toBeTrue()
        ->and($props['role'])->toBe('hr')
        ->and($props['can']['save_manager'])->toBeTrue()
        ->and($props['can']['submit_manager'])->toBeTrue()
        ->and($props['rows'][0])->toHaveKeys(['manager_rating', 'manager_comment', 'self_rating'])
        ->and($props)->toHaveKey('manager_comment');
});
