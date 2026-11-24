<?php

namespace App\Actions\Employees;

use App\Actions\CreateUserAccount;
use App\Actions\UpdateUserAccount;
use App\Models\Employee;
use App\Models\EmployeeBankAccount;
use App\Models\EmployeeContact;
use App\Models\User;
use App\Support\EmployeeRoles;
use App\Support\ManagerLoop;
use App\Support\Names;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The pieces CreateEmployee and UpdateEmployee share: cleaning the posted data, writing the satellite rows, building the
 * one audit entry's old/new values, and giving the employee a login account (linking an existing one or creating one).
 *
 * Everything here runs inside the caller's database transaction. Model auditing is switched off for the rows written
 * here (disableLogging) because the caller records ONE entry for the whole save.
 *
 * The data shape every action accepts (flat keys, all optional unless the form requires them):
 *   employee: first_name, last_name, email, phone, dob, gender, cnic, department_id, designation_id, manager_id,
 *             joining_date, probation_end_date, employment_type, status, device_user_id, employee_code (Admin only)
 *   login:    role
 *   contact:  address, emergency_name, emergency_phone
 *   bank:     bank_name, account_title, account_number
 */
class EmployeeWriter
{
    /** Columns of `employees` the form writes (never photo_path or last_working_day). */
    public const EMPLOYEE_FIELDS = [
        'employee_code', 'device_user_id', 'first_name', 'last_name', 'email', 'phone', 'dob', 'gender', 'cnic',
        'department_id', 'designation_id', 'manager_id', 'joining_date', 'probation_end_date', 'employment_type', 'status',
    ];

    public const CONTACT_FIELDS = ['address', 'emergency_name', 'emergency_phone'];

    public const BANK_FIELDS = ['bank_name', 'account_title', 'account_number'];

    private bool $linkedExisting = false;

    private ?User $createdUser = null;

    public function __construct(private readonly CreateUserAccount $createAccount, private readonly UpdateUserAccount $updateAccount) {}

    /** Forget what the previous save did (the actions call this first, so one instance can be reused). */
    public function reset(): void
    {
        $this->linkedExisting = false;
        $this->createdUser = null;
    }

    public function linkedExisting(): bool
    {
        return $this->linkedExisting;
    }

    /** The account this call created (its set-password link is sent after the commit), or null. */
    public function createdUser(): ?User
    {
        return $this->createdUser;
    }

    public function sendLinkFor(User $user): bool
    {
        return $this->createAccount->sendLink($user);
    }

    /**
     * Only the keys present in $data, cleaned: names collapsed, email lower-cased, CNIC digits only, empty strings null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function employeeAttributes(array $data, bool $mayEditCode): array
    {
        $attributes = [];

        foreach (self::EMPLOYEE_FIELDS as $field) {
            if (! array_key_exists($field, $data) || ($field === 'employee_code' && ! $mayEditCode)) {
                continue;
            }

            $attributes[$field] = $this->clean($field, $data[$field]);
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    public function satelliteAttributes(array $data, array $fields): array
    {
        $attributes = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $this->clean($field, $data[$field]);
            }
        }

        return $attributes;
    }

    private function clean(string $field, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $value = match ($field) {
            'first_name', 'last_name' => Names::normalise($value),
            'email' => Str::lower(trim($value)),
            'cnic' => (string) preg_replace('/[\s-]+/', '', $value),
            default => trim($value),
        };

        return $value === '' ? null : $value;
    }

    /** Refuses a manager that does not exist or would make the reporting line loop back on itself. */
    public function assertManager(?int $employeeId, mixed $managerId): void
    {
        if ($managerId === null) {
            return;
        }

        $managerId = (int) $managerId;

        if (! Employee::query()->whereKey($managerId)->exists()) {
            throw ValidationException::withMessages(['manager_id' => 'Choose an existing employee as the manager.']);
        }

        if ($employeeId !== null && ManagerLoop::wouldCreateCycle($employeeId, $managerId)) {
            throw ValidationException::withMessages(['manager_id' => 'This would make the reporting line loop back on itself.']);
        }
    }

