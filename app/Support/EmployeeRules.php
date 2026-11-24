<?php

namespace App\Support;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The validation rules of one employee record, shared by the add/edit form (EmployeeRequest) and the Excel import
 * (one row at a time, EmployeeImportValidator), so the two can never drift apart.
 *
 * The input is read through a closure because the rules compare fields with each other (designation within the
 * department, date of birth against the joining date): the form passes its request input, the import its row.
 *
 * The one difference for an import: any configured status is allowed (a resigned person can be loaded as resigned),
 * where the form accepts only Active or Probation for a new person.
 */
final class EmployeeRules
{
    /**
     * @param  Employee|null  $editing  the employee being edited, null when creating
     * @param  User|null  $by  the signed-in user (an Admin may set the employee code)
     * @param  Closure(string): mixed  $input  reads one input field
     */
    public function __construct(
        private readonly ?Employee $editing,
        private readonly ?User $by,
        private readonly Closure $input,
        private readonly bool $import = false,
    ) {}

    /**
     * The clean-up done before validation: names collapsed, email trimmed and lower-cased, CNIC without spaces or
     * dashes ('' becomes null). Returns only the keys it changed, to merge over the input.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function prepare(array $input): array
    {
        $merge = [];

        foreach (['first_name', 'last_name'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $merge[$field] = Names::normalise($input[$field]);
            }
        }

        if (is_string($input['email'] ?? null)) {
            $merge['email'] = mb_strtolower(trim($input['email']));
        }

        if (is_string($input['cnic'] ?? null)) {
            $stripped = (string) preg_replace('/[\s-]+/', '', $input['cnic']);
            $merge['cnic'] = $stripped === '' ? null : $stripped;
        }

        return $merge;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $employee = $this->editing;
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
            // A new person is active or on probation; every other status is a change made later. An import may load
            // people who have already left (see the class comment).
            $rules['status'] = ['required', 'string', Rule::in($this->import ? config('hrm.employees.statuses') : ['active', 'probation'])];
        }

        if ($this->by?->hasRole('admin')) {
            // Blank means "assign" on create; on edit the code can be changed but never emptied.
            $rules['employee_code'] = [$employee === null ? 'nullable' : 'required', 'string', 'regex:'.EmployeeCode::PATTERN, Rule::unique('employees', 'employee_code')->ignore($id)];
        }

        return $rules;
    }

    /** @return array<string, string> */
    public static function attributes(): array
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
    public static function messages(): array
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

    private function input(string $key): mixed
    {
        return ($this->input)($key);
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
