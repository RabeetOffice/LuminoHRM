<?php

namespace App\Actions\Employees;

use App\Models\Employee;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EmployeeCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates an employee, their contact and bank rows, and their login account, in ONE transaction with ONE audit entry.
 *
 * - The code comes from EmployeeCode::next() inside the transaction. If another request wins the code in between,
 *   the unique index refuses the insert and the whole save runs once more. A code an Admin typed is never retried.
 * - The login: an existing account with this email and no employee is linked (its role kept unless the actor may and
 *   did change it, no email sent); otherwise a new account is created. See EmployeeWriter::provideLogin().
 * - The set-password link is sent after the commit, and only for a new account when $sendLink is true. Its failure
 *   never undoes the employee: read linkSent() afterwards. With $sendLink false (the Excel import) the account gets a
 *   random password nobody knows and no email goes out.
 *
 * The caller authorises (employees.manage) and validates; see EmployeeWriter for the shape of $data.
 */
class CreateEmployee
{
    private bool $linkSent = false;

    public function __construct(private readonly EmployeeWriter $writer) {}

    /** Whether the set-password link of a NEW account was handed to the mail queue by the last handle() call. */
    public function linkSent(): bool
    {
        return $this->linkSent;
    }

    /** Whether the last handle() call linked an account that already existed instead of creating one. */
    public function linkedExistingUser(): bool
    {
        return $this->writer->linkedExisting();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  string|null  $code  a code the caller has already chosen (the Excel import numbers blank codes in file
     *                             order); it wins over $data['employee_code'] and, like a typed code, is never retried.
     *                             It must NEVER receive user input directly: it bypasses the "only an Admin may set the
     *                             code" check (the import validates the file's codes, Admin only, before passing them)
     * @param  string|null  $passwordHash  for a NEW login only: a hash the server made itself from a random secret
     *                                     (the Excel import makes one per run). Never anything from a request or a
     *                                     file. Null (the form): a fresh random password per account
     *
     * @throws UniqueConstraintViolationException a duplicate email, CNIC, machine ID or typed code the validation did not see
     */
    public function handle(array $data, User $by, bool $sendLink = true, ?string $code = null, #[\SensitiveParameter] ?string $passwordHash = null): Employee
    {
        $this->linkSent = false;
        $mayEditCode = $by->hasRole('admin');

        if ($code !== null) {
            $data['employee_code'] = $code;
            $mayEditCode = true;
        }

        $explicitCode = $mayEditCode && filled($data['employee_code'] ?? null);
        $retried = false;

        while (true) {
            $this->writer->reset();

            try {
                $employee = DB::transaction(fn () => $this->persist($data, $by, $mayEditCode, $explicitCode, $passwordHash), attempts: 3);

                break;
            } catch (UniqueConstraintViolationException $e) {
                if ($retried || $explicitCode || ! EmployeeCode::isCodeConflict($e)) {
                    throw $e;
                }

                $retried = true;
            }
        }

        $created = $this->writer->createdUser();

        if ($created !== null && $sendLink) {
            $this->linkSent = $this->writer->sendLinkFor($created);
        }

        return $employee;
    }

    /** @param  array<string, mixed>  $data */
    private function persist(array $data, User $by, bool $mayEditCode, bool $explicitCode, #[\SensitiveParameter] ?string $passwordHash): Employee
    {
        $this->writer->reset(); // a deadlock retry runs this again

        $attributes = $this->writer->employeeAttributes($data, $mayEditCode);
        $attributes['employee_code'] = $explicitCode ? $attributes['employee_code'] : EmployeeCode::next();
        $attributes['status'] ??= 'active';
        $attributes['employment_type'] ??= 'full_time';

        if (! in_array($attributes['status'], config('hrm.employees.statuses'), true)) {
            throw new InvalidArgumentException("Unknown status [{$attributes['status']}].");
        }

        $this->writer->assertManager(null, $attributes['manager_id'] ?? null);

        $employee = new Employee($attributes);
        $employee->disableLogging(); // one entry for the whole save, written below
        $employee->save();

        $satellites = $this->writer->saveSatellites($employee, $data);
        $this->writer->provideLogin($employee, $by, $data['role'] ?? null, $passwordHash);

        $values = array_filter(
            [...$attributes, ...$satellites['attributes']],
            fn ($value) => $value !== null,
        );

        // Inside the transaction: no entry, no employee. CNIC, date of birth and the account number are masked by key.
        Audit::record(
            'employees',
            'Employee created',
            $employee,
            ['attributes' => array_map(EmployeeWriter::auditValue(...), $values)],
            $by,
        );

        return $employee;
    }
}
