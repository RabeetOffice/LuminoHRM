<?php

namespace App\Http\Requests;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeRoles;
use App\Support\ManagerLoop;
use App\Support\Names;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Create and edit an employee (one form, five tabs). Authorisation: employees.manage, plus the role rules of
 * EmployeeRoles (a role the actor may not give, or an account whose role the actor may not change, is a 403 for the
 * whole request, never a silently ignored field).
 *
 * Not read from the request: the status on edit and the last working day (ChangeEmployeeStatus), the photo and the
 * documents (their own routes), and the employee code unless the signed-in user is an Admin.
 */
class EmployeeRequest extends FormRequest
{
    public const CODE_PATTERN = '/^LD-\d{4,}$/';

    public function authorize(): bool
    {
        $by = $this->user();

        if ($by === null || ! $by->can('employees.manage')) {
            return false;
        }

        $role = $this->input('role');

        if (! is_string($role) || ! in_array($role, config('hrm.roles'), true)) {
            return true; // not a 403: validation reports an unknown role
        }

        try {
            [$account, $linking] = $this->targetAccount();
            EmployeeRoles::resolve($by, EmployeeRoles::currentRole($account), $role, $linking);
        } catch (AuthorizationException) {
            return false;
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($this->input($field))) {
                $merge[$field] = Names::normalise($this->input($field));
            }
        }

        if (is_string($this->input('email'))) {
            $merge['email'] = mb_strtolower(trim($this->input('email')));
        }

        if (is_string($this->input('cnic'))) {
            $stripped = (string) preg_replace('/[\s-]+/', '', $this->input('cnic'));
            $merge['cnic'] = $stripped === '' ? null : $stripped;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->editing();
        $id = $employee?->getKey();

        $rules = [
            'first_name' => ['bail', 'required', 'string', 'max:100'],
            'last_name' => ['bail', 'required', 'string', 'max:100'],
            'email' => ['bail', 'required', 'string', 'email', 'max:255', $this->emailIsFree($employee)],
            'phone' => ['nullable', 'string', 'max:30'],
            'dob' => ['bail', 'nullable', 'date_format:Y-m-d', 'before:today', $this->oldEnough()],
            'gender' => ['nullable', 'string', Rule::in(config('hrm.employees.genders'))],
            'cnic' => ['nullable', 'string', 'regex:/^\d{13}$/', Rule::unique('employees', 'cnic')->ignore($id)],
            'department_id' => ['bail', 'required', 'integer', 'exists:departments,id'],
            'designation_id' => ['bail', 'required', 'integer', 'exists:designations,id', $this->designationBelongsToDepartment()],
            'manager_id' => ['bail', 'nullable', 'integer', 'exists:employees,id', $this->managerIsAllowed($employee)],
            'joining_date' => ['bail', 'required', 'date_format:Y-m-d'],
            'probation_end_date' => ['bail', 'nullable', 'date_format:Y-m-d', 'after:joining_date'],
            'employment_type' => ['required', 'string', Rule::in(config('hrm.employees.employment_types'))],
            'device_user_id' => ['nullable', 'string', 'max:20', Rule::unique('employees', 'device_user_id')->ignore($id)],
            'role' => ['nullable', 'string', Rule::in(config('hrm.roles'))],
            'address' => ['nullable', 'string', 'max:1000'],
            'emergency_name' => ['nullable', 'string', 'max:100'],
            'emergency_phone' => ['nullable', 'string', 'max:30'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'account_title' => ['nullable', 'string', 'max:100'],
            'account_number' => ['nullable', 'string', 'max:50'],
        ];

        if ($employee === null) {
            // A new person is active or on probation; every other status is a change made later.
            $rules['status'] = ['required', 'string', Rule::in(['active', 'probation'])];
        }

        if ($this->user()?->hasRole('admin')) {
            // Blank means "assign" on create; on edit the code can be changed but never emptied.
            $rules['employee_code'] = [$employee === null ? 'nullable' : 'required', 'string', 'regex:'.self::CODE_PATTERN, Rule::unique('employees', 'employee_code')->ignore($id)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'dob' => 'date of birth',
            'cnic' => 'CNIC',
            'department_id' => 'department',
            'designation_id' => 'designation',
            'manager_id' => 'reporting manager',
            'joining_date' => 'joining date',
            'probation_end_date' => 'probation end date',
            'employment_type' => 'employment type',
            'device_user_id' => 'machine user ID',
            'employee_code' => 'employee ID',
            'emergency_name' => 'emergency contact name',
            'emergency_phone' => 'emergency contact phone',
            'account_number' => 'account number',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cnic.regex' => 'The CNIC must have exactly 13 digits (dashes are fine).',
            'cnic.unique' => 'An employee with this CNIC already exists.',
            'device_user_id.unique' => 'This machine user ID is already used by another employee.',
            'employee_code.regex' => 'The code must look like LD-0001.',
            'employee_code.unique' => 'This employee code is already taken.',
            'dob.before' => 'The date of birth must be in the past.',
            'probation_end_date.after' => 'The probation end date must be after the joining date.',
            'status.in' => 'A new employee starts as Active or on Probation.',
        ];
    }

    /** The employee being edited, or null when creating. */
    private function editing(): ?Employee
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee ? $employee : null;
    }

    /**
     * The account the posted role would apply to, and whether it would be linked to a new employee:
     * on edit the employee's own account; on create an existing account with the typed email that has no employee yet.
     *
     * @return array{0: ?User, 1: bool}
     */
    private function targetAccount(): array
    {
        $employee = $this->editing();

        if ($employee?->user !== null) {
            return [$employee->user, false];
        }

        $email = $this->input('email');

        if (! is_string($email) || trim($email) === '') {
            return [null, false];
        }

        $account = User::query()->whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])->first();