    /**
     * Creates or updates the contact and bank rows from $data. Returns the changed columns as old/new values
     * (a new row counts only its non-empty values) for the audit entry.
     *
     * @param  array<string, mixed>  $data
     * @return array{old: array<string, mixed>, attributes: array<string, mixed>}
     */
    public function saveSatellites(Employee $employee, array $data): array
    {
        $changes = ['old' => [], 'attributes' => []];

        $groups = [
            ['relation' => 'contact', 'fields' => self::CONTACT_FIELDS],
            ['relation' => 'bankAccount', 'fields' => self::BANK_FIELDS],
        ];

        foreach ($groups as $group) {
            $fields = $group['fields'];
            $attributes = $this->satelliteAttributes($data, $fields);

            if ($attributes === []) {
                continue;
            }

            $row = $employee->{$group['relation']}()->first();
            $isNew = $row === null;

            if ($isNew) {
                $attributes = array_filter($attributes, fn ($value) => $value !== null);

                if ($attributes === []) {
                    continue;
                }

                $row = $group['relation'] === 'contact' ? new EmployeeContact : new EmployeeBankAccount;
                $row->employee_id = $employee->getKey();
            }

            $row->disableLogging();
            $row->fill($attributes);

            if (! $isNew && ! $row->isDirty()) {
                continue;
            }

            foreach (array_keys($row->getDirty()) as $key) {
                if (in_array($key, $fields, true)) {
                    $changes['old'][$key] = $isNew ? null : $row->getOriginal($key);
                    $changes['attributes'][$key] = $row->getAttribute($key);
                }
            }

            $row->save();
            $employee->unsetRelation($group['relation']);
        }

        return $changes;
    }

    /**
     * Gives $employee a login: links an existing account that has no employee yet (its role is kept unless the actor
     * may and did change it), or creates one. Runs in the caller's transaction; the set-password link is NOT sent
     * here (see createdUser()). Returns the account.
     *
     * @throws ValidationException when the account belongs to another employee
     */
    public function provideLogin(Employee $employee, User $by, mixed $postedRole): User
    {
        $existing = User::query()
            ->whereRaw('LOWER(email) = ?', [Str::lower($employee->email)])
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            $role = EmployeeRoles::resolve($by, null, $postedRole) ?? EmployeeRoles::DEFAULT;
            $user = $this->createAccount->create($employee->full_name, $employee->email, $role);
            $user->forceFill(['employee_id' => $employee->getKey()])->save();
            $this->createdUser = $user;

            return $user;
        }

        if ($existing->employee_id !== null && (int) $existing->employee_id !== (int) $employee->getKey()) {
            throw ValidationException::withMessages(['email' => 'This email already belongs to the login of another employee.']);
        }

        $current = EmployeeRoles::currentRole($existing);
        $role = EmployeeRoles::resolve($by, $current, $postedRole, linking: true);

        $existing->forceFill(['employee_id' => $employee->getKey()])->save();
        $this->updateAccount->handle($existing, $employee->full_name, $employee->email, $role ?? $current ?? EmployeeRoles::DEFAULT, actor: $by);
        $this->linkedExisting = true;

        return $existing;
    }

    /** Keeps the linked account's name, email and (if the actor changed it) role in step, through the Phase 1 path. */
    public function syncLogin(Employee $employee, User $account, User $by, mixed $postedRole): void
    {
        $current = EmployeeRoles::currentRole($account);
        $role = EmployeeRoles::resolve($by, $current, $postedRole);

        if ($role === null && $account->name === $employee->full_name && $account->email === $employee->email) {
            return;
        }

        $this->updateAccount->handle($account, $employee->full_name, $employee->email, $role ?? $current ?? EmployeeRoles::DEFAULT, actor: $by);
    }

    /** A value as it is stored in the audit log: dates as Y-m-d, everything else as it is. */
    public static function auditValue(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->format('Y-m-d') : $value;
    }
}
