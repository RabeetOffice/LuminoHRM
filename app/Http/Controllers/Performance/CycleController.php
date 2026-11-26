<?php

namespace App\Http\Controllers\Performance;

use App\Actions\Performance\AddReviewPeople;
use App\Actions\Performance\ChangeCycleDueDate;
use App\Actions\Performance\CloseReviewCycle;
use App\Actions\Performance\OpenReviewCycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Performance\AddPeopleRequest;
use App\Http\Requests\Performance\DueDateRequest;
use App\Http\Requests\Performance\OpenCycleRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\ListQuery;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewEligibility;
use App\Support\Performance\ReviewPeriods;
use App\Support\Performance\ReviewScoring;
use App\Support\Performance\ReviewStates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Review cycles (performance.manage: Admin and HR, enforced by the routes): open, add people, change the due date, close.
 * Each write is one action (one transaction, audited); refusals come back as validation errors, successes as a plain flash.
 * `show` is the cycle page: progress, state tabs and filters, and the people who are not in the cycle yet.
 */
class CycleController extends Controller
{
    /** Rows per page on the cycle page. */
    public const PER_PAGE = 25;

    /** The most people "not in this cycle" lists (the add request takes at most 500). */
    public const NOT_ADDED_MAX = 500;

    /** The states that still need work before a cycle can close. */
    private const UNFINISHED = [ReviewStates::SELF_REVIEW, ReviewStates::MANAGER_REVIEW, ReviewStates::READY_TO_CLOSE];

