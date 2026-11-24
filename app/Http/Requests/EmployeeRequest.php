<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\User;
use App\Support\EmployeeRoles;
use App\Support\EmployeeRules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

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
        $this->merge(EmployeeRules::prepare($this->input()));
    }

    /**
     * The rules live in App\Support\EmployeeRules, shared with the Excel import.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->shared()->rules();
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return EmployeeRules::attributes();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return EmployeeRules::messages();
    }

    private function shared(): EmployeeRules
    {
        return new EmployeeRules($this->editing(), $this->user(), fn (string $key) => $this->input($key));
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
}
