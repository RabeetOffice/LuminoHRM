<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\CorrectionRequests;
use App\Http\Controllers\Controller;
use App\Http\Requests\CorrectionDecisionRequest;
use App\Http\Requests\CorrectionRequestRequest;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Support\Attendance\CorrectionRouting;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Attendance > Correction Requests.
 *
 *   GET  /attendance/corrections?tab=pending|approved|rejected|cancelled   attendance.view-all (everyone's) or
 *        attendance.view-team (own and direct reports'); others 403 (their own requests live on My Attendance)
 *   POST /attendance/corrections                       any signed-in user with an employee record, for their own day
 *   PUT  /attendance/corrections/{correction}/manager-decide   the reporting manager (CorrectionRouting)
 *   PUT  /attendance/corrections/{correction}/hr-decide        attendance.edit, not the requester, not the manager who approved
 *   POST /attendance/corrections/{correction}/cancel           the requester, while nobody has decided
 *
 * A request outside the user's scope is 403 on every write, whatever the id in the URL. A stale decision (someone
 * else decided or the requester cancelled first) comes back as an error message, never as a second apply.
 */
class CorrectionController extends Controller
{
    private const TABS = [
        'pending' => ['pending', 'manager_approved'],
        'approved' => ['approved'],
        'rejected' => ['rejected'],
        'cancelled' => ['cancelled'],
    ];

    public function __construct(private readonly CorrectionRouting $routing) {}

    public function index(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['attendance.view-all', 'attendance.view-team']), 403);

        $tab = ListQuery::stringParam($request, 'tab');
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'pending';
        $perPage = ListQuery::perPage($request);

        $scoped = fn (): Builder => $this->routing->scope(AttendanceCorrection::query(), $user);

        $paginator = $scoped()
            ->whereIn('status', self::TABS[$tab])
            ->with(['employee:id,employee_code,first_name,last_name,manager_id', 'managerDecider:id,name', 'hrDecider:id,name'])
            ->orderBy($tab === 'pending' ? 'date' : 'updated_at', $tab === 'pending' ? 'asc' : 'desc')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        /** @var Collection<int, AttendanceCorrection> $items */
        $items = $paginator->getCollection();
        $current = $this->currentTimes($items);

        $counts = $scoped()->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        return Inertia::render('attendance/corrections', [
            'tab' => $tab,
            'requests' => [
                'data' => $items->map(fn (AttendanceCorrection $c) => $this->row($c, $user, $current))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => collect(self::TABS)->map(fn (array $statuses) => (int) collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0)))->all(),
        ]);
    }

    public function store(CorrectionRequestRequest $request, CorrectionRequests $requests): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $user->employee;

        abort_if($employee === null, 403, 'Your login is not linked to an employee record.');

        $requests->submit(
            $user,
            $employee,
            (string) $request->validated('date'),
            $request->validated('clock_in'),
            $request->validated('clock_out'),
            (string) $request->validated('reason'),
        );

        return back()->with('success', 'Correction request sent.');
    }

    public function managerDecide(CorrectionDecisionRequest $request, CorrectionRequests $requests, AttendanceCorrection $correction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->routing->canSee($user, $correction), 403);

        return $this->attempt(fn () => $requests->decideAsManager($user, $correction, $request->approves(), $request->note()));
    }

    public function hrDecide(CorrectionDecisionRequest $request, CorrectionRequests $requests, AttendanceCorrection $correction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->routing->canSee($user, $correction), 403);

        return $this->attempt(fn () => $requests->decideAsHr($user, $correction, $request->approves(), $request->note()));
    }

    public function cancel(Request $request, CorrectionRequests $requests, AttendanceCorrection $correction): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($this->routing->isRequester($user, $correction), 403);

        return $this->attempt(function () use ($requests, $user, $correction): string {
            $requests->cancel($user, $correction);

            return 'Correction request cancelled.';
        });
    }

    /** Runs a write; a refused or stale one comes back as an error message on the page. */
    private function attempt(callable $write): RedirectResponse
    {
        try {
            return back()->with('success', $write());
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }
    }

    /**
     * The recorded times of each request's day now, keyed "employee_id|date".
     *
     * @param  Collection<int, AttendanceCorrection>  $items
     * @return array<string, array{clock_in: string|null, clock_out: string|null, is_manual: bool}>
     */
    private function currentTimes(Collection $items): array
    {
        if ($items->isEmpty()) {
            return [];
        }

        $dates = $items->map(fn (AttendanceCorrection $c) => $c->date->format('Y-m-d'));

        return Attendance::query()
            ->whereIn('employee_id', $items->pluck('employee_id')->unique()->values())
            ->betweenDates($dates->min(), $dates->max())
            ->get(['employee_id', 'date', 'clock_in', 'clock_out', 'is_manual'])
            ->mapWithKeys(function (Attendance $a): array {
                $raw = $a->getAttributes();

                return [$a->employee_id.'|'.substr((string) $raw['date'], 0, 10) => [
                    'clock_in' => $raw['clock_in'] === null ? null : substr((string) $raw['clock_in'], 11, 5),
                    'clock_out' => $raw['clock_out'] === null ? null : substr((string) $raw['clock_out'], 11, 5),
                    'is_manual' => (bool) $a->is_manual,
                ]];
            })
            ->all();
    }

    /**
     * @param  array<string, array{clock_in: string|null, clock_out: string|null, is_manual: bool}>  $current
     * @return array<string, mixed>
     */
    private function row(AttendanceCorrection $c, User $user, array $current): array
    {
        $date = $c->date->format('Y-m-d');
        $stage = $this->routing->stage($c);

        return [
            'id' => $c->id,
            'employee' => [
                'id' => $c->employee->id,
                'name' => $c->employee->full_name,
                'code' => $c->employee->employee_code,
            ],
            'date' => $date,
            'current' => $current[$c->employee_id.'|'.$date] ?? ['clock_in' => null, 'clock_out' => null, 'is_manual' => false],
            'requested' => ['clock_in' => $c->requested_in, 'clock_out' => $c->requested_out],
            'reason' => $c->reason,
            'status' => $c->status,
            'stage' => $stage,
            'decision_note' => $c->decision_note,
            'manager_decider' => $c->managerDecider?->name,
            'hr_decider' => $c->hrDecider?->name,
            'is_own' => $this->routing->isRequester($user, $c),
            'can' => [
                'decide' => $this->routing->decidableStage($user, $c),
                'cancel' => $this->routing->canCancel($user, $c),
            ],
        ];
    }
}
