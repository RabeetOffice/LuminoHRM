<?php

namespace App\Support\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use App\Models\User;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one lock order of every review action (spec 5.9), in ONE transaction (DB::transaction(fn, 3)):
 *   1. a plain read of the review's review_cycle_id (to know which cycle row to lock);
 *   2. the cycle row with a shared lock (CloseReviewCycle takes it exclusively, so no review changes while a cycle closes);
 *   3. the review row FOR UPDATE: the re-read every check is made on.
 * Never a review row and then a cycle row.
 *
 * Employee and user rows are never locked AFTER a review row. When an action needs a current read of one (it is a decision
 * that a concurrent change would invalidate), the lock is taken BEFORE the cycle row, right after step 1, and nothing is
 * locked on those tables later:
 *   - $by: when the actor is the reviewee and holds performance.manage, the active performance.manage users are
 *     share-locked (ReviewAccess::lockManagers), so "is this the sole manager" is read current, not from the snapshot;
 *   - $before: the action's own early locks (ChangeReviewer locks the new reviewer's employee and user rows).
 * Nothing else locks employees, payroll, leave or assets.
 *
 * The plain read in step 1 fixes the MariaDB REPEATABLE READ snapshot, so everything read after it that another action may
 * have changed meanwhile is read with a lock: the review row (step 3) and its score rows (scores(), FOR UPDATE).
 *
 *   ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $action->within($by, $cycle, $locked, ...));
 */
final class ReviewLock
{
    public const CYCLE_CLOSED = 'This cycle is closed.';

    /**
     * @template T
     *
     * @param  Closure(ReviewCycle, Review): T  $within
     * @return T
     */
    public static function run(Review $review, Closure $within, ?User $by = null, ?Closure $before = null): mixed
    {
        $id = $review->getKey();

        return DB::transaction(function () use ($id, $within, $by, $before) {
            $head = Review::query()->whereKey($id)->first(['review_cycle_id', 'employee_id']);

            if ($head === null) {
                throw ValidationException::withMessages(['review' => 'This review no longer exists.']);
            }

            $cycleId = $head->review_cycle_id;

            if ($by !== null && $by->employee_id !== null && (int) $by->employee_id === (int) $head->employee_id && $by->can('performance.manage')) {
                ReviewAccess::lockManagers();
            }

            if ($before !== null) {
                $before();
            }

            $cycle = ReviewCycle::query()->whereKey($cycleId)->sharedLock()->firstOrFail();
            $locked = Review::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('cycle', $cycle);

            return $within($cycle, $locked);
        }, 3);
    }

    /** @throws ValidationException when the cycle is closed. */
    public static function assertCycleOpen(ReviewCycle $cycle, string $message = self::CYCLE_CLOSED): void
    {
        if ($cycle->status !== 'open') {
            throw ValidationException::withMessages(['review' => $message]);
        }
    }

    /**
     * The review's score rows, read FOR UPDATE (current values, not the transaction's snapshot), set as its `scores`
     * relation. The caller holds the review row lock.
     *
     * @return Collection<int, ReviewScore>
     */
    public static function scores(Review $locked): Collection
    {
        $scores = ReviewScore::query()->where('review_id', $locked->getKey())
            ->orderBy('sort_order')->orderBy('id')->lockForUpdate()->get();
        $locked->setRelation('scores', $scores);

        return $scores;
    }
}
