<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\ListQuery;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewPeriods;
use App\Support\Performance\ReviewScoring;
use App\Support\Performance\ReviewStates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Performance > Reviews. HR and Admin (performance.manage) see the cycles and can open one; a manager (performance.review-team
 * only) sees "Team reviews": the reviews they rate in open cycles and the closed history of their current reports, read-only,
 * and never a score or any rating text on a review that is not closed. Anyone else is 403. It lives in its own controller
 * so the review page's controller stays with the review workflow.
 */
class ReviewListController extends Controller
{
    /** Rows per page on the team list. */
    public const PER_PAGE = 25;

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['performance.manage', 'performance.review-team']), 403);

        return $user->can('performance.manage') ? $this->cycles() : $this->team($request, $user);
    }

    private function cycles(): Response
    {
        $today = app(AttendanceClock::class)->attendanceToday();
        $cycles = ReviewCycle::query()->orderByDesc('period_start')->orderByDesc('id')->get();

        $counts = [];

        foreach (Review::query()->toBase()->selectRaw('review_cycle_id, status, count(*) as n')->groupBy('review_cycle_id', 'status')->get() as $row) {
            $counts[$row->review_cycle_id][$row->status] = (int) $row->n;
        }

        $type = ReviewPeriods::type();

        return Inertia::render('performance/reviews', [
            'view' => 'cycles',
            'cycles' => $cycles->map(fn (ReviewCycle $c) => self::cycleRow($c, $counts[$c->id] ?? [], $today))->values()->all(),
            'open_options' => [
                'type' => $type,
                'periods' => array_map(fn (array $p) => [
                    'key' => $p['key'],
                    'label' => $p['label'],
                    'start' => $p['start'],
                    'end' => $p['end'],
                    'due_on' => ReviewPeriods::dueDefault($p['end']),
                ], ReviewPeriods::offered($type, $today, $cycles)),
            ],
            'can' => ['open' => true],
        ]);
    }

    /**
     * @param  array<string, int>  $counts  review state => number
     * @return array<string, mixed>
     */
    public static function cycleRow(ReviewCycle $cycle, array $counts, string $today): array
    {
        $byState = [];

        foreach (array_keys(ReviewStates::LABELS) as $state) {
            $byState[$state] = $counts[$state] ?? 0;
        }

        $total = array_sum($byState);
        $counted = $total - $byState[ReviewStates::SKIPPED];

        return [
            'id' => $cycle->id,
            'name' => $cycle->name,
            'status' => $cycle->status,
            'period_start' => $cycle->period_start->format('Y-m-d'),
            'period_end' => $cycle->period_end->format('Y-m-d'),
            'due_on' => $cycle->due_on->format('Y-m-d'),
            'overdue' => self::isOverdue($cycle, $today),
            'counts' => $byState,
            'total' => $total,
            'progress' => $counted === 0 ? 'No reviews yet' : "{$byState[ReviewStates::CLOSED]} of {$counted} closed",
        ];
    }

    /** A cycle is overdue after its due date, and only while it is open. */
    public static function isOverdue(ReviewCycle $cycle, string $today): bool
    {
        return $cycle->status === 'open' && $cycle->due_on->format('Y-m-d') < $today;
    }

    private function team(Request $request, User $user): Response|RedirectResponse
    {
        $me = (int) $user->employee_id;
        $search = ListQuery::stringParam($request, 'search');
        $state = $request->query('state');
        $state = is_string($state) && in_array($state, config('hrm.performance.review_states'), true) ? $state : '';
        $cycleId = $request->query('cycle');
        $cycleId = is_string($cycleId) && ctype_digit($cycleId) && strlen($cycleId) < 10 ? (int) $cycleId : null;

        // Their own reviews belong on My Reviews: this list is about other people.
        $base = ReviewAccess::scope(Review::query(), $user)->where('reviews.employee_id', '!=', $me);

        $query = (clone $base)
            ->with(['employee:id,employee_code,first_name,last_name,department_id', 'employee.department:id,name', 'cycle:id,name,status', 'reviewer:id,first_name,last_name'])
            ->when($cycleId !== null, fn (Builder $q) => $q->where('reviews.review_cycle_id', $cycleId))
            ->when($state !== '', fn (Builder $q) => $q->where('reviews.status', $state))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->search($search)))
            ->orderByDesc('reviews.review_cycle_id')
            ->orderBy(Employee::query()->select('first_name')->whereColumn('employees.id', 'reviews.employee_id'))
            ->orderBy('reviews.id');

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $cycles = ReviewCycle::query()
            ->whereIn('id', (clone $base)->select('reviews.review_cycle_id'))
            ->orderByDesc('period_start')->orderByDesc('id')
            ->get(['id', 'name']);

        return Inertia::render('performance/reviews', [
            'view' => 'team',
            'team' => [
                'data' => $paginator->getCollection()->map(fn (Review $r) => self::teamRow($r, $me))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['search' => $search, 'cycle' => $cycleId, 'state' => $state],
            'options' => [
                'cycles' => $cycles->map(fn (ReviewCycle $c) => ['id' => $c->id, 'name' => $c->name])->values()->all(),
            ],
        ]);
    }

    /**
     * One team row. Nothing about the review's content: no self score, no ratings, no comments. The final score and its
     * label (`score`, `label`) are added only when the review is closed.
     *
     * @return array<string, mixed>
     */
    private static function teamRow(Review $review, int $me): array
    {
        $row = [
            'id' => $review->id,
            'employee' => [
                'id' => $review->employee->id,
                'code' => $review->employee->employee_code,
                'name' => $review->employee->full_name,
                'department' => $review->employee->department?->name,
            ],
            'cycle' => $review->cycle->name,
            'state' => $review->status,
            'reviewer' => $review->reviewer_id === null ? 'HR' : $review->reviewer->full_name,
            'awaiting_me' => $review->status === ReviewStates::MANAGER_REVIEW
                && $review->reviewer_id !== null
                && (int) $review->reviewer_id === $me
                && $review->cycle->status === 'open',
            'url' => "/performance/reviews/{$review->id}",
        ];

        if ($review->status === ReviewStates::CLOSED && $review->final_score !== null) {
            $row['score'] = $review->final_score;
            $row['label'] = ReviewScoring::label($review->final_score);
        }

        return $row;
    }
}
