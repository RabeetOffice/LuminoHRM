<?php

namespace App\Http\Controllers\Payroll;

use App\Actions\Payroll\SaveSalaryProfile;
use App\Http\Controllers\Controller;
use App\Http\Requests\SalaryProfileRequest;
use App\Models\Department;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\EmployeeAccess;
use App\Support\ListQuery;
use App\Support\Payroll\PayrollAccess;
use App\Support\PayrollLock;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll > Salary Profiles: who earns what, each person's salary history and the new-salary form.
 *
 * GET    /payroll/salary-profiles?search=&department=&missing=1&page=   can:payroll.view
 * GET    /payroll/salary-profiles/{employee}                             can:payroll.view
 * POST   /payroll/salary-profiles/{employee}                             can:payroll.run (+ mayEditSalary: 403)
 * PUT    /payroll/salary-profiles/{employee}/{profile}                   can:payroll.run (+ mayEditSalary: 403)
 * DELETE /payroll/salary-profiles/{employee}/{profile}                   can:payroll.run (+ mayEditSalary: 403)
 *
 * Salary figures are Admin / HR only (payroll.view); a Manager or Employee gets 403 on every route, their own record
 * included. A profile that is not the employee's is 404. The list holds people employed now or joining later (never a
 * leaver past their last working day, nor a leaver without one), scoped by EmployeeAccess. Its "current" salary is the
 * profile in force today, else the first future one (shown as upcoming); `missing=1` lists people without any.
 *
 * Reads per list page, whatever the number of people: the count and the page (with departments), the profiles of the
 * page and their components, and the departments filter.
 */
