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
use Illuminate\Validation\ValidationException;

/**
 * HR skips an open review (self_review, manager_review or ready_to_close -> skipped; ReviewAccess::mayManage) with a reason
 * (required, up to 500). skipped_from keeps the old state for RestoreReview; ratings and drafts stay. Audit `Review skipped`
 * (reason, from). Lock order: ReviewLock.
 */
class SkipReview
{
    public const REASON_REQUIRED = 'Give a reason for skipping.';

    public const REASON_TOO_LONG = 'The reason can be at most 500 characters.';

    public function handle(User $by, Review $review, string $reason): Review
    {
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $reason));
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked, string $reason): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can skip a review.');
        }

        ReviewStates::assertCan(ReviewStates::SKIP, $locked->status);

        $reason = ReviewRatings::cleanText($reason, 500, 'reason', self::REASON_TOO_LONG)
            ?? throw ValidationException::withMessages(['reason' => self::REASON_REQUIRED]);

        $from = $locked->status;

        $locked->forceFill([
            'status' => ReviewStates::target(ReviewStates::SKIP, $from),
            'skip_reason' => $reason,
            'skipped_from' => $from,
        ])->save();

        ReviewRatings::audit('Review skipped', $locked, $cycle, ['reason' => $reason, 'from' => $from], $by);

        return $locked;
    }
}
