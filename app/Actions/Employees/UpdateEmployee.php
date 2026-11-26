<?php

namespace App\Actions\Employees;

use App\Actions\Leave\LeaveEmploymentTrim;
use App\Actions\Leave\WeeklyOffLeaveRefund;
use App\Exceptions\AccountRuleViolation;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\PunchIngestor;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\EmployeeRoles;
use App\Support\Leave\BalanceLine;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * Saves the employee form: the employee row, the contact and bank rows, and the linked account's name, email and role
 * (through the Phase 1 UpdateUserAccount, so the account stays verified and the last-admin and own-role rules hold),
 * in ONE transaction with ONE audit entry listing only the fields that changed.
 *
 * Never touches the status or the last working day (ChangeEmployeeStatus does) and never the code unless the actor is an
 * Admin. An employee that has no account yet gets one, exactly like CreateEmployee.
 *
 * The caller authorises and validates; see EmployeeWriter for the shape of $data. Only keys present in $data change.
 *
 * Payroll lock: a joining date change is refused ([joining_date] PayrollLock::message()) when a day it adds to or
 * removes from the employment window ([earlier date, later date)) is in a month with an approved or paid payroll run;
 * nothing is saved.
 */
class UpdateEmployee
{
    private bool $linkSent = false;

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{request: LeaveRequest, outcome: string, refunded: float, removed: list<string>, manual: list<string>}> */
    private array $weeklyOffRefunds = [];

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
        $this->warnings = [];
        $this->weeklyOffRefunds = [];
        $this->writer->reset();

        $saved = DB::transaction(function () use ($employee, $data, $by, $syncAccount): Employee {
            $this->writer->reset(); // a deadlock retry runs this again
            $this->warnings = [];
            $this->weeklyOffRefunds = [];
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

            // A joining date move changes the employment window on the days [earlier, later): refused when one of them
            // is in a month whose payroll is approved. After the employee row lock (lock order: employee, then
            // payroll_runs shared, then attendance and leave rows).
            if ($current->isDirty('joining_date')) {
                $from = $before->joining_date->format('Y-m-d');
                $to = $current->joining_date->format('Y-m-d');
                [$low, $high] = $from <= $to ? [$from, $to] : [$to, $from];
                $locked = PayrollLock::rangeTouchesLockedFor(
                    (int) $current->id,
                    $low,
                    CarbonImmutable::createFromFormat('!Y-m-d', $high)->subDay()->format('Y-m-d'),
                );

                if ($locked !== null) {
                    throw ValidationException::withMessages(['joining_date' => PayrollLock::messageForEmployee((int) $current->id, $locked)]);
                }
            }

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
                // Leave outside the new window goes too (removed and refunded, open requests cancelled; audited).
                $trim = app(LeaveEmploymentTrim::class);
                $trim->apply($current, $by);
                // Hand-corrected days whose leave went keep their leave status: HR is told.
                array_push($this->warnings, ...$trim->notices());
            }

            // Another shift may make leave days weekly offs: those are refunded from the first unclosed day.
            if ($current->wasChanged('shift_id')) {
                $this->weeklyOffRefunds = app(WeeklyOffLeaveRefund::class)->forEmployees([(int) $current->getKey()], $by);
                $note = trim(WeeklyOffLeaveRefund::note($this->weeklyOffRefunds));

                if ($note !== '') {
                    $this->warnings[] = $note;
                }
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

        app(WeeklyOffLeaveRefund::class)->mail($this->weeklyOffRefunds);

        $created = $this->writer->createdUser();

        if ($created !== null) {
            $this->linkSent = $this->writer->sendLinkFor($created);
        }

        return $saved;
    }

    /**
     * Messages for the person who saved (shown as a flash): a recalculated allocation that leaves a balance overdrawn,
     * e.g. "Annual 2026 is now overdrawn by 3 days."; leave refunded because another shift made days weekly offs; and
     * hand-corrected attendance days whose leave was removed. The save still happened.
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /**
     * A new joining date changes the allocation of every year from the earlier to the later joining year (pro-rated in
     * the joining year, nothing before it, the full quota after it). A balance row of those years is recalculated to
     * LeaveBalances::allocationFor under the new date when it is untouched:
     *   - no "Leave balance adjusted" entry ever changed its `allocated` (the audit trail of HR's edits), and
     *   - its `allocated` equals allocationFor under the OLD joining date (the value it was created with, as far as
     *     can be told now: the row does not store its creation value, so a row created under another quota or
     *     pro-rating setting no longer matches and is kept as it is, the safe side).
     * A recalculated row that ends overdrawn — or leaves a later year fed by its carry overdrawn, up to
     * LeaveBalances::lastYearInUse() — is still saved (ruling C) and adds a warning (warnings()). Unpaid types have
     * no balance. One entry "Leave allocation recalculated" per row changed (subject the employee; year, type, old / new
     * allocated).
     */
    private function recalculateAllocations(Employee $before, Employee $after, User $by): void
    {
        $balances = app(LeaveBalances::class);
        $oldYear = (int) $before->joining_date->format('Y');
        $newYear = (int) $after->joining_date->format('Y');

        $rows = LeaveBalance::query()
            ->with('type')
            ->where('employee_id', $after->getKey())
            ->whereBetween('year', [min($oldYear, $newYear), max($oldYear, $newYear)])
            ->orderBy('year')->orderBy('leave_type_id')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        // "type id:year" of every row whose allocated HR changed at some point.
        $edited = [];
        foreach (Activity::query()
            ->where('log_name', 'leave')->where('description', 'Leave balance adjusted')
            ->where('subject_type', $after->getMorphClass())->where('subject_id', $after->getKey())
            ->get(['properties']) as $entry) {
            $p = $entry->properties;

            if (LeaveDays::halves($p['old']['allocated'] ?? 0) !== LeaveDays::halves($p['attributes']['allocated'] ?? 0)) {
                $edited[(int) ($p['type_id'] ?? 0).':'.(int) ($p['year'] ?? 0)] = true;
            }
        }

        $touched = []; // type id => [type, earliest recalculated year]

        foreach ($rows as $row) {
            $type = $row->type;

            if ($type === null || $type->isUnpaid() || isset($edited[$type->id.':'.(int) $row->year])) {
                continue;
            }

            $oldAllocation = $balances->allocationFor($before, $type, (int) $row->year);

            if (LeaveDays::halves($row->allocated) !== LeaveDays::halves($oldAllocation)) {
                continue; // edited by other means, or created under another quota / setting: kept
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

            $touched[$type->id] ??= [$type, (int) $row->year];
        }

        // The recalculated year, and every later year fed by its carry up to the last year in use (M1).
        foreach ($touched as [$type, $from]) {
            $last = max($from, $balances->lastYearInUse($after, $type) ?? $from);

            for ($year = $from; $year <= $last; $year++) {
                $line = $balances->line($after, $type, $year);

                if ($line->remaining < 0) {
                    $days = BalanceLine::format(abs($line->remaining));
                    $this->warnings[] = "{$type->name} {$year} is now overdrawn by {$days} ".($days === '1' ? 'day' : 'days').'.';
                }
            }
        }
    }
}
