<?php

namespace App\Actions\Employees;

use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\User;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
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
     * @param  bool  $syncAccount  false: never create, link or update a login and never send a set-password link (for
     *                             saves that touch no account field, such as assigning a machine user ID). The
     *                             privileged-account check still applies.
     *
     * @throws AccountRuleViolation the own-role or last-admin rule refused the account change (nothing is saved)
     * @throws UniqueConstraintViolationException
     */
    public function handle(Employee $employee, array $data, User $by, bool $syncAccount = true): Employee
    {
        $this->linkSent = false;
        $this->writer->reset();

        $saved = DB::transaction(function () use ($employee, $data, $by, $syncAccount): Employee {
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
            $before = clone $current; // the record as stored, for the allocation recalculation below
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

            // A later joining date leaves days outside the window: flag them so the rebuild removes them. (An earlier
            // one opens days, which the hourly close backfills.)
            if ($current->wasChanged(['joining_date', 'last_working_day', 'status'])) {
                RebuildMarks::outsideEmployment($current);
            }

            if ($current->wasChanged('joining_date')) {
                $this->recalculateAllocations($before, $current, $by);
            }

            $satellites = $this->writer->saveSatellites($current, $data);
            $old += $satellites['old'];
            $new += $satellites['attributes'];

            $account = $current->user;

            // Without $syncAccount no login is created, linked or renamed (so there is no link to send below).
            if ($syncAccount) {
                if ($account === null) {
                    $this->writer->provideLogin($current, $by, $data['role'] ?? null);
                } else {
                    $this->writer->syncLogin($current, $account, $by, $data['role'] ?? null);
                }
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

    /**
     * A new joining date changes the pro-rated allocation of the old and the new joining year. A balance row of either
     * year whose `allocated` still equals LeaveBalances::allocationFor under the OLD joining date (HR never edited it)
     * gets allocationFor under the new one; a row HR edited is left alone. Unpaid types have no balance. One entry
     * "Leave allocation recalculated" per row changed (subject the employee; year, type, old / new allocated).
     */
    private function recalculateAllocations(Employee $before, Employee $after, User $by): void
    {
        $balances = app(LeaveBalances::class);
        $years = array_values(array_unique([
            (int) $before->joining_date->format('Y'),
            (int) $after->joining_date->format('Y'),
        ]));

        $rows = LeaveBalance::query()
            ->with('type')
            ->where('employee_id', $after->getKey())
            ->whereIn('year', $years)
            ->orderBy('year')->orderBy('leave_type_id')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            $type = $row->type;

            if ($type === null || $type->isUnpaid()) {
                continue;
            }

            $oldAllocation = $balances->allocationFor($before, $type, (int) $row->year);

            if (LeaveDays::halves($row->allocated) !== LeaveDays::halves($oldAllocation)) {
                continue; // HR edited this row
            }

            $newAllocation = $balances->allocationFor($after, $type, (int) $row->year);

            if (LeaveDays::halves($newAllocation) === LeaveDays::halves($oldAllocation)) {
                continue;
            }

            $row->forceFill(['allocated' => $newAllocation])->save();

            Audit::record('leave', 'Leave allocation recalculated', $after, [
                'year' => (int) $row->year,
                'type' => $type->name,
                'type_id' => $type->id,
                'old' => ['allocated' => LeaveDays::fromHalves(LeaveDays::halves($oldAllocation))],
                'attributes' => ['allocated' => LeaveDays::fromHalves(LeaveDays::halves($newAllocation))],
            ], $by);
        }
    }
}
