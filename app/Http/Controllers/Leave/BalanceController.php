<?php

namespace App\Http\Controllers\Leave;

use App\Actions\Leave\AdjustBalance;
use App\Console\Commands\AllocateLeave;
use App\Exports\LeaveBalancesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\LeaveBalanceRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\EmployeeAccess;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveRouting;
use App\Support\ListQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelType;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Leave > Balances: employees × leave types for one year, HR's edit of a balance row, and the year as an .xlsx.
 *
 * GET /leave/balances?year=&department=&search=&page=   leave.approve-all (everyone in scope) or leave.approve-team
 *                                                       (own team, read-only); checked here (403 otherwise)
 * PUT /leave/balances/{employee}/{type}/{year}          can:leave.approve-all + LeaveRouting::mayAdminister (403)
 * GET /leave/balances/export?year=&department=&search=  can:leave.approve-all
 *
 * People: EmployeeAccess::scope (a Manager sees their own row and their direct reports), employed at some point in the
 * year (AllocateLeave::employedIn); a leaver without a last working day only in a year where they have a balance row.
 * Years: from the earliest balance year (or this year) to next year; anything else means this year.
 *
 * Rows are created (LeaveBalances::ensureRow, only the missing pairs) for the listed people the viewer may administer,
 * only for this leave year and the next. Anyone else's figure for a year without a row is the projection
 * (BalanceLine::projected), which the page shows as such. Balance rows of unpaid types are never shown: an unpaid
 * type's cell is its used days (BalanceLine::toArray, unlimited). Inactive types are not columns.
 *
 * Reads per page, whatever the number of people: the count and the page of people (with departments), the earliest
 * balance year, the types, the privileged logins (once, for can_edit), the existing rows of the page (ensuring), the
 * fixed set of LeaveBalances::forEmployees, and the departments filter.
 */
