<?php

namespace App\Actions\Employees;

use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the employee form: the employee row, the contact and bank rows, and the linked account's name, email and role
 * (through the Phase 1 UpdateUserAccount, so the account stays verified and the last-admin and own-role rules hold),
 * in ONE transaction with ONE audit entry listing only the fields that changed.
 *
 * Never touches the status or the last working day (ChangeEmployeeStatus does) and never the code unless the actor is an
 * Admin. An employee that has no account yet gets one, exactly like CreateEmployee.
 *
 * The caller authorises and validates; see EmployeeWriter for the shape of $data. Only keys present in $data change.
 */
class UpdateEmployee
{
    private bool $linkSent = false;

    public function __construct(private readonly EmployeeWriter $writer) {}

    /** Whether the set-password link of an account created by this save was handed to the mail queue. */
    public function linkSent(): bool
    {
        return $this->linkSent;
    }

    public function createdAccount(): bool
    {
        return $this->writer->createdUser() !== null;
    }

    public function linkedExistingUser(): bool
    {
        return $this->writer->linkedExisting();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws AccountRuleViolation the own-role or last-admin rule refused the account change (nothing is saved)
     * @throws UniqueConstraintViolationException
     */
    public function handle(Employee $employee, array $data, User $by): Employee
    {
        $this->linkSent = false;
        $this->writer->reset();

        $saved = DB::transaction(function () use ($employee, $data, $by): Employee {
            $this->writer->reset(); // a deadlock retry runs this again
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            // A privileged account (admin or hr, as stored now) is only edited by an Admin: nothing is written otherwise.
            if (! EmployeeRoles::mayTouchAccount($by, $current->user)) {
                throw ValidationException::withMessages(['email' => EmployeeRoles::PRIVILEGED_MESSAGE]);
            }

            // The form never changes these two; status goes through ChangeEmployeeStatus.
            $attributes = $this->writer->employeeAttributes($data, $by->hasRole('admin'));
            unset($attributes['status']);

            if (array_key_exists('manager_id', $attributes)) {
                $this->writer->assertManager($current->getKey(), $attributes['manager_id']);
            }

            $current->disableLogging(); // one entry for the whole save, written below
            $current->fill($attributes);

            $old = [];
            $new = [];

            foreach (array_keys($current->getDirty()) as $key) {
                $old[$key] = $current->getOriginal($key);
                $new[$key] = $current->getAttribute($key);
            }

            $oldDeviceUserId = $current->getOriginal('device_user_id');
            $current->save();

            // A new or changed machine user ID moves the punches stored under it (and marks their days for a rebuild).
            if ($current->wasChanged('device_user_id')) {
                app(PunchIngestor::class)->relink($current, is_string($oldDeviceUserId) ? $oldDeviceUserId : null);
            }

            // Another shift, or exempt on or off, changes how their open days from today are worked out.
            if ($current->wasChanged(['shift_id', 'attendance_exempt'])) {
                RebuildMarks::forEmployee((int) $current->getKey());
            }

            $satellites = $this->writer->saveSatellites($current, $data);
            $old += $satellites['old'];
            $new += $satellites['attributes'];

            $account = $current->user;

            if ($account === null) {
                $this->writer->provideLogin($current, $by, $data['role'] ?? null);
            } else {
                $this->writer->syncLogin($current, $account, $by, $data['role'] ?? null);
            }

            if ($new !== []) {
                Audit::record(
                    'employees',
                    'Employee updated',
                    $current,
                    [
                        'old' => array_map(EmployeeWriter::auditValue(...), $old),
                        'attributes' => array_map(EmployeeWriter::auditValue(...), $new),
                    ],
                    $by,
                );
            }

            return $current;
        }, attempts: 3);

        $created = $this->writer->createdUser();

        if ($created !== null) {
            $this->linkSent = $this->writer->sendLinkFor($created);
        }

        return $saved;
    }
}
