<?php

namespace App\Actions\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewStates;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The employee saves a draft of their self-ratings (state self_review; the reviewee only). Any number of times, partial
 * allowed; no state change and no audit entry. Lock order: ReviewLock.
 *
 *   app(SaveSelfRatings::class)->handle($user, $review, [$scoreId => ['rating' => 4, 'comment' => 'Shipped on time']]);
 */
class SaveSelfRatings
{
    /** @param  array<int|string, mixed>  $ratings */
    public function handle(User $by, Review $review, array $ratings): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $ratings));
    }

    /**
     * The checks and writes; the caller holds the cycle (shared) and review (FOR UPDATE) locks.
     *
     * @param  array<int|string, mixed>  $ratings
     */
    public function within(User $by, ReviewCycle $cycle, Review $locked, array $ratings): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::isReviewee($by, $locked)) {
            throw new AuthorizationException('Only the person being reviewed rates themselves.');
        }

        ReviewStates::assertCan(ReviewStates::SELF_DRAFT, $locked->status);
        ReviewRatings::apply($locked, $ratings, 'self');

        return $locked;
    }
}
