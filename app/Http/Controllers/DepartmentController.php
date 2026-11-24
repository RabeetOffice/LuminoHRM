<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartmentRequest;
use App\Models\Department;
use App\Models\Designation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "Departments & Designations" page. One page, two tabs; each tab has its
 * own pagination parameter (dept_page, desig_page) so they never interfere.
 * Every route is behind the can:org.manage middleware.
 */
class DepartmentController extends Controller
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    /** @var array<string, int> page parameter => real last page, for pages requested past the end */
    private array $clamped = [];

    public function index(Request $request): Response|RedirectResponse
    {
        $tab = $request->query('tab') === 'designations' ? 'designations' : 'departments';
        $perPage = $this->perPage($request);
        $search = $this->searchTerm($request);
        $departmentId = $request->query('department_id');
        $departmentId = is_scalar($departmentId) && ctype_digit((string) $departmentId) ? (int) $departmentId : null;

        $departments = Department::query()
            ->withCount('designations')
            ->when($tab === 'departments' && $search !== '', fn (Builder $q) => $this->whereNameLike($q, 'name', $search))
            ->orderBy('name');

        $designations = Designation::query()
            ->with('department:id,name')
            ->when($departmentId, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->when($tab === 'designations' && $search !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($search) {
                $this->whereNameLike($w, 'name', $search);
                $w->orWhereHas('department', fn (Builder $d) => $this->whereNameLike($d, 'name', $search));
            }))
            ->orderBy('name')
            ->orderBy('id');

        $departmentsPage = $this->page(
            $departments, $perPage, 'dept_page', $request,
            fn (Department $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'designations_count' => $d->designations_count,
                'created_at' => $d->created_at?->toIso8601String(),
            ],
        );
        $designationsPage = $this->page(
            $designations, $perPage, 'desig_page', $request,
            fn (Designation $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'department_id' => $g->department_id,
                'department_name' => $g->department->name,
                'created_at' => $g->created_at?->toIso8601String(),
            ],
        );

        // A page past the end (for example after deleting the last row of the
        // last page): send the browser to the real page so the address is right.
        if ($this->clamped !== []) {
            return redirect()->to($request->fullUrlWithQuery(
                array_map(fn (int $page) => $page === 1 ? null : $page, $this->clamped),
            ));
        }

        return Inertia::render('organization/index', [
            'departments' => $departmentsPage,
            'designations' => $designationsPage,
            // Totals for the tab badges: never narrowed by search or filters.
            'counts' => [
                'departments' => Department::count(),
                'designations' => Designation::count(),
            ],
            'departmentOptions' => Department::query()->orderBy('name')->get(['id', 'name']),
            'filters' => [
                'tab' => $tab,
                'search' => $search,
                'per_page' => $perPage,
                'department_id' => $departmentId,
            ],
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        try {
            // One transaction with the audit entry the model writes: no entry, no change.
            DB::transaction(fn () => Department::create(['name' => $request->validated('name')]));
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A department with this name already exists.']);
        }

        return back()->with('success', 'Department added.');
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        try {
            DB::transaction(fn () => $department->update(['name' => $request->validated('name')]));
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['name' => 'A department with this name already exists.']);
        }

        return back()->with('success', 'Department updated.');
    }

    public function destroy(Department $department): RedirectResponse
    {
        if ($reason = $department->deleteBlocker()) {
            return back()->with('error', $reason);
        }

        try {
            DB::transaction(fn () => $department->delete());
        } catch (QueryException) {
            // A designation was added after the check above; the restrict foreign key refused.
            return back()->with('error', "Move or delete this department's designations first.");
        }

        return back()->with('success', 'Department deleted.');
    }

    /** Rows per page: 10, 25 or 50; anything else falls back to 10. */
    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (is_scalar($value) && ctype_digit((string) $value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true)) {
            return (int) $value;
        }

        return self::PER_PAGE_OPTIONS[0];
    }

    private function searchTerm(Request $request): string
    {
        $value = $request->query('search');

        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** Case-insensitive "contains" on both SQLite and MariaDB; wildcards in the term are literal. */
    private function whereNameLike(Builder $query, string $column, string $term): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));

        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
    }

    /**
     * Paginates a query under its own page parameter and maps rows to arrays.
     * A page past the end (for example after deleting the last row of the last
     * page) is recorded in $clamped so index() can redirect to the real page.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function page(Builder $query, int $perPage, string $pageName, Request $request, callable $map): array
    {
        $requested = $request->query($pageName);
        $page = is_scalar($requested) && ctype_digit((string) $requested) ? max(1, (int) $requested) : 1;

        /** @var LengthAwarePaginator<int, TModel> $paginator */
        $paginator = $query->paginate($perPage, ['*'], $pageName, $page);

        if ($page > $paginator->lastPage()) {
            $this->clamped[$pageName] = $paginator->lastPage();
            $paginator = $query->paginate($perPage, ['*'], $pageName, $paginator->lastPage());
        }

        return [
            'data' => $paginator->getCollection()->map($map)->values()->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
