<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Review;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Performance\ReviewScoring;
use App\Support\Performance\ReviewStates;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Reviews: the signed-in person's OWN reviews, for every role.
 *
 *   GET /my/reviews
 *
 * The record is the account's own employee_id; nothing in the URL names a person. An account with no employee record
 * gets the page with employee = null and empty lists. Whoever the viewer is, they get the reviewee view: never a
 * manager rating, comment or score before the review is closed (spec 5.7), and never a skip reason.
 *
 * Props: employee {name} | null,
 *   open    [{id, cycle, due_on, state, action, url}]  not closed or skipped; action is the next step for the person
 *   closed  [{id, cycle, final_score, label, closed_on, url}]  newest first
 *   skipped [{id, cycle, state: "Skipped"}]  no link and no reason
 */
class MyReviewController extends Controller
{
    private const ACTIONS = [
        ReviewStates::SELF_REVIEW => 'Write your self-review',
        ReviewStates::MANAGER_REVIEW => 'With your manager',
        ReviewStates::READY_TO_CLOSE => 'Ready to close',
    ];

    public function show(Request $request, AttendanceClock $clock): Response
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee_id === null ? null : Employee::query()->find($user->employee_id, ['id', 'first_name', 'last_name']);

        if ($employee === null) {
            return Inertia::render('my/reviews', ['employee' => null, 'open' => [], 'closed' => [], 'skipped' => []]);
        }

        // Only the columns a reviewee may see: no manager comment, send-back note or skip reason is ever selected.
        $reviews = Review::query()
            ->join('review_cycles', 'review_cycles.id', '=', 'reviews.review_cycle_id')
            ->where('reviews.employee_id', $employee->id)
            ->orderByDesc('review_cycles.due_on')
            ->orderByDesc('reviews.id')
            ->get(['reviews.id', 'reviews.status', 'reviews.final_score', 'reviews.closed_at', 'review_cycles.name as cycle_name', 'review_cycles.due_on as cycle_due_on']);

        $open = [];
        $closed = [];
        $skipped = [];

        foreach ($reviews as $review) {
            $base = ['id' => (int) $review->id, 'cycle' => (string) $review->getAttribute('cycle_name')];

            if ($review->status === ReviewStates::CLOSED) {
                $score = $review->final_score;
                $closed[] = $base + [
                    'url' => '/performance/reviews/'.$review->id,
                    'final_score' => $score === null ? null : ReviewScoring::format($score),
                    'label' => $score === null ? null : ReviewScoring::label($score),
                    'closed_on' => $review->closed_at?->copy()->setTimezone($clock->zone())->format('Y-m-d'),
                ];
            } elseif ($review->status === ReviewStates::SKIPPED) {
                $skipped[] = $base + ['state' => ReviewStates::label(ReviewStates::SKIPPED)];
            } else {
                $open[] = $base + [
                    'url' => '/performance/reviews/'.$review->id,
                    'due_on' => substr((string) $review->getRawOriginal('cycle_due_on'), 0, 10),
                    'state' => ReviewStates::label($review->status),
                    'action' => self::ACTIONS[$review->status] ?? ReviewStates::label($review->status),
                ];
            }
        }

        usort($closed, fn (array $a, array $b) => [$b['closed_on'], $b['id']] <=> [$a['closed_on'], $a['id']]);

        return Inertia::render('my/reviews', [
            'employee' => ['name' => $employee->full_name],
            'open' => $open,
            'closed' => $closed,
            'skipped' => $skipped,
        ]);
    }
}