class BalanceController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly LeaveBalances $balances,
        private readonly AttendanceClock $clock,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->canAny(['leave.approve-all', 'leave.approve-team']), 403);

        $current = $this->currentYear();
        $years = $this->years($current);
        $year = $this->year($request, $years, $current);
        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->departmentId($request);
        $administers = $user->can('leave.approve-all');

        $paginator = $this->people($user, $year, $search, $departmentId)
            ->with('department:id,name')
            ->paginate($this->perPage($request), ['*'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        /** @var Collection<int, Employee> $people */
        $people = $paginator->getCollection();
        $types = LeaveType::query()->active()->orderBy('sort_order')->orderBy('id')->get();
        $routing = new LeaveRouting;
        $editable = [];

        if ($administers) {
            foreach ($people as $employee) {
                $editable[$employee->id] = $routing->mayAdminister($user, $employee);
            }

            if ($year === $current || $year === $current + 1) {
                $this->ensureRows($people->filter(fn (Employee $e) => $editable[$e->id] && ! Leavers::withoutLastDay($e)), $types, $year);
            }
        }

        $lines = $this->balances->forEmployees($people, $year);

        return Inertia::render('leave/balances', [
            'year' => $year,
            'years' => $years,
            'current_year' => $current,
            'types' => $types->map(fn (LeaveType $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'colour' => $t->colour,
                'is_paid' => $t->is_paid,
            ])->values()->all(),
            'rows' => [
                'data' => $people->map(function (Employee $e) use ($types, $lines, $editable): array {
                    $cells = [];

                    foreach ($types as $type) {
                        $line = $lines[$e->id][$type->id] ?? null;

                        if ($line !== null) {
                            $cells[$type->id] = [...$line->toArray(), 'can_edit' => $type->is_paid && ($editable[$e->id] ?? false)];
                        }
                    }

                    return [
                        'id' => $e->id,
                        'name' => $e->full_name,
                        'first_name' => $e->first_name,
                        'code' => $e->employee_code,
                        'department' => $e->department?->name,
                        'cells' => (object) $cells,
                    ];
                })->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => [
                'search' => $search,
                'department' => $departmentId,
            ],
            'departments' => Department::query()
                ->when(! $user->can('employees.view-all'), fn (Builder $q) => $q->whereIn(
                    'id',
                    (new EmployeeAccess($user))->scope(Employee::query())->whereNotNull('department_id')->select('department_id'),
                ))
                ->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'can' => [
                'edit' => $administers,
                'export' => $administers,
                'import' => $administers,
            ],
        ]);
    }

    public function update(LeaveBalanceRequest $request, Employee $employee, LeaveType $type, int $year, AdjustBalance $adjust): RedirectResponse
    {
        $user = $request->user();

        // Out of scope or not administrable: 403 before anything about the type or year is revealed.
        abort_unless((new EmployeeAccess($user))->canView($employee) && (new LeaveRouting)->mayAdminister($user, $employee), 403);
        abort_if($type->isUnpaid(), 404);

        $current = $this->currentYear();
        abort_unless(in_array($year, $this->years($current), true), 404);

        if (! AllocateLeave::employedIn(Employee::query()->whereKey($employee->id), $year)->exists()
            && ! LeaveBalance::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->where('year', $year)->exists()) {
            return back()->with('error', "{$employee->full_name} was not employed in {$year}.");
        }

        try {
            $adjust->handle(
                $employee,
                $type,
                $year,
                (float) $request->input('allocated'),
                (float) $request->input('adjustment'),
                (float) $request->input('opening_used'),
                (string) $request->input('reason'),
                $request->boolean('confirm_overdrawn'),
                $user,
            );
        } catch (AuthorizationException) {
            abort(403);
        }

        return back()->with('success', $adjust->changed() ? 'Balance updated.' : 'Nothing changed.');
    }

    public function export(Request $request): BinaryFileResponse
    {
        $user = $request->user();
        $current = $this->currentYear();
        $year = $this->year($request, $this->years($current), $current);
        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->departmentId($request);

        $people = $this->people($user, $year, $search, $departmentId)->with('department:id,name')->get();
        $types = LeaveType::query()->active()->where('is_paid', true)->orderBy('sort_order')->orderBy('id')->get();

        $lines = [];
        foreach ($people->chunk(500) as $chunk) {
            $lines += $this->balances->forEmployees(new Collection($chunk->all()), $year);
        }

        // Before the file is built: an export that cannot be logged does not happen. Never row data.
        Audit::record('leave', 'Leave balances exported', null, [
            'year' => $year,
            'rows' => $people->count(),
            'filters' => ['search' => $search, 'department' => $departmentId],
        ], $user);

        return Excel::download(
            new LeaveBalancesExport(array_values($types->all()), array_values($people->all()), $lines),
            "leave-balances-{$year}.xlsx",
            ExcelType::XLSX,
        );
    }

    /**
     * The people this viewer may see who were employed at some point in $year, in name order (full models: the balance
     * service reads the joining date, last working day, status and shift).
     *
     * @return Builder<Employee>
     */
    private function people(User $user, int $year, string $search, ?int $departmentId): Builder
    {
        return (new EmployeeAccess($user))->scope(Employee::query())
            ->search($search)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->where('joining_date', '<=', sprintf('%04d-12-31', $year))
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', sprintf('%04d-01-01', $year)))
            // A leaver with no last working day recorded: only in a year where they have a balance row.
            ->where(fn (Builder $q) => $q
                ->tap(fn (Builder $w) => Leavers::exceptWithoutLastDay($w))
                ->orWhereExists(fn ($sub) => $sub->selectRaw('1')
                    ->from('leave_balances')
                    ->whereColumn('leave_balances.employee_id', 'employees.id')
                    ->where('leave_balances.year', $year)))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');
    }

    /**
     * Creates the missing balance rows of $year for these people (active paid types only): one query for the existing
     * pairs, then LeaveBalances::ensureRow for each missing pair. Nothing more once every row exists.
     *
     * @param  \Illuminate\Support\Collection<int, Employee>  $people
     * @param  Collection<int, LeaveType>  $types
     */
    private function ensureRows(\Illuminate\Support\Collection $people, Collection $types, int $year): void
    {
        $paid = $types->filter(fn (LeaveType $t) => $t->is_paid);

        if ($people->isEmpty() || $paid->isEmpty()) {
            return;
        }

        $existing = [];
        foreach (LeaveBalance::query()->toBase()
            ->whereIn('employee_id', $people->map(fn (Employee $e) => $e->id)->values()->all())
            ->whereIn('leave_type_id', $paid->modelKeys())
            ->where('year', $year)
            ->get(['employee_id', 'leave_type_id']) as $row) {
            $existing[(int) $row->employee_id][(int) $row->leave_type_id] = true;
        }

        foreach ($people as $employee) {
            foreach ($paid as $type) {
                if (! isset($existing[$employee->id][$type->id])) {
                    $this->balances->ensureRow($employee, $type, $year);
                }
            }
        }
    }

    private function currentYear(): int
    {
        return (int) substr($this->clock->attendanceToday(), 0, 4);
    }

    /**
     * From the earliest balance year (or this year, whichever is earlier) to next year.
     *
     * @return list<int>
     */
    private function years(int $current): array
    {
        $earliest = LeaveBalance::query()->min('year');
        $from = $earliest === null ? $current : min((int) $earliest, $current);

        return range($from, $current + 1);
    }

    /** @param  list<int>  $years */
    private function year(Request $request, array $years, int $current): int
    {
        $value = $request->query('year');

        if (is_scalar($value) && preg_match('/^\d{4}$/', (string) $value) === 1 && in_array((int) $value, $years, true)) {
            return (int) $value;
        }

        return $current;
    }

    /** department when it names an existing department; null for anything else (never an error). */
    private function departmentId(Request $request): ?int
    {
        $value = $request->query('department');

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