class SalaryProfileController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(private readonly AttendanceClock $clock) {}

    public function index(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $today = $this->clock->attendanceToday();
        $search = ListQuery::stringParam($request, 'search');
        $departmentId = $this->departmentId($request);
        $missing = $request->query('missing') === '1';

        $paginator = $this->people($user, $today, $search, $departmentId, $missing)
            ->with('department:id,name')
            ->paginate(self::PER_PAGE, ['id', 'employee_code', 'first_name', 'last_name', 'department_id'], 'page', ListQuery::page($request, 'page'));

        if ($redirect = ListQuery::clampRedirect($request, ['page' => $paginator])) {
            return $redirect;
        }

        $people = $paginator->getCollection();
        $profiles = SalaryProfile::query()
            ->whereIn('employee_id', $people->modelKeys())
            ->with('components')
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->groupBy('employee_id');

        return Inertia::render('payroll/salary-profiles/index', [
            'rows' => [
                'data' => $people->map(function (Employee $e) use ($profiles, $today): array {
                    $current = self::currentOf($profiles->get($e->id)?->all() ?? [], $today);

                    return [
                        'id' => $e->id,
                        'code' => $e->employee_code,
                        'name' => $e->full_name,
                        'department' => $e->department?->name,
                        'salary' => $current === null ? null : [
                            'basic' => (int) $current->basic,
                            'gross' => $current->gross(),
                            'tax' => SaveSalaryProfile::taxText($current->tax_mode, (int) $current->tax_value, $current->tax_base),
                            'effective_from' => $current->effective_from->toDateString(),
                            'upcoming' => $current->effective_from->toDateString() > $today,
                        ],
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
                'missing' => $missing,
            ],
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
        ]);
    }

    public function show(Request $request, Employee $employee): Response
    {
        $user = $request->user();
        abort_unless((new EmployeeAccess($user))->canView($employee), 403);

        $employee->loadMissing(['department:id,name', 'designation:id,name']);
        $canEdit = PayrollAccess::mayEditSalary($user, $employee);
        $latestLocked = PayrollLock::latestLocked();
        $today = $this->clock->attendanceToday();

        $history = $employee->salaryProfiles()
            ->with(['components', 'createdBy:id,name'])
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        $current = self::currentOf(array_reverse($history->all()), $today);
        $latest = $history->first();

        return Inertia::render('payroll/salary-profiles/show', [
            'employee' => [
                'id' => $employee->id,
                'code' => $employee->employee_code,
                'name' => $employee->full_name,
                'department' => $employee->department?->name,
                'designation' => $employee->designation?->name,
                'joining_date' => $employee->joining_date->format('Y-m-d'),
                'status' => $employee->status,
            ],
            'history' => $history->map(fn (SalaryProfile $p): array => [
                ...self::profileRow($p),
                'current' => $current !== null && $p->id === $current->id,
                'upcoming' => $p->effective_from->toDateString() > $today,
                'added_by' => $p->createdBy?->name,
                'can_edit' => $canEdit && SaveSalaryProfile::editable($p, $latestLocked),
            ])->values()->all(),
            // The new-salary form starts from the latest profile (a raise usually changes one figure).
            'defaults' => [
                'basic' => $latest === null ? null : (int) $latest->basic,
                'tax_mode' => $latest === null ? self::defaultTaxMode() : $latest->tax_mode,
                'tax_value' => $latest === null ? 0 : (int) $latest->tax_value,
                'tax_base' => $latest === null ? 'gross_taxable' : ($latest->tax_base ?? 'gross_taxable'),
                'components' => $latest === null ? [] : self::components($latest),
            ],
            'latest_locked' => $latestLocked,
            'today' => $today,
            'can_edit' => $canEdit,
        ]);
    }

    public function store(SalaryProfileRequest $request, Employee $employee, SaveSalaryProfile $save): RedirectResponse
    {
        abort_unless((new EmployeeAccess($request->user()))->canView($employee), 403);

        $save->create($request->user(), $employee, $request->profileData());

        return to_route('payroll.salary-profiles.show', $employee)->with('success', 'Salary saved.');
    }

    public function update(SalaryProfileRequest $request, Employee $employee, SalaryProfile $profile, SaveSalaryProfile $save): RedirectResponse
    {
        abort_unless((new EmployeeAccess($request->user()))->canView($employee), 403);
        abort_unless((int) $profile->employee_id === (int) $employee->id, 404);

        $save->update($request->user(), $profile, $request->profileData());

        return to_route('payroll.salary-profiles.show', $employee)->with('success', 'Salary updated.');
    }

    public function destroy(Request $request, Employee $employee, SalaryProfile $profile, SaveSalaryProfile $save): RedirectResponse
    {
        abort_unless((new EmployeeAccess($request->user()))->canView($employee), 403);
        abort_unless((int) $profile->employee_id === (int) $employee->id, 404);

        $save->delete($request->user(), $profile);

        return to_route('payroll.salary-profiles.show', $employee)->with('success', 'Salary deleted.');
    }

    /**
     * One profile as the pages show it (the Salary Profiles history and the profile's Salary tab). Paisa, basis points
     * for a percentage tax; the tax label is ready to show.
     *
     * @return array<string, mixed>
     */
    public static function profileRow(SalaryProfile $p): array
    {
        $deductions = 0;
        foreach ($p->components as $c) {
            if ($c->type === 'deduction') {
                $deductions += (int) $c->amount;
            }
        }

        return [
            'id' => $p->id,
            'effective_from' => $p->effective_from->toDateString(),
            'basic' => (int) $p->basic,
            'gross' => $p->gross(),
            'taxable_gross' => $p->taxableGross(),
            'deductions' => $deductions,
            'tax_mode' => $p->tax_mode,
            'tax_value' => (int) $p->tax_value,
            'tax_base' => $p->tax_base,
            'tax' => SaveSalaryProfile::taxText($p->tax_mode, (int) $p->tax_value, $p->tax_base),
            'note' => $p->note,
            'components' => self::components($p),
        ];
    }

    /**
     * The profile in force on $today (the latest starting on or before it), else the first one starting later.
     *
     * @param  array<int, SalaryProfile>  $ascending  one person's profiles, oldest first
     */
    public static function currentOf(array $ascending, string $today): ?SalaryProfile
    {
        $current = null;

        foreach ($ascending as $profile) {
            if ($profile->effective_from->toDateString() <= $today) {
                $current = $profile;
            } elseif ($current === null) {
                return $profile;
            }
        }

        return $current;
    }

    /** @return list<array{name: string, type: string, amount: int, is_taxable: bool}> */
    private static function components(SalaryProfile $p): array
    {
        return array_values($p->components->map(fn (SalaryComponent $c) => [
            'name' => $c->name,
            'type' => $c->type,
            'amount' => (int) $c->amount,
            'is_taxable' => (bool) $c->is_taxable,
        ])->all());
    }

    private static function defaultTaxMode(): string
    {
        $mode = Settings::get('payroll.default_tax_mode', 'none');

        return in_array($mode, (array) config('hrm.payroll.tax_modes'), true) ? (string) $mode : 'none';
    }

    /**
     * People employed now or joining later, that this viewer may see, in name order.
     *
     * @return Builder<Employee>
     */
    private function people(User $user, string $today, string $search, ?int $departmentId, bool $missing): Builder
    {
        return (new EmployeeAccess($user))->scope(Employee::query())
            ->search($search)
            ->when($departmentId !== null, fn (Builder $q) => $q->where('department_id', $departmentId))
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $today))
            ->tap(fn (Builder $q) => Leavers::exceptWithoutLastDay($q))
            ->when($missing, fn (Builder $q) => $q->whereDoesntHave('salaryProfiles'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id');
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
}
