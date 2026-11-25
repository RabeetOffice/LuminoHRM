<?php

namespace App\Http\Controllers\Attendance;

use App\Actions\Attendance\ApplyManualCorrection;
use App\Exports\MonthlyAttendanceExport;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
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
 * People: EmployeeAccess::scope unchanged, as on the Daily screen (a Manager sees their own row and their direct
 * reports), limited to people employed at some point in the month. A leaver (inactive / resigned / terminated) with
 * no last working day is shown only in months where they have an attendance row. A missing or malformed month means
 * the current one; a month that has not started is refused (back to the current month).
 *
 * Who may correct which day: the Daily screen's rule. Per row, can_edit = attendance.edit AND ApplyManualCorrection::
 * mayCorrect (not your own record unless Admin; an Admin / HR login only by an Admin; the privileged logins are read
 * once, as on the Daily screen) AND the person can be corrected inside this month's window at all. The page then
 * allows a day only inside the employment window and not in the future. The window is exactly the span
 * ApplyManualCorrection::employmentRefusal accepts (from max(joining date, attendance start date) to the last working
 * day), except a leaver with no last working day, whom employmentRefusal refuses on every day: can_edit is false.
 * Reads per page: the people (their designations), for a non-Admin editor the privileged logins (one query), one
 * query for their attendance rows, one for holidays.
 */
class MonthlyController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly ApplyManualCorrection $correct) {}

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

        $mayEdit = $user->can('attendance.edit');

        $paginator = $this->people($user, $grid, $search, $departmentId)
            ->with('designation:id,name')
            ->paginate($perPage, ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $people = $paginator->getCollection();
        $rows = $grid->rows($people);
        $current = MonthGrid::currentMonth($clock);

        // Employees whose login is Admin or HR: one query, only when the viewer edits and is not an Admin (as on Daily).
        $privileged = $mayEdit && ! $user->hasRole('admin')
            ? array_flip(User::query()->role(['admin', 'hr'])->whereNotNull('employee_id')->pluck('employee_id')->all())
            : [];

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
                    'can_edit' => $mayEdit
                        && ApplyManualCorrection::mayCorrect($user, $e, isset($privileged[$e->id]))
                        && $this->correctableWindow($e, $grid, $rows[$e->id]['window']),
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
            'overrideStatuses' => ApplyManualCorrection::OVERRIDE_STATUSES,
            'today' => $grid->today,
            // A team-scoped viewer only sees the departments of the people in their scope (as on the Daily screen).
            'departments' => Department::query()
                ->when(! $user->can('attendance.view-all'), fn (Builder $q) => $q->whereIn(
                    'id',
                    (new EmployeeAccess($user))->scope(Employee::query())->whereNotNull('department_id')->select('department_id'),
                ))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'can' => [
                'export' => $user->can('attendance.view-all'),
                'edit' => $mayEdit,
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
        return (new EmployeeAccess($user))->scope(Employee::query())
            ->select(['id', 'employee_code', 'first_name', 'last_name', 'department_id', 'designation_id', 'photo_path',
                'joining_date', 'last_working_day', 'status', 'updated_at'])
            ->search($search)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->where('joining_date', '<=', $grid->last)
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $grid->first))
            // A leaver with no last working day recorded: only in months where they have attendance (one subquery).
            ->where(fn (Builder $q) => $q
                ->tap(fn (Builder $w) => Leavers::exceptWithoutLastDay($w))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('attendances')
                    ->whereColumn('attendances.employee_id', 'employees.id')
                    ->whereBetween('attendances.date', [$grid->first, $grid->last])))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');
    }

    /**
     * Whether ApplyManualCorrection accepts every day of the person's window this month (no query: employmentRefusal
     * reads the employee and the cached settings). It refuses a contiguous span's outside only, so checking the first
     * and the last day of the window covers every day in between; a leaver with no last working day is refused on all.
     *
     * @param  array{0: int, 1: int}|null  $window
     */
    private function correctableWindow(Employee $employee, MonthGrid $grid, ?array $window): bool
    {
        if ($window === null) {
            return false;
        }

        foreach ($window as $day) {
            if ($this->correct->employmentRefusal($employee, sprintf('%s-%02d', $grid->month, $day)) !== null) {
                return false;
            }
        }

        return true;
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
