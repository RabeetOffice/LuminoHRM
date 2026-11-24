<?php

namespace App\Http\Controllers;

use App\Http\Resources\EmployeeListResource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Support\EmployeeAccess;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Employees. Which rows and which fields a person gets is decided by App\Support\EmployeeAccess,
 * never here and never in the page.
 */
class EmployeeController extends Controller
{
    public const VIEWS = ['list', 'grid'];

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['employees.view-all', 'employees.view-team']), 403);

        $access = new EmployeeAccess($user);
        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->existingId($request, 'department_id', Department::query());
        // With a department chosen, only that department's designations count.
        $designationId = $this->existingId(
            $request,
            'designation_id',
            Designation::query()->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId)),
        );
        $status = $this->status($request);
        $perPage = ListQuery::perPage($request);

        // Everything the viewer may see, narrowed by every filter except the status tab.
        $base = fn (): Builder => $access->scope(Employee::query())
            ->search($search)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->when($designationId !== null, fn (Builder $q) => $q->where('designation_id', $designationId));

        $paginator = $base()
            ->when($status !== '', fn (Builder $q) => $q->status($status))
            // Explicit columns: only what the list shows leaves the database.
            ->select(['id', 'employee_code', 'first_name', 'last_name', 'email', 'department_id', 'designation_id', 'status', 'joining_date'])
            ->with([
                'department:id,name',
                'designation:id,name',
                'user:id,employee_id,is_active',
            ])
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        return Inertia::render('employees/index', [
            'employees' => [
                'data' => $paginator->getCollection()
                    ->map(fn (Employee $e) => (new EmployeeListResource($e))->resolve($request))
                    ->values()
                    ->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'counts' => $this->counts($base()),
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId,
                'designation_id' => $designationId,
                'status' => $status,
                'view' => $this->view($request),
                'per_page' => $perPage,
            ],
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'designations' => Designation::query()
                ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
                ->orderBy('name')
                ->orderBy('id')
                ->get(['id', 'name'])
                ->all(),
            'can' => [
                'manage' => $access->canManage(),
                // Per record the answer also depends on history (see EmployeeAccess::canDelete); the server decides then.
                'delete' => $user->can('employees.delete'),
                'import' => $access->canManage(),
                'export' => $access->canManage(),
            ],
        ]);
    }

    /**
     * Rows per status in one grouped query; "all" is their sum.
     *
     * @param  Builder<Employee>  $query
     * @return array<string, int>
     */
    private function counts(Builder $query): array
    {
        $grouped = $query
            ->toBase()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = [];
        foreach (config('hrm.employees.statuses') as $status) {
            $counts[$status] = (int) ($grouped[$status] ?? 0);
        }

        return ['all' => array_sum($counts), ...$counts];
    }

    /**
     * The id in the query parameter if it names a row of $rows; null for anything else (never an error).
     *
     * @param  Builder<Department>|Builder<Designation>  $rows
     */
    private function existingId(Request $request, string $key, Builder $rows): ?int
    {
        $value = $request->query($key);

        if (! is_scalar($value) || ! ctype_digit((string) $value) || strlen((string) $value) > 9) {
            return null;
        }

        $id = (int) $value;

        return $rows->whereKey($id)->exists() ? $id : null;
    }

    private function status(Request $request): string
    {
        $value = $request->query('status');

        return is_string($value) && in_array($value, config('hrm.employees.statuses'), true) ? $value : '';
    }

    private function view(Request $request): string
    {
        $value = $request->query('view');

        return is_string($value) && in_array($value, self::VIEWS, true) ? $value : self::VIEWS[0];
    }
}
