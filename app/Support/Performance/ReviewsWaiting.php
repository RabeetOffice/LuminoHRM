<?php

namespace App\Support\Performance;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's "Reviews waiting for you" card: what is waiting on the viewer in open cycles, as counts and up to five
 * links. Two queries on `reviews` whatever the counts (one grouped count, one list).
 *
 *   self   the person's own reviews in self_review
 *   rate   reviews they rate in manager_review: as the reviewer (needs performance.review-team), or, for a review with no
 *          reviewer ("HR"), with performance.manage; never their own
 *   close  reviews in ready_to_close, only with performance.manage; never their own
 *
 * No score, rating or comment is read. A reviewee's name appears only on a review the viewer may open (they rate or close it).
 */
final class ReviewsWaiting
{
    public const ITEMS = 5;

    /** @return array{self: int, rate: int, close: int, items: list<array{id: int, label: string, url: string}>} */
    public static function for(User $user): array
    {
        $empty = ['self' => 0, 'rate' => 0, 'close' => 0, 'items' => []];
        $me = $user->employee_id;

        if ($me === null) {
            return $empty;
        }

        $me = (int) $me;
        $manage = $user->can('performance.manage');
        $team = $user->can('performance.review-team');

        $base = fn (): Builder => Review::query()
            ->join('review_cycles', 'review_cycles.id', '=', 'reviews.review_cycle_id')
            ->where('review_cycles.status', 'open')
            ->where(function (Builder $w) use ($me, $manage, $team) {
                $w->where(fn (Builder $own) => $own->where('reviews.employee_id', $me)->where('reviews.status', ReviewStates::SELF_REVIEW));

                if ($manage || $team) {
                    $w->orWhere(function (Builder $rate) use ($me, $manage, $team) {
                        $rate->where('reviews.employee_id', '!=', $me)->where('reviews.status', ReviewStates::MANAGER_REVIEW)
                            ->where(function (Builder $who) use ($me, $manage, $team) {
                                $who->whereRaw('1 = 0');

                                if ($team) {
                                    $who->orWhere('reviews.reviewer_id', $me);
                                }

                                if ($manage) {
                                    $who->orWhereNull('reviews.reviewer_id');
                                }
                            });
                    });
                }

                if ($manage) {
                    $w->orWhere(fn (Builder $close) => $close->where('reviews.employee_id', '!=', $me)->where('reviews.status', ReviewStates::READY_TO_CLOSE));
                }
            });

        $byStatus = $base()->toBase()->selectRaw('reviews.status, COUNT(*) as aggregate')->groupBy('reviews.status')->pluck('aggregate', 'status');

        $counts = [
            'self' => (int) ($byStatus[ReviewStates::SELF_REVIEW] ?? 0),
            'rate' => (int) ($byStatus[ReviewStates::MANAGER_REVIEW] ?? 0),
            'close' => (int) ($byStatus[ReviewStates::READY_TO_CLOSE] ?? 0),
        ];

        if (array_sum($counts) === 0) {
            return $empty;
        }

        $rows = $base()
            ->join('employees', 'employees.id', '=', 'reviews.employee_id')
            ->orderByDesc('review_cycles.due_on')
            ->orderByDesc('reviews.id')
            ->limit(self::ITEMS)
            ->get(['reviews.id', 'reviews.status', 'review_cycles.name as cycle_name', 'employees.first_name', 'employees.last_name']);

        $items = [];

        foreach ($rows as $row) {
            $who = trim($row->getAttribute('first_name').' '.$row->getAttribute('last_name'));
            $cycle = (string) $row->getAttribute('cycle_name');

            $items[] = [
                'id' => (int) $row->id,
                'label' => match ($row->status) {
                    ReviewStates::SELF_REVIEW => "Write your self-review for {$cycle}",
                    ReviewStates::MANAGER_REVIEW => "Rate {$who} for {$cycle}",
                    default => "Close {$who}'s review for {$cycle}",
                },
                'url' => '/performance/reviews/'.$row->id,
            ];
        }

        return [...$counts, 'items' => $items];
    }
}