    public function show(Request $request, ReviewCycle $cycle): Response|RedirectResponse
    {
        $user = $request->user();
        $today = app(AttendanceClock::class)->attendanceToday();
        $open = $cycle->status === 'open';

        $counts = array_fill_keys(array_keys(ReviewStates::LABELS), 0);

        foreach (Review::query()->toBase()->where('review_cycle_id', $cycle->id)->selectRaw('status, count(*) as n')->groupBy('status')->get() as $row) {
            $counts[$row->status] = (int) $row->n;
        }

        $unfinished = array_sum(array_intersect_key($counts, array_flip(self::UNFINISHED)));
        $overdue = ReviewListController::isOverdue($cycle, $today);

        $search = ListQuery::stringParam($request, 'search');
        $state = $request->query('state');
        $state = is_string($state) && in_array($state, config('hrm.performance.review_states'), true) ? $state : '';
        $department = $this->idParam($request, 'department');
        $reviewerFilter = $request->query('reviewer') === 'hr' ? 'hr' : $this->idParam($request, 'reviewer');
        $onlyOverdue = $request->query('overdue') === '1';

        $query = Review::query()
            ->where('reviews.review_cycle_id', $cycle->id)
            ->with(['employee:id,employee_code,first_name,last_name,department_id', 'employee.department:id,name', 'reviewer:id,first_name,last_name'])
            ->when($state !== '', fn (Builder $q) => $q->where('reviews.status', $state))
            ->when($department !== null, fn (Builder $q) => $q->whereIn('reviews.employee_id', Employee::query()->select('id')->where('department_id', $department)))
            ->when($reviewerFilter === 'hr', fn (Builder $q) => $q->whereNull('reviews.reviewer_id'))
            ->when(is_int($reviewerFilter), fn (Builder $q) => $q->where('reviews.reviewer_id', $reviewerFilter))
            ->when($onlyOverdue, fn (Builder $q) => $overdue ? $q->whereIn('reviews.status', self::UNFINISHED) : $q->whereRaw('1 = 0'))
            ->when($search !== '', fn (Builder $q) => $q->whereHas('employee', fn (Builder $e) => $e->search($search)))
            ->orderBy(Employee::query()->select('first_name')->whereColumn('employees.id', 'reviews.employee_id'))
            ->orderBy('reviews.id');

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $reviews = $paginator->getCollection();
        $reviewerIds = $reviews->pluck('reviewer_id')->filter()->unique()->values()->all();
        $withAccess = $reviewerIds === [] ? [] : User::query()
            ->permission('performance.review-team')
            ->where('users.is_active', true)
            ->whereIn('users.employee_id', $reviewerIds)
            ->pluck('users.employee_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return Inertia::render('performance/cycle', [
            'cycle' => [
                'id' => $cycle->id,
                'name' => $cycle->name,
                'status' => $cycle->status,
                'period_label' => ReviewPeriods::parse($cycle->period_type, $cycle->period_key)['label'] ?? $cycle->name,
                'period_start' => $cycle->period_start->format('Y-m-d'),
                'period_end' => $cycle->period_end->format('Y-m-d'),
                'due_on' => $cycle->due_on->format('Y-m-d'),
                'overdue' => $overdue,
                'self_review_enabled' => $cycle->self_review_enabled,
                'closed_on' => $cycle->closed_at?->format('Y-m-d'),
                'total' => array_sum($counts),
                'unfinished' => $unfinished,
                'close_hint' => $open && $unfinished > 0 ? CloseReviewCycle::unfinishedMessage($unfinished) : null,
            ],
            'counts' => $counts,
            'reviews' => [
                'data' => $reviews->map(fn (Review $r) => $this->row($r, $user, $open, $withAccess))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'state' => $state,
                'search' => $search,
                'department' => $department,
                'reviewer' => $reviewerFilter === null ? '' : (string) $reviewerFilter,
                'overdue' => $onlyOverdue,
            ],
            'options' => [
                'departments' => $this->departments($cycle),
                'reviewers' => $this->reviewersInCycle($cycle),
                'reviewer_choices' => $open ? $this->reviewerChoices() : [],
            ],
            'not_added' => $open ? $this->notAdded($cycle) : ['total' => 0, 'people' => []],
            'can' => [
                'add_people' => $open,
                'close' => $open && $unfinished === 0,
                'waive' => $open,
                'reviewer' => $open,
                'skip' => $open,
                'restore' => $open,
                'reopen' => $open,
            ],
        ]);
    }

    public function store(OpenCycleRequest $request, OpenReviewCycle $open): RedirectResponse
    {
        $cycle = $open->handle($request->user(), (string) $request->validated('period'), $request->validated('due_on'));

        $reviews = $cycle->reviews()->count();
        $noKpis = $cycle->getRelation('no_kpis')->count();

        $message = "{$cycle->name} opened with ".self::count($reviews, 'review', 'reviews').'.';

        if ($noKpis > 0) {
            $message .= ' '.($noKpis === 1 ? '1 person has no KPIs.' : "{$noKpis} people have no KPIs.");
        }

        return back()->with('success', $message);
    }

    public function people(AddPeopleRequest $request, ReviewCycle $cycle, AddReviewPeople $add): RedirectResponse
    {
        $result = $add->handle($request->user(), $cycle, $request->employeeIds());

        $message = self::count($result['added'], 'person', 'people').' added.';

        if ($result['refused'] !== []) {
            $message .= ' '.implode(' ', $result['refused']);
        }

        return back()->with('success', $message);
    }

    public function dueDate(DueDateRequest $request, ReviewCycle $cycle, ChangeCycleDueDate $change): RedirectResponse
    {
        $change->handle($request->user(), $cycle, (string) $request->validated('due_on'));

        return back()->with('success', 'Due date changed.');
    }

    public function close(Request $request, ReviewCycle $cycle, CloseReviewCycle $close): RedirectResponse
    {
        $close->handle($request->user(), $cycle);

        return back()->with('success', 'Cycle closed.');
    }

    /**
     * One review row on the cycle page (HR and Admin only). `can` is what this viewer may do to this review right now, from
     * the state table and ReviewAccess; all false once the cycle is closed.
     *
     * @param  array<int, int>  $withAccess  employee ids of reviewers whose login is active and holds performance.review-team
     * @return array<string, mixed>
     */
    private function row(Review $review, User $viewer, bool $open, array $withAccess): array
    {
        $state = $review->status;
        $manage = $open && ReviewAccess::mayManage($viewer, $review);
        $row = [
            'id' => $review->id,
            'employee' => [
                'id' => $review->employee->id,
                'code' => $review->employee->employee_code,
                'name' => $review->employee->full_name,
            ],
            'department' => $review->employee->department?->name,
            'reviewer' => $review->reviewer_id === null ? 'HR' : $review->reviewer->full_name,
            'reviewer_id' => $review->reviewer_id,
            'reviewer_problem' => $review->reviewer_id !== null
                && in_array($state, self::UNFINISHED, true)
                && ! in_array((int) $review->reviewer_id, $withAccess, true),
            'state' => $state,
            'self_score' => $review->self_score,
            'url' => "/performance/reviews/{$review->id}",
            'can' => [
                'waive' => $manage && ReviewStates::canMove(ReviewStates::WAIVE, $state),
                'reviewer' => $manage && ReviewStates::canMove(ReviewStates::CHANGE_REVIEWER, $state) && $state !== ReviewStates::CLOSED,
                'skip' => $manage && ReviewStates::canMove(ReviewStates::SKIP, $state),
                'restore' => $manage && ReviewStates::canMove(ReviewStates::RESTORE, $state),
                'reopen' => $manage && ReviewStates::canMove(ReviewStates::REOPEN, $state),
            ],
        ];

        if ($state === ReviewStates::CLOSED && $review->final_score !== null) {
            $row['final_score'] = $review->final_score;
            $row['label'] = ReviewScoring::label($review->final_score);
        }

        // HR looking at their own review gets the reviewee view (spec 5.7): no skip reason. Only the sole performance.manage user
        // keeps it, as on the review page (ReviewView).
        if ($state === ReviewStates::SKIPPED && (! ReviewAccess::isReviewee($viewer, $review) || ReviewAccess::soleManager($viewer))) {
            $row['skip_reason'] = $review->skip_reason;
        }

        return $row;
    }

    /** @return array<int, array{id: int, name: string}> */
    private function departments(ReviewCycle $cycle): array
    {
        return Department::query()
            ->whereIn('id', Employee::query()->select('department_id')->whereIn('id', Review::query()->select('employee_id')->where('review_cycle_id', $cycle->id)))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])
            ->values()->all();
    }

    /**
     * The people who review in this cycle, for the filter.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function reviewersInCycle(ReviewCycle $cycle): array
    {
        return Employee::query()
            ->whereIn('id', Review::query()->select('reviewer_id')->where('review_cycle_id', $cycle->id)->whereNotNull('reviewer_id'))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name])
            ->values()->all();
    }

    /**
     * Who HR can name as a reviewer: people with an active login that holds performance.review-team.
     *
     * @return array<int, array{id: int, name: string, code: string}>
     */
    private function reviewerChoices(): array
    {
        return Employee::query()
            ->whereIn('id', User::query()->permission('performance.review-team')->where('users.is_active', true)->whereNotNull('users.employee_id')->select('users.employee_id'))
            ->orderBy('first_name')->orderBy('last_name')->orderBy('id')
            ->get(['id', 'employee_code', 'first_name', 'last_name'])
            ->map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name, 'code' => $e->employee_code])
            ->values()->all();
    }

    /**
     * The people who meet the period rules but have no review here: the ones with KPIs can be added, the others show why not.
     *
     * @return array{total: int, people: array<int, array<string, mixed>>}
     */
    private function notAdded(ReviewCycle $cycle): array
    {
        $found = ReviewEligibility::forPeriod($cycle->period_end->format('Y-m-d'), $cycle->id);
        // One query for both lists: the models are shared, so each list sees the loaded department.
        EloquentCollection::make($found['eligible']->concat($found['no_kpis'])->all())->load('department:id,name');

        $people = $found['eligible']->map(fn (Employee $e) => [$e, true])
            ->concat($found['no_kpis']->map(fn (Employee $e) => [$e, false]));

        return [
            'total' => $people->count(),
            'people' => $people->take(self::NOT_ADDED_MAX)->map(fn (array $pair) => [
                'id' => $pair[0]->id,
                'code' => $pair[0]->employee_code,
                'name' => $pair[0]->full_name,
                'department' => $pair[0]->department?->name,
                'reason' => $pair[1] ? 'Can be added' : 'No KPIs',
                'addable' => $pair[1],
            ])->values()->all(),
        ];
    }

    private function idParam(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_string($value) && ctype_digit($value) && strlen($value) < 10 && (int) $value > 0 ? (int) $value : null;
    }

    private static function count(int $n, string $one, string $many): string
    {
        return $n === 1 ? "1 {$one}" : "{$n} {$many}";
    }
}
