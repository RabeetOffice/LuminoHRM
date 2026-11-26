<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use App\Models\PayrollRun;
use App\Models\SalaryComponent;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayrollAccess;
use App\Support\PayrollLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Salary profiles: create, update and delete, each in ONE transaction whose first statement locks the employee row
 * (plan Global Constraints: employee -> payroll run (shared, via PayrollLock) lock order). Decisions are read after
 * the lock.
 *
 *   $save->create($hr, $employee, $data);     // a new row (a change of salary is always a new row; history is kept)
 *   $save->update($hr, $profile, $data);      // components replaced wholesale
 *   $save->delete($hr, $profile);
 *
 * $data (SalaryProfileRequest::profileData()): effective_from "Y-m-d", basic (paisa), tax_mode, tax_value (paisa for fixed,
 * basis points for percentage, 0 for none), tax_base (percentage only), note, components (list of {name, type,
 * amount (paisa), is_taxable}).
 *
 * Refusals: not PayrollAccess::mayEditSalary -> AuthorizationException (403). ValidationException on effective_from:
 * before the joining date; in or before the latest approved payroll month; a second salary
 * of the same person on the same date; and editing or deleting a profile whose effective month is in or before the
 * latest approved month (approval runs in month order, so such a profile was used by approved payroll). Future-dated
 * profiles are allowed. Inside the transaction the lock is read with PayrollLock::rangeTouchesLocked (shared locks only
 * on runs from that month on), never latestLocked(). editable() (pages) takes a latestLocked() read outside any write.
 *
 * Audited (area payroll, subject the employee): "Salary profile created|updated|deleted" with `profile` (id) and
 * `attributes` / `old`: effective_from, basic, tax fields and components, every amount in paisa plus a formatted copy.
 * An update that changes nothing writes nothing.
 */
class SaveSalaryProfile
{
    private const FAR_FUTURE = '9999-12-31';

    public const USED_MESSAGE = 'This salary was used in approved payroll. Add a new salary instead.';

    /** @param  array<string, mixed>  $data */
    public function create(User $by, Employee $employee, array $data): SalaryProfile
    {
        return DB::transaction(function () use ($by, $employee, $data): SalaryProfile {
            $locked = $this->lockEmployee($by, $employee->id);

            $this->checkDate($locked, (string) $data['effective_from']);

            $profile = new SalaryProfile;
            $profile->forceFill([
                'employee_id' => $locked->id,
                'created_by' => $by->id,
            ]);
            $this->fill($profile, $data);
            $profile->save();
            $this->writeComponents($profile, $data['components'] ?? []);

            Audit::record('payroll', 'Salary profile created', $locked, [
                'profile' => $profile->id,
                'attributes' => self::snapshot($profile->load('components')),
            ], $by);

            return $profile;
        }, 3);
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $by, SalaryProfile $profile, array $data): SalaryProfile
    {
        return DB::transaction(function () use ($by, $profile, $data): SalaryProfile {
            $locked = $this->lockEmployee($by, $profile->employee_id);

            $current = SalaryProfile::query()->whereKey($profile->id)->with('components')->lockForUpdate()->firstOrFail();
            $this->refuseIfUsed($current);
            $old = self::snapshot($current);

            $this->checkDate($locked, (string) $data['effective_from'], $current->id);

            $this->fill($current, $data);
            $candidate = self::snapshot($current, self::componentRows($data['components'] ?? []));

            if ($candidate === $old) {
                return $current;
            }

            $current->save();
            $current->components()->delete();
            $this->writeComponents($current, $data['components'] ?? []);

            Audit::record('payroll', 'Salary profile updated', $locked, [
                'profile' => $current->id,
                'old' => $old,
                'attributes' => self::snapshot($current->load('components')),
            ], $by);

            return $current;
        }, 3);
    }

    public function delete(User $by, SalaryProfile $profile): void
    {
        DB::transaction(function () use ($by, $profile): void {
            $locked = $this->lockEmployee($by, $profile->employee_id);

            $current = SalaryProfile::query()->whereKey($profile->id)->with('components')->lockForUpdate()->firstOrFail();
            $this->refuseIfUsed($current);
            $old = self::snapshot($current);

            $current->components()->delete();
            $current->delete();

            Audit::record('payroll', 'Salary profile deleted', $locked, [
                'profile' => $current->id,
                'old' => $old,
            ], $by);
        }, 3);
    }

    /**
     * Whether a profile may still be edited or deleted: its effective month is after the latest approved month.
     * $latestLocked: pass PayrollLock::latestLocked() once when checking many profiles.
     */
    public static function editable(SalaryProfile $profile, ?string $latestLocked): bool
    {
        return $latestLocked === null || substr($profile->effective_from->toDateString(), 0, 7) > $latestLocked;
    }

    /**
     * The profile as the audit log records it: amounts in paisa and as text.
     *
     * @param  list<array{name: string, type: string, amount: int, is_taxable: bool}>|null  $components  instead of the loaded ones
     * @return array<string, mixed>
     */
    public static function snapshot(SalaryProfile $profile, ?array $components = null): array
    {
        $components ??= self::componentRows($profile->components->map(fn (SalaryComponent $c) => [
            'name' => $c->name,
            'type' => $c->type,
            'amount' => (int) $c->amount,
            'is_taxable' => (bool) $c->is_taxable,
        ])->all());

        return [
            'effective_from' => $profile->effective_from->toDateString(),
            'basic' => (int) $profile->basic,
            'basic_text' => Money::format((int) $profile->basic),
            'tax_mode' => $profile->tax_mode,
            'tax_value' => (int) $profile->tax_value,
            'tax_base' => $profile->tax_base,
            'tax_text' => self::taxText($profile->tax_mode, (int) $profile->tax_value, $profile->tax_base),
            'note' => $profile->note,
            'components' => array_map(fn (array $c) => [...$c, 'amount_text' => Money::format($c['amount'])], $components),
        ];
    }

