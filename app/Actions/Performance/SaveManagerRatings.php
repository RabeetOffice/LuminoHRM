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
 * The reviewer saves a draft of the manager ratings and the overall comment (state manager_review; ReviewAccess::mayRate).
 * Partial allowed; no state change, no audit entry. A null overall comment clears it; a request without the manager_comment key keeps it (commentSent). Lock order: ReviewLock.
 */
class SaveManagerRatings
{
    /** @param  array<int|string, mixed>  $ratings */
    public function handle(User $by, Review $review, array $ratings, ?string $overallComment, bool $commentSent = true): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $ratings, $overallComment, $commentSent), $by);
    }

    /** @param  array<int|string, mixed>  $ratings */
    public function within(User $by, ReviewCycle $cycle, Review $locked, array $ratings, ?string $overallComment, bool $commentSent = true): Review
    {
        self::check($by, $cycle, $locked, ReviewStates::MANAGER_DRAFT);
        self::write($locked, $ratings, $overallComment, $commentSent);

        return $locked;
    }

    /** The checks shared with SubmitManagerReview: cycle open, then the actor, then the state. */
    public static function check(User $by, ReviewCycle $cycle, Review $locked, string $action): void
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayRate($by, $locked)) {
            throw new AuthorizationException('Only the reviewer rates this review.');
        }

        ReviewStates::assertCan($action, $locked->status);
    }

    /**
     * The overall comment changes only when it was sent ($commentSent: the request had the manager_comment key; null or an
     * empty text then clears it). A request without the key keeps the saved comment, like a row left out of the ratings.
     *
     * @param  array<int|string, mixed>  $ratings
     */
    public static function write(Review $locked, array $ratings, ?string $overallComment, bool $commentSent = true): void
    {
        ReviewRatings::apply($locked, $ratings, 'manager');

        if ($commentSent) {
            $locked->manager_comment = ReviewRatings::cleanText($overallComment, 2000, 'manager_comment', ReviewRatings::OVERALL_TOO_LONG);
        }

        if ($locked->isDirty()) {
            $locked->save();
        }
    }
}
