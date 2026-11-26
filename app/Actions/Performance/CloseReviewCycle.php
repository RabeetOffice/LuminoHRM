<?php

namespace App\Actions\Performance;

use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closes a cycle once every review in it is closed or skipped. One transaction: the cycle row FOR UPDATE first (so no review
 * action, which takes the cycle row shared, can slip in between "all done" and "closed"), then the cycle's review rows FOR
 * UPDATE by id. A closed cycle is read-only for ever.
 */
class CloseReviewCycle
{
    public const ALREADY = 'This cycle is already closed.';

    public static function unfinishedMessage(int $count): string
    {
        return $count === 1
            ? '1 review is not finished yet. Close or skip it first.'
            : "{$count} reviews are not finished yet. Close or skip them first.";
    }

    public function handle(User $by, ReviewCycle $cycle): ReviewCycle
    {
        return DB::transaction(function () use ($by, $cycle): ReviewCycle {
            $current = ReviewCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status === 'closed') {
                throw ValidationException::withMessages(['cycle' => self::ALREADY]);
            }

            // The ids are read after the cycle lock (nothing can add a review now), then the rows are locked by primary key.
            $ids = Review::query()->where('review_cycle_id', $current->id)->orderBy('id')->pluck('id')->all();
            $statuses = $ids === []
                ? collect()
                : Review::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->pluck('status');

            $unfinished = $statuses->reject(fn (string $s) => in_array($s, ['closed', 'skipped'], true))->count();

            if ($unfinished > 0) {
                throw ValidationException::withMessages(['cycle' => self::unfinishedMessage($unfinished)]);
            }

            $current->forceFill([
                'status' => 'closed',
                'closed_by' => $by->id,
                'closed_at' => $current->freshTimestamp(),
            ])->save();

            Audit::record('performance', 'Review cycle closed', $current, [
                'name' => $current->name,
                'closed' => $statuses->filter(fn (string $s) => $s === 'closed')->count(),
                'skipped' => $statuses->filter(fn (string $s) => $s === 'skipped')->count(),
            ], $by);

            return $current;
        }, 3);
    }
}
