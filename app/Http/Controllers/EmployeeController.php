<?php

namespace App\Http\Controllers;

use App\Actions\Employees\ChangeEmployeeStatus;
use App\Actions\Employees\CreateEmployee;
use App\Actions\Employees\DeleteEmployee;
use App\Actions\Employees\UpdateEmployee;
use App\Exceptions\AccountRuleViolation;
use App\Http\Requests\EmployeeRequest;
use App\Http\Requests\EmployeeStatusRequest;
use App\Http\Resources\EmployeeListResource;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeAccess;
use App\Support\EmployeeRoles;
use App\Support\ListQuery;
use App\Support\ManagerLoop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
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

    /** The Phase 1 wording, shown when the account exists but its set-password email could not be queued. */
    public const LINK_FAILED = 'Account created, but the link could not be sent. Use the reset icon.';

    private const ROLE_LABELS = ['admin' => 'Admin', 'hr' => 'HR', 'manager' => 'Manager', 'employee' => 'Employee'];

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
        $status = $this->statusFilter($request);
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
     * The profile page. Any signed-in user may ask for it: EmployeeAccess decides (403), and the field groups, the tabs
     * and the manager and reports lists all come from EmployeeProfileResource, which asks EmployeeAccess again. A person
     * who may see only their own record is sent to My Profile instead of being shown this page.
     */
    public function show(Request $request, Employee $employee): Response|RedirectResponse
    {
        $user = $request->user();
        $access = new EmployeeAccess($user);
        $own = $this->isOwnRecord($user, $employee);

        if ($own && ! $user->canAny(['employees.view-all', 'employees.view-team'])) {
            return redirect('/my/profile');
        }

        abort_unless($access->canView($employee), 403);

        $profile = new EmployeeProfileResource($employee, $access, $user);

        // Edit and the status change follow the edit page's rule (employees.manage, and only an Admin touches an Admin or
        // HR login). Delete adds the delete rules and is never offered on your own record (the route refuses it).
        $mayTouch = EmployeeRoles::mayTouchAccount($user, $employee->user()->first());
        $mayEdit = $access->canManage() && $mayTouch;

        return Inertia::render('employees/show', [
            'employee' => $profile->resolve($request),
            'tabs' => $profile->tabs(),
            // The statuses the "Change status" dialog offers (the same list the edit page gets).
            'statusOptions' => config('hrm.employees.statuses'),
            'can' => [
                'edit' => $mayEdit,
                'changeStatus' => $mayEdit,
                'delete' => ! $own && $mayTouch && $access->canDelete($employee),
            ],
        ]);
    }

    private function isOwnRecord(User $user, Employee $employee): bool
    {
        return $user->employee_id !== null && (int) $user->employee_id === (int) $employee->getKey();
    }

    public function create(Request $request): Response
    {
        return $this->form($request, null);
    }

    public function store(EmployeeRequest $request, CreateEmployee $create): RedirectResponse
    {
        try {
            $employee = $create->handle($request->validated(), $request->user(), sendLink: true);
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors($this->duplicateError($e));
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        $flash = match (true) {
            $create->linkedExistingUser() => ['success', "Employee added and linked to the existing account {$employee->email}. No email was sent."],
            $create->linkSent() => ['success', "Employee added. A link to set a password has been emailed to {$employee->email}."],
            default => ['error', self::LINK_FAILED],
        };

        return redirect()->route('employees.show', $employee)->with(...$flash);
    }

    public function edit(Request $request, Employee $employee): Response
    {
        abort_unless((new EmployeeAccess($request->user()))->canView($employee), 403);

        return $this->form($request, $employee);
    }

    public function update(EmployeeRequest $request, Employee $employee, UpdateEmployee $update): RedirectResponse
    {
        try {
            $update->handle($employee, $request->validated(), $request->user());
        } catch (UniqueConstraintViolationException $e) {
            return back()->withErrors($this->duplicateError($e));
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        $email = $request->validated('email');

        $flash = match (true) {
            $update->linkedExistingUser() => ['success', 'Employee updated and linked to the existing account. No email was sent.'],
            $update->createdAccount() && ! $update->linkSent() => ['error', self::LINK_FAILED],
            $update->createdAccount() => ['success', "Employee updated. A link to set a password has been emailed to {$email}."],
            default => ['success', 'Employee updated.'],
        };

        return redirect()->route('employees.show', $employee)->with(...$flash);
    }

    public function status(EmployeeStatusRequest $request, Employee $employee, ChangeEmployeeStatus $change): RedirectResponse
    {
        try {
            $change->handle($employee, $request->validated('status'), $request->validated('last_working_day'), $request->user());
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        // Asking for the status the person already has is not a change: say so instead of announcing one.
        return $change->changed()
            ? back()->with('success', 'Status changed to '.ucfirst($request->validated('status')).'.')
            : back()->with('info', 'No change.');
    }

    public function destroy(Request $request, Employee $employee, DeleteEmployee $delete): RedirectResponse
    {
        $user = $request->user();

        if ($user->employee_id !== null && (int) $user->employee_id === (int) $employee->getKey()) {
            return back()->with('error', 'You cannot delete your own employee record.');
        }

        $blockers = (new EmployeeAccess($user))->deleteBlockers($employee);

        if ($blockers !== []) {
            $advice = 'Set the status to Inactive, Resigned or Terminated instead.';

            return back()->with('error', str_contains($blockers[0], $advice) ? $blockers[0] : "{$blockers[0]} {$advice}");
        }

        try {
            $delete->handle($employee, $user);
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('employees.index')->with('success', 'Employee deleted.');
    }

    /**
     * Search for the "Reports to" picker: at most 20 people who are active or on probation, never the employee being
     * edited or anyone below them (that would be a loop). Only id, name, code and designation leave the server.
     */
    public function managerOptions(Request $request): JsonResponse
    {
        $exclude = $request->query('exclude');
        $excluded = is_scalar($exclude) && ctype_digit((string) $exclude) && strlen((string) $exclude) <= 9
            ? [(int) $exclude, ...ManagerLoop::descendantIds((int) $exclude)]
            : [];

        $people = Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->when($excluded !== [], fn (Builder $q) => $q->whereNotIn('id', $excluded))
            ->search(ListQuery::stringParam($request, 'search'))
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'employee_code', 'first_name', 'last_name', 'designation_id']);

        return response()->json(['data' => $people->map(fn (Employee $e) => $this->managerOption($e))->values()->all()]);
    }

    /** @return array{id: int, name: string, code: string, designation: string} */
    private function managerOption(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->full_name,
            'code' => $employee->employee_code,
            'designation' => $employee->designation->name,
        ];
    }

    /** The add / edit page. Admin and HR, the only roles that reach it, see every field group, so nothing is filtered here. */
    private function form(Request $request, ?Employee $employee): Response
    {
        $user = $request->user();
        $account = $employee?->user;
        $currentRole = EmployeeRoles::currentRole($account);
        $assignable = EmployeeRoles::assignable($user);

        // The role is read-only when the actor may not change it: HR looking at an Admin or HR, or your own account.
        $roleLocked = $employee !== null && (
            EmployeeRoles::isLockedFor($user, $currentRole)
            || ($user->employee_id !== null && (int) $user->employee_id === (int) $employee->getKey())
        );

        $roleChoices = $assignable;
        if ($currentRole !== null && ! in_array($currentRole, $roleChoices, true)) {
            $roleChoices[] = $currentRole;
        }

        $employee?->loadMissing('contact', 'bankAccount', 'manager.designation');

        return Inertia::render('employees/form', [
            'employee' => $employee === null ? null : $this->formEmployee($employee, $account, $currentRole),
            'manager' => $employee?->manager === null ? null : $this->managerOption($employee->manager),
            'departments' => Department::query()->orderBy('name')->orderBy('id')->get(['id', 'name'])->all(),
            'designations' => Designation::query()->orderBy('name')->orderBy('id')->get(['id', 'name', 'department_id'])->all(),
            'genders' => config('hrm.employees.genders'),
            'employmentTypes' => config('hrm.employees.employment_types'),
            // On create only Active or Probation; on edit the "Change status" dialog offers all five.
            'statusOptions' => $employee === null ? ['active', 'probation'] : config('hrm.employees.statuses'),
            'roleOptions' => array_map(
                fn (string $role) => ['value' => $role, 'label' => self::ROLE_LABELS[$role]],
                array_values(array_intersect(EmployeeRoles::ORDER, $roleChoices)),
            ),
            'roleLocked' => $roleLocked,
            // edit: false when the record belongs to an Admin or HR login and the viewer is not an Admin (the server refuses the save too).
            'can' => ['editCode' => $user->hasRole('admin'), 'edit' => EmployeeRoles::mayTouchAccount($user, $account)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formEmployee(Employee $employee, ?User $account, ?string $role): array
    {
        return [
            'id' => $employee->id,
            'code' => $employee->employee_code,
            'first_name' => $employee->first_name,
            'last_name' => $employee->last_name,
            'name' => $employee->full_name,
            'photo_url' => null,
            'email' => $employee->email,
            'phone' => $employee->phone,
            'dob' => $employee->dob?->format('Y-m-d'),
            'gender' => $employee->gender,
            'cnic' => $employee->cnic,
            'department_id' => $employee->department_id,
            'designation_id' => $employee->designation_id,
            'manager_id' => $employee->manager_id,
            'joining_date' => $employee->joining_date->format('Y-m-d'),
            'probation_end_date' => $employee->probation_end_date?->format('Y-m-d'),
            'employment_type' => $employee->employment_type,
            'status' => $employee->status,
            'last_working_day' => $employee->last_working_day?->format('Y-m-d'),
            'device_user_id' => $employee->device_user_id,
            'role' => $role ?? EmployeeRoles::DEFAULT,
            'has_login' => $account !== null,
            'login_active' => $account?->is_active,
            'address' => $employee->contact?->address,
            'emergency_name' => $employee->contact?->emergency_name,
            'emergency_phone' => $employee->contact?->emergency_phone,
            'bank_name' => $employee->bankAccount?->bank_name,
            'account_title' => $employee->bankAccount?->account_title,
            'account_number' => $employee->bankAccount?->account_number,
        ];
    }

    /**
     * Which field a unique-index violation is about (the validation normally catches these first; this is the race).
     *
     * @return array<string, string>
     */
    private function duplicateError(UniqueConstraintViolationException $e): array
    {
        $message = (string) ($e->errorInfo[2] ?? '');

        return match (true) {
            str_contains($message, 'cnic') => ['cnic' => 'An employee with this CNIC already exists.'],
            str_contains($message, 'device_user_id') => ['device_user_id' => 'This machine user ID is already used by another employee.'],
            str_contains($message, 'employee_code') => ['employee_code' => 'This employee code is already taken.'],
            str_contains($message, 'users.email'), str_contains($message, 'users_email') => ['email' => 'A user with this email already exists.'],
            default => ['email' => 'An employee with this email already exists.'],
        };
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

    private function statusFilter(Request $request): string
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
