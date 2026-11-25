<?php

namespace App\Http\Controllers\Attendance;

use App\Exports\MonthlyAttendanceExport;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\MonthGrid;
use App\Support\Audit\Audit;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\ListQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Attendance > Monthly: employees x days for one month (MonthGrid), and the same month as an .xlsx.
 *
 * GET /attendance/monthly?month=YYYY-MM&search=&department_id=&page=  (attendance.view-all or attendance.view-team)
 * GET /attendance/monthly/export?month=YYYY-MM&search=&department_id= (attendance.view-all, route middleware)
 *
 * People: EmployeeAccess::scope, narrowed further for a viewer without attendance.view-all to their direct reports
 * (a Manager's own days are on My Attendance), and to people employed at some point in the month. A missing or
 * malformed month means the current one; a month that has not started is refused (back to the current month).
 * Reads per page: the people (and their designations), one query for their attendance rows, one for holidays.
 */
class MonthlyController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request, AttendanceClock $clock): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['attendance.view-all', 'attendance.view-team']), 403);

        $grid = $this->grid($request, $clock);
        if ($grid instanceof RedirectResponse) {
            return $grid;
        }

        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->departmentId($request);
        $perPage = $this->perPage($request);

        $paginator = $this->people($user, $grid, $search, $departmentId)
            ->with('designation:id,name')
            ->paginate($perPage, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $people = $paginator->getCollection();
        $rows = $grid->rows($people);
        $current = MonthGrid::currentMonth($clock);

        return Inertia::render('attendance/monthly', [
            'month' => [
                'value' => $grid->month,
                'label' => $grid->label(),
                'previous' => $grid->shift(-1),
                'next' => $grid->month < $current ? $grid->shift(1) : null,
                'current' => $current,
            ],
            'days' => $grid->days(),
            'employees' => [
                'data' => $people->map(fn (Employee $e) => [
                    'id' => $e->id,
                    'name' => $e->full_name,
                    'code' => $e->employee_code,
                    'designation' => $e->designation?->name,
                    'photo_url' => EmployeeFiles::photoUrl($e),
                    ...$rows[$e->id],
                ])->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId,
            ],
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'can' => [
                'export' => $user->can('attendance.view-all'),
                'edit' => $user->can('attendance.edit'),
            ],
        ]);
    }

    public function export(Request $request, AttendanceClock $clock): BinaryFileResponse|RedirectResponse
    {
        $user = $request->user();

        $grid = $this->grid($request, $clock);
        if ($grid instanceof RedirectResponse) {
            return $grid;
        }

        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->departmentId($request);

        $people = $this->people($user, $grid, $search, $departmentId)
            ->with(['designation:id,name', 'department:id,name'])
            ->get();

        // Before the file is built: an export that cannot be logged does not happen. Never row data.
        Audit::record('attendance', 'Monthly attendance exported', null, [
            'month' => $grid->month,
            'rows' => $people->count(),
            'filters' => ['search' => $search, 'department_id' => $departmentId],
        ], $user);

        return Excel::download(
            new MonthlyAttendanceExport($grid->days(), array_values($people->all()), $grid->rows($people)),
            "attendance-{$grid->month}.xlsx",
            ExcelType::XLSX,
        );
    }

    /** The requested month's grid; a redirect to the current month when it has not started yet. */
    private function grid(Request $request, AttendanceClock $clock): MonthGrid|RedirectResponse
    {
        $grid = MonthGrid::for($request->query('month'), $clock)
            ?? MonthGrid::for(MonthGrid::currentMonth($clock), $clock);

        if ($grid === null) {
            abort(500); // the current month is always a valid month
        }

        if ($grid->isFuture()) {
            return redirect()->route('attendance.monthly', ['month' => MonthGrid::currentMonth($clock)])
                ->with('error', 'That month has not started yet.');
        }

        return $grid;
    }

    /**
     * The people this viewer may see on the grid, employed at some point in the month, in name order.
     *
     * @return Builder<Employee>
     */
    private function people(User $user, MonthGrid $grid, string $search, ?int $departmentId): Builder
    {
        $query = (new EmployeeAccess($user))->scope(Employee::query());

        if (! $user->can('attendance.view-all')) {
            // Team view: direct reports only.
            $query->where('manager_id', $user->employee_id ?? 0);
        }

        return $query
            ->select(['id', 'employee_code', 'first_name', 'last_name', 'department_id', 'designation_id', 'photo_path',
                'joining_date', 'last_working_day', 'updated_at'])
            ->search($search)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->where('joining_date', '<=', $grid->last)
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $grid->first))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->orderBy('id');
    }

    /** department_id when it names an existing department; null for anything else (never an error). */
    private function departmentId(Request $request): ?int
    {
        $value = $request->query('department_id');

        if (! is_scalar($value) || ! ctype_digit((string) $value) || strlen((string) $value) > 9) {
            return null;
        }

        return Department::query()->whereKey((int) $value)->exists() ? (int) $value : null;
    }

    /** 25 by default; 10 and 50 are also offered (the table's rows-per-page control). */
    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        return is_scalar($value) && in_array((string) $value, array_map('strval', ListQuery::PER_PAGE_OPTIONS), true)
            ? (int) $value
            : self::PER_PAGE;
    }
}
