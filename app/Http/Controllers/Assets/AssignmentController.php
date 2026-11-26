<?php

namespace App\Http\Controllers\Assets;

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\EditAssignmentNote;
use App\Actions\Assets\ReturnAsset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assets\AssignAssetRequest;
use App\Http\Requests\Assets\AssignmentNoteRequest;
use App\Http\Requests\Assets\ReturnAssetRequest;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Assets\AssetScope;
use App\Support\Attendance\AttendanceClock;
use App\Support\EmployeeAccess;
use App\Support\ListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Hand-overs: assign, return and the notes. Every route is can:assets.assign (Admin, HR); the employee given to an
 * assign must also be in the user's EmployeeAccess scope (403 otherwise). The actions lock and decide; this answers
 * with a redirect back and a flash. There is no route that edits an assignment's dates or holder, and no delete.
 */
class AssignmentController extends Controller
{
    /** Rows per page on the Assignments list. */
    public const PER_PAGE = 25;

    /**
     * The Assignments list: GET /assets/assignments?status=open|returned|all&search=&category=&department=&page=.
     * Admin and HR (assets.assign) see every hand-over and go on to the return dialog on the asset page; a Manager
     * (assets.view-team) sees only the hand-overs of their reports and themself, read-only: no link to the asset page.
     * Nobody gets a cost here: the asset's cost is never read.
     */
    public function index(Request $request, AttendanceClock $clock): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->canAny(['assets.assign', 'assets.view-team']), 403);

        $all = AssetScope::seesAll($user);
        $today = $clock->attendanceToday();
        $status = $this->choice($request, 'status', ['open', 'returned', 'all'], 'open');
        $search = ListQuery::stringParam($request, 'search');
        $category = $this->idParam($request, 'category');
        $department = $this->idParam($request, 'department');

        $query = AssetAssignment::query()->with([
            'asset:id,asset_code,name,asset_category_id',
            'asset.category:id,name',
            'employee:id,employee_code,first_name,last_name,department_id',
            'employee.department:id,name',
        ]);

        AssetScope::assignments($user, $query);

        match ($status) {
            'open' => $query->open()->orderBy('asset_assignments.assigned_on')->orderBy('asset_assignments.id'),
            'returned' => $query->whereNotNull('asset_assignments.returned_on')
                ->orderByDesc('asset_assignments.returned_on')->orderByDesc('asset_assignments.id'),
            // Still-out rows first (longest held first), then the returned ones, newest first.
            default => $query->orderByRaw('CASE WHEN asset_assignments.returned_on IS NULL THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN asset_assignments.returned_on IS NULL THEN asset_assignments.assigned_on END')
                ->orderByRaw('CASE WHEN asset_assignments.returned_on IS NULL THEN asset_assignments.id END')
                ->orderByRaw('CASE WHEN asset_assignments.returned_on IS NOT NULL THEN asset_assignments.returned_on END DESC')
                ->orderByDesc('asset_assignments.id'),
        };

        if ($category !== null) {
            $query->whereIn('asset_assignments.asset_id', Asset::query()->select('id')->where('asset_category_id', $category));
        }

        if ($department !== null) {
            $query->whereIn('asset_assignments.employee_id', Employee::query()->select('id')->where('department_id', $department));
        }

        $this->search($query, $search);

        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('assets/assignments', [
            'assignments' => [
                'data' => $paginator->getCollection()->map(fn (AssetAssignment $a) => $this->row($a, $today))->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => ['status' => $status, 'search' => $search, 'category' => $category, 'department' => $department],
            'options' => [
                'categories' => AssetCategory::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                    ->map(fn (AssetCategory $c) => ['id' => $c->id, 'name' => $c->name])->all(),
                'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->all(),
            ],
            'scope' => AssetScope::scopeName($user),
            'can' => ['return' => $all],
        ]);
    }

    public function assign(AssignAssetRequest $request, Asset $asset, AssignAsset $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));

        abort_unless((new EmployeeAccess($user))->canView($employee), 403);

        $action->handle($user, $asset, $employee, (string) $request->validated('assigned_on'), $request->validated('note'));

        return back()->with('success', "{$asset->asset_code} assigned to {$employee->full_name}.");
    }

    public function return(ReturnAssetRequest $request, AssetAssignment $assignment, ReturnAsset $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->mustSee($user, $assignment);

        $action->handle(
            $user,
            $assignment,
            (string) $request->validated('returned_on'),
            (string) $request->validated('return_condition'),
            (string) $request->validated('next_status'),
            $request->validated('return_note'),
        );

        $code = Asset::query()->whereKey($assignment->asset_id)->value('asset_code');

        return back()->with('success', "{$code} returned.");
    }

    public function note(AssignmentNoteRequest $request, AssetAssignment $assignment, EditAssignmentNote $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->mustSee($user, $assignment);

        // A key the request did not send leaves that note as it is; a key sent empty clears it.
        $action->handle(
            $user,
            $assignment,
            $request->validated('note'),
            $request->validated('return_note'),
            $request->has('note'),
            $request->has('return_note'),
        );

        return back()->with('success', $action->changed() ? 'Note saved.' : 'Nothing changed.');
    }

    /** Defence in depth: the holder must be someone the user's EmployeeAccess lets them see (403 otherwise). */
    private function mustSee(User $user, AssetAssignment $assignment): void
    {
        $holder = Employee::query()->find($assignment->employee_id);

        abort_unless($holder !== null && (new EmployeeAccess($user))->canView($holder), 403);
    }

    /**
     * Narrows to hand-overs whose asset code, name or serial, or whose employee's name or code, contains the term
     * (wildcards in the term are literal).
     *
     * @param  Builder<AssetAssignment>  $query
     */
    private function search(Builder $query, string $term): void
    {
        if ($term === '') {
            return;
        }

        $pattern = '%'.ListQuery::escapeLike($term).'%';

        $query->where(function (Builder $w) use ($pattern) {
            $assets = Asset::query()->select('id')->where(function (Builder $a) use ($pattern) {
                foreach (['asset_code', 'name', 'serial_number'] as $column) {
                    $a->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
            });
            $people = Employee::query()->select('id')->where(function (Builder $e) use ($pattern) {
                foreach (['first_name', 'last_name', 'employee_code'] as $column) {
                    $e->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [$pattern]);
                }
                // "||" is string concatenation on SQLite but a logical OR on MariaDB, so the full name is built per driver.
                $fullName = $e->getModel()->getConnection()->getDriverName() === 'sqlite'
                    ? "first_name || ' ' || last_name"
                    : "CONCAT(first_name, ' ', last_name)";
                $e->orWhereRaw("LOWER({$fullName}) LIKE ? ESCAPE '!'", [$pattern]);
            });

            $w->whereIn('asset_assignments.asset_id', $assets)->orWhereIn('asset_assignments.employee_id', $people);
        });
    }

    /** @return array<string, mixed> */
    private function row(AssetAssignment $a, string $today): array
    {
        $from = $a->assigned_on->format('Y-m-d');
        $to = $a->returned_on?->format('Y-m-d');

        return [
            'id' => $a->id,
            'asset' => [
                'id' => $a->asset->id,
                'code' => $a->asset->asset_code,
                'name' => $a->asset->name,
                'category' => $a->asset->category?->name,
            ],
            'employee' => [
                'id' => $a->employee->id,
                'code' => $a->employee->employee_code,
                'name' => $a->employee->full_name,
                'department' => $a->employee->department?->name,
            ],
            'assigned_on' => $from,
            'returned_on' => $to,
            'days_held' => $this->daysBetween($from, $to ?? $today),
            'condition_out' => $a->condition_out,
            'return_condition' => $a->return_condition,
        ];
    }

    /** Whole calendar days from one Y-m-d to another (never below 0). */
    private function daysBetween(string $from, string $to): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $end = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        return max(0, (int) $start->diffInDays($end));
    }

    private function idParam(Request $request, string $key): ?int
    {
        $value = $request->query($key);

        return is_scalar($value) && ctype_digit((string) $value) && strlen((string) $value) < 10 ? (int) $value : null;
    }

    /** @param  list<string>  $allowed */
    private function choice(Request $request, string $key, array $allowed, string $default): string
    {
        $value = $request->query($key);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }
}
