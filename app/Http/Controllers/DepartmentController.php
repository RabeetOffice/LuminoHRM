<?php

namespace App\Http\Controllers;

use App\Http\Requests\DepartmentRequest;
use App\Models\Department;
use App\Models\Designation;
use App\Support\ListQuery;
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
    public function index(Request $request): Response|RedirectResponse
    {
        $tab = $request->query('tab') === 'designations' ? 'designations' : 'departments';
        $perPage = ListQuery::perPage($request);
        $search = ListQuery::stringParam($request, 'search');
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

        $departmentsPaginator = $departments->paginate($perPage, ['*'], 'dept_page', ListQuery::page($request, 'dept_page'));
        $designationsPaginator = $designations->paginate($perPage, ['*'], 'desig_page', ListQuery::page($request, 'desig_page'));

        // A page past the end (for example after deleting the last row of the
        // last page): send the browser to the real page so the address is right.
        if ($redirect = ListQuery::clampRedirect($request, ['dept_page' => $departmentsPaginator, 'desig_page' => $designationsPaginator])) {
            return $redirect;
        }

        $departmentsPage = $this->pageData($departmentsPaginator, fn (Department $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'designations_count' => $d->designations_count,
            'created_at' => $d->created_at?->toIso8601String(),
        ]);
        $designationsPage = $this->pageData($designationsPaginator, fn (Designation $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'department_id' => $g->department_id,
            'department_name' => $g->department->name,
            'created_at' => $g->created_at?->toIso8601String(),
        ]);

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
        } catch (QueryException $e) {
            // Only a foreign key refusal (SQLSTATE 23000) is the friendly case; anything else is a real fault.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // A designation or employee was added after the check above and the restrict foreign key refused.
            // Ask again (the late row may or may not have survived the rollback); the designations message is the fallback.
            return back()->with('error', $department->deleteBlocker() ?? "Move or delete this department's designations first.");
        }

        return back()->with('success', 'Department deleted.');
    }

    /** Case-insensitive "contains" on both SQLite and MariaDB; wildcards in the term are literal. */
    private function whereNameLike(Builder $query, string $column, string $term): Builder
    {
        return $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.ListQuery::escapeLike($term).'%']);
    }

    /**
     * The page payload the tables read: the rows mapped to arrays plus the paging numbers.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  LengthAwarePaginator<int, TModel>  $paginator
     * @param  callable(TModel): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function pageData(LengthAwarePaginator $paginator, callable $map): array
    {
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