        return $account !== null && $account->employee_id === null ? [$account, true] : [null, false];
    }

    /** Case-insensitive uniqueness among employees, and the login rules of the account that carries this email. */
    private function emailIsFree(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            $lower = mb_strtolower((string) $value);

            $duplicate = Employee::query()->whereRaw('LOWER(email) = ?', [$lower])
                ->when($employee !== null, fn ($q) => $q->whereKeyNot($employee->getKey()));

            if ($duplicate->exists()) {
                $fail('An employee with this email already exists.');

                return;
            }

            $account = User::query()->whereRaw('LOWER(email) = ?', [$lower])->first();

            if ($account === null) {
                return;
            }

            if ($account->employee_id !== null && ($employee === null || (int) $account->employee_id !== (int) $employee->getKey())) {
                $fail('This email already belongs to the login of another employee.');

                return;
            }

            $own = $employee?->user;

            if ($own !== null && $own->getKey() !== $account->getKey()) {
                $fail('A user with this email already exists.');
            }
        };
    }

    /** At least 16 years old on the joining date. */
    private function oldEnough(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $joining = $this->input('joining_date');

            if (! is_string($value) || ! is_string($joining)) {
                return;
            }

            try {
                $born = Carbon::createFromFormat('!Y-m-d', $value);
                $joined = Carbon::createFromFormat('!Y-m-d', $joining);
            } catch (Throwable) {
                return;
            }

            if ($born !== null && $joined !== null && $born->gt($joined->copy()->subYears(16))) {
                $fail('The date of birth must be at least 16 years before the joining date.');
            }
        };
    }

    private function designationBelongsToDepartment(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $department = $this->input('department_id');

            if (! is_scalar($department) || ! Department::query()->whereKey($department)->exists()) {
                return; // the department rule reports it
            }

            if (! Designation::query()->whereKey($value)->where('department_id', $department)->exists()) {
                $fail('This designation does not belong to the chosen department.');
            }
        };
    }

    /** Not the person themselves, no loop, and (when it changes) not someone who has left. */
    private function managerIsAllowed(?Employee $employee): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($employee): void {
            $manager = (int) $value;

            if ($employee !== null) {
                if ($manager === (int) $employee->getKey()) {
                    $fail('An employee cannot report to themselves.');

                    return;
                }

                if (ManagerLoop::wouldCreateCycle((int) $employee->getKey(), $manager)) {
                    $fail('This would make the reporting line loop back on itself.');

                    return;
                }
            }

            $unchanged = $employee !== null && $employee->manager_id !== null && (int) $employee->manager_id === $manager;
            $status = Employee::query()->whereKey($manager)->value('status');

            if (! $unchanged && in_array($status, ['resigned', 'terminated'], true)) {
                $fail('This person has left the company and cannot be a manager.');
            }
        };
    }
}
