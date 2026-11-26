<?php

use App\Actions\Performance\CloseReview;
use App\Actions\Performance\SaveManagerRatings;
use App\Actions\Performance\SaveSelfRatings;
use App\Actions\Performance\SendBackReview;
use App\Actions\Performance\SkipReview;
use App\Actions\Performance\SubmitManagerReview;
use App\Actions\Performance\SubmitSelfReview;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewStates;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

// Two clicks, two tabs, two users: each action gets the model as it was loaded before the other one committed (a stale copy)
// and must re-read under its locks, so the second one sees the new state and refuses.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
});

function rrStale(Review $review): Review
{
    return Review::query()->with('scores')->findOrFail($review->id);
}

it('double self submit: the second is refused, one entry', function () {
    $review = rvReview($this->p['employeeEmp'], 'self_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    [$tabA, $tabB] = [rrStale($review), rrStale($review)];
    $ratings = rvRatings($review, [4, 5, 3]);

    app(SubmitSelfReview::class)->handle($this->p['employee'], $tabA, $ratings);

    expect(rvRefusal(fn () => app(SubmitSelfReview::class)->handle($this->p['employee'], $tabB, rvRatings($review, [1, 1, 1]))))
        ->toBe(['review' => ReviewStates::refused('manager_review')])
        ->and(rvAudit('Self-review submitted'))->toHaveCount(1)
        ->and(Review::query()->findOrFail($review->id)->self_score)->toBe(380)
        ->and(array_column(rvScoreRows($review), 'self_rating'))->toBe([4, 5, 3]);
});

it('double manager submit: the second is refused, one entry', function () {
    $review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    [$tabA, $tabB] = [rrStale($review), rrStale($review)];

    app(SubmitManagerReview::class)->handle($this->p['manager'], $tabA, rvRatings($review, [4, 5, 3]), null);

    expect(rvRefusal(fn () => app(SubmitManagerReview::class)->handle($this->p['manager'], $tabB, rvRatings($review, [1, 1, 1]), null)))
        ->toBe(['review' => ReviewStates::refused('ready_to_close')])
        ->and(rvAudit('Manager review submitted'))->toHaveCount(1)
        ->and(array_column(rvScoreRows($review), 'manager_rating'))->toBe([4, 5, 3]);
});

it('close against send back: whichever commits first wins, the other refuses', function (string $first) {
    $review = rvReview($this->p['employeeEmp'], 'ready_to_close', ['reviewer_id' => $this->p['managerEmp']->id, 'manager' => [4, 5, 3]]);
    $hr2 = userWithRole('admin');
    [$tabA, $tabB] = [rrStale($review), rrStale($review)];

    if ($first === 'close') {
        app(CloseReview::class)->handle($this->p['hr'], $tabA);
        expect(rvRefusal(fn () => app(SendBackReview::class)->handle($hr2, $tabB, 'Redo')))->toBe(['review' => ReviewStates::refused('closed')])
            ->and(Review::query()->findOrFail($review->id)->status)->toBe('closed')
            ->and(rvAudit('Review sent back'))->toHaveCount(0);
    } else {
        app(SendBackReview::class)->handle($hr2, $tabA, 'Redo');
        expect(rvRefusal(fn () => app(CloseReview::class)->handle($this->p['hr'], $tabB)))->toBe(['review' => ReviewStates::refused('manager_review')])
            ->and(Review::query()->findOrFail($review->id)->final_score)->toBeNull()
            ->and(rvAudit('Review closed'))->toHaveCount(0);
    }
})->with(['close', 'send back']);

it('skip against manager submit: whichever commits first wins', function (string $first) {
    $review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    [$tabA, $tabB] = [rrStale($review), rrStale($review)];

    if ($first === 'skip') {
        app(SkipReview::class)->handle($this->p['hr'], $tabA, 'Left');
        expect(rvRefusal(fn () => app(SubmitManagerReview::class)->handle($this->p['manager'], $tabB, rvRatings($review, [4, 4, 4]), null)))
            ->toBe(['review' => ReviewStates::refused('skipped')])
            ->and(array_column(rvScoreRows($review), 'manager_rating'))->toBe([null, null, null]);
    } else {
        app(SubmitManagerReview::class)->handle($this->p['manager'], $tabA, rvRatings($review, [4, 4, 4]), null);
        app(SkipReview::class)->handle($this->p['hr'], $tabB, 'Left');
        // A skip from ready_to_close is allowed; it records the state it really came from.
        expect(Review::query()->findOrFail($review->id)->skipped_from)->toBe('ready_to_close');
    }
})->with(['skip', 'submit']);

it('a draft save after submit refuses with the state message', function () {
    $review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    $stale = rrStale($review);

    app(SubmitManagerReview::class)->handle($this->p['manager'], rrStale($review), rvRatings($review, [4, 5, 3]), null);

    expect(rvRefusal(fn () => app(SaveManagerRatings::class)->handle($this->p['manager'], $stale, rvRatings($review, [1]), null)))
        ->toBe(['review' => ReviewStates::refused('ready_to_close')])
        ->and(array_column(rvScoreRows($review), 'manager_rating'))->toBe([4, 5, 3]);
});

it('an action that began before the cycle closed refuses on the cycle status', function () {
    $review = rvReview($this->p['employeeEmp'], 'self_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    $stale = rrStale($review);
    $stale->load('cycle');

    // The cycle closes (written directly: the cycle actions belong to another task) after the model was loaded.
    ReviewCycle::query()->whereKey($review->review_cycle_id)->update(['status' => 'closed', 'closed_at' => now()]);

    expect($stale->cycle->status)->toBe('open')
        ->and(rvRefusal(fn () => app(SaveSelfRatings::class)->handle($this->p['employee'], $stale, rvRatings($review, [4]))))
        ->toBe(['review' => ReviewLock::CYCLE_CLOSED])
        ->and(array_column(rvScoreRows($review), 'self_rating'))->toBe([null, null, null]);
});

it('refuses ratings of another review\'s score rows, keeping both reviews untouched', function () {
    $mine = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    $theirs = rvReview(Employee::factory()->create(['manager_id' => $this->p['managerEmp']->id]), 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
    $mixed = rvRatings($mine, [4]) + rvRatings($theirs, [1]);

    expect(rvRefusal(fn () => app(SaveManagerRatings::class)->handle($this->p['manager'], $mine, $mixed, null)))
        ->toBe(['ratings' => ReviewRatings::NOT_THIS_REVIEW])
        ->and(array_column(rvScoreRows($mine), 'manager_rating'))->toBe([null, null, null])
        ->and(array_column(rvScoreRows($theirs), 'manager_rating'))->toBe([null, null, null]);
});

it('scores the rows as they are under the lock, not as the stale model holds them', function () {
    $review = rvReview($this->p['employeeEmp'], 'ready_to_close', ['reviewer_id' => $this->p['managerEmp']->id, 'manager' => [1, 1, 1]]);
    $stale = rrStale($review);

    // Another session sent it back and the manager resubmitted with new ratings.
    app(SendBackReview::class)->handle($this->p['hr'], rrStale($review), 'Redo');
    app(SubmitManagerReview::class)->handle($this->p['manager'], rrStale($review), rvRatings($review, [5, 5, 5]), null);

    app(CloseReview::class)->handle($this->p['hr'], $stale);

    expect(Review::query()->findOrFail($review->id)->final_score)->toBe(500);
});