    /** "5% of gross taxable", "5.25% of basic", "Rs 2,000 fixed" or "None" (the list and the audit log). */
    public static function taxText(string $mode, int $value, ?string $base): string
    {
        return match ($mode) {
            'fixed' => Money::format($value).' fixed',
            'percentage' => self::percent($value).'% of '.($base === 'basic' ? 'basic' : 'gross taxable'),
            default => 'None',
        };
    }

    /** Basis points as a percentage: 500 -> "5", 525 -> "5.25", 510 -> "5.1". */
    public static function percent(int $bp): string
    {
        $whole = intdiv($bp, 100);
        $rest = $bp % 100;

        return $rest === 0 ? (string) $whole : $whole.'.'.rtrim(str_pad((string) $rest, 2, '0', STR_PAD_LEFT), '0');
    }

    /**
     * First statement of the transaction: the employee row lock, then who may write (read after the lock).
     *
     * @throws AuthorizationException
     */
    private function lockEmployee(User $by, int $employeeId): Employee
    {
        // By id, as the very first statement: no plain read of the employee may come before it (MariaDB REPEATABLE READ
        // fixes the snapshot at the first read, so a read before the lock wait would see stale data after it).
        $locked = Employee::query()->whereKey($employeeId)->lockForUpdate()->firstOrFail();

        if (! PayrollAccess::mayEditSalary($by, $locked)) {
            throw new AuthorizationException('You cannot change this person\'s salary.');
        }

        return $locked;
    }

    /** @throws ValidationException */
    private function checkDate(Employee $employee, string $from, ?int $ignoreProfileId = null): void
    {
        $joined = $employee->joining_date->format('Y-m-d');

        if ($from < $joined) {
            throw ValidationException::withMessages([
                'effective_from' => "A salary cannot start before {$employee->full_name} joined (".self::date($joined).').',
            ]);
        }

        $locked = self::lockedFrom($from);

        if ($locked !== null) {
            // The label is the latest approved month (shared-locked, current); the month the check found is the fallback.
            $latest = PayrollLock::query()->max('month');

            throw ValidationException::withMessages([
                'effective_from' => 'Payroll up to '.PayrollRun::monthLabel(is_string($latest) && $latest !== '' ? $latest : $locked).' is approved. Start the new salary after it.',
            ]);
        }

        $duplicate = SalaryProfile::query()
            ->where('employee_id', $employee->id)
            ->onDate($from)
            ->when($ignoreProfileId !== null, fn ($q) => $q->whereKeyNot($ignoreProfileId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'effective_from' => "{$employee->full_name} already has a salary starting on ".self::date($from).'. Edit that one instead.',
            ]);
        }
    }

    /** @throws ValidationException */
    private function refuseIfUsed(SalaryProfile $profile): void
    {
        // Approval runs in month order, so a locked month on or after the profile's month means approved payroll used it.
        if (self::lockedFrom($profile->effective_from->toDateString()) !== null) {
            throw ValidationException::withMessages(['effective_from' => self::USED_MESSAGE]);
        }
    }

    /**
     * The first locked payroll month in or after the month of $date, or null. Called after the employee row lock
     * (lock order: employee, then the shared lock on the run rows PayrollLock reads). Only runs from that month on are
     * share-locked; latestLocked() is avoided inside the write (it would share-lock every locked run).
     */
    private static function lockedFrom(string $date): ?string
    {
        return PayrollLock::rangeTouchesLocked($date, self::FAR_FUTURE);
    }

    /** @param  array<string, mixed>  $data */
    private function fill(SalaryProfile $profile, array $data): void
    {
        $mode = (string) $data['tax_mode'];

        $profile->forceFill([
            'effective_from' => (string) $data['effective_from'],
            'basic' => (int) $data['basic'],
            'tax_mode' => $mode,
            'tax_value' => $mode === 'none' ? 0 : (int) $data['tax_value'],
            'tax_base' => $mode === 'percentage' ? ($data['tax_base'] ?? 'gross_taxable') : null,
            'note' => $data['note'] ?? null,
        ]);
    }

    /** @param  array<int, array<string, mixed>>  $components */
    private function writeComponents(SalaryProfile $profile, array $components): void
    {
        foreach (self::componentRows($components) as $order => $c) {
            $profile->components()->create([...$c, 'sort_order' => $order]);
        }
    }

    /**
     * Allowances first, then fixed deductions, each in the order given; a deduction is never taxable.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return list<array{name: string, type: string, amount: int, is_taxable: bool}>
     */
    private static function componentRows(array $components): array
    {
        $rows = [];

        foreach (['allowance', 'deduction'] as $type) {
            foreach ($components as $c) {
                if (($c['type'] ?? null) === $type) {
                    $rows[] = [
                        'name' => (string) $c['name'],
                        'type' => $type,
                        'amount' => (int) $c['amount'],
                        'is_taxable' => $type === 'allowance' && (bool) ($c['is_taxable'] ?? false),
                    ];
                }
            }
        }

        return $rows;
    }

    private static function date(string $ymd): string
    {
        return Carbon::createFromFormat('!Y-m-d', $ymd)->format('j M Y');
    }
}
