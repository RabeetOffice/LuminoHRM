<?php

namespace App\Actions\Payroll;

use App\Models\Employee;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Payroll\Money;
use App\Support\Payroll\PayAdjustment;
use App\Support\Payroll\PayCalculator;
use App\Support\Payroll\PayInputLoader;
use App\Support\Payroll\PayResult;
use App\Support\Payroll\PayrollRoster;
use App\Support\Payroll\RosterResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Generating and recalculating a draft payroll run (design 5.6, plan Task 7).
 *
 *   $run = $generate->generate($hr, '2026-11');            // creates the draft (one per month) or recalculates it
 *   $run = $generate->recalculate($hr, $run);               // every line again from fresh inputs
 *   $run = $generate->recalculate($hr, $run, $employeeId);  // one line
 *
 * Lock order (plan, "Payroll lock order — run-level writes"): these writes insert and delete lines, and the foreign key
 * takes shared locks on the employee rows, so each transaction FIRST share-locks the employees the run can hold (every
 * employee joined by the month end, or the one employee, in id order: locking reads, which fix no read snapshot), THEN
 * locks the run row FOR UPDATE (generate: the existing row; only a month with no run inserts it first, so INSERT
 * IGNORE never meets a duplicate and upgrades a shared lock), THEN reads the later months' runs LOCK IN SHARE MODE
 * (month order). No plain read happens before these locks. DB::transaction(..., 3). A one-person recalculation for
 * someone who is not in the roster and has no line writes nothing (no totals, no audit entry).
 *
 * A line is updated in place (same item id): HR's inputs (excluded + reason, tax override, one-off adjustments) are
 * kept and fed back into the calculation. An excluded line stores the calculation's breakdown and warnings but pays
 * nothing: every money column is 0 and nothing blocks. Lines of people no longer in the roster are deleted with their
 * adjustments (listed in the audit entry). After every change the run's headcount (non-excluded lines) and totals
 * (gross; deductions = attendance + fixed + other + loan; tax; net), summary.settled_separately,
 * summary.left_without_last_day and calculated_at are written again (the dashboard tile reads them).
 *
 * Refusals (ValidationException): [month] malformed, after the current attendance month, in or before the latest
 * approved month, or an approved run of that month; [run] recalculating a run that is not a draft. Not payroll.run →
 * AuthorizationException (the controller checks it too).
 *
 * Audited (area payroll, subject the run): "Payroll generated" (the first generation), "Payroll recalculated".
 */
class GeneratePayrollRun
{
    public const LOCKED_MESSAGE = 'This payroll is approved and cannot change.';

    public function generate(User $by, string $month): PayrollRun
    {
        self::authorize($by);
        $this->checkMonth($month);

        return DB::transaction(function () use ($by, $month): PayrollRun {
            [, $last] = PayrollRoster::bounds($month);
            self::lockEmployees($last, null);

            // The existing run row FOR UPDATE; only a month with no run yet inserts it (INSERT IGNORE on a duplicate
            // would take a shared lock first and then upgrade it to exclusive: a deadlock between two generators).
            $run = PayrollRun::query()->where('month', $month)->lockForUpdate()->first();
            if ($run === null) {
                $now = now();
                PayrollRun::query()->insertOrIgnore([
                    'month' => $month,
                    'status' => 'draft',
                    'headcount' => 0,
                    'gross' => 0,
                    'deductions' => 0,
                    'tax' => 0,
                    'net' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $run = PayrollRun::query()->where('month', $month)->lockForUpdate()->firstOrFail();
            }

            if ($run->isLocked()) {
                throw ValidationException::withMessages(['month' => "Payroll for {$run->label()} is already approved."]);
            }

            if (($later = self::laterLocked($month, true)) !== null) {
                throw ValidationException::withMessages(['month' => 'Payroll for '.PayrollRun::monthLabel($later).' is already approved.']);
            }

            $first = $run->generated_at === null;
            if ($first) {
                $run->forceFill(['generated_by' => $by->id, 'generated_at' => now()]);
            }

            $stats = $this->apply($run, null);

            Audit::record('payroll', $first ? 'Payroll generated' : 'Payroll recalculated', $run, self::auditProperties($run, $stats), $by);

            return $run->fresh() ?? $run;
        }, 3);
    }

    public function recalculate(User $by, PayrollRun $run, ?int $employeeId = null): PayrollRun
    {
        self::authorize($by);

        return DB::transaction(function () use ($by, $run, $employeeId): PayrollRun {
            [, $last] = PayrollRoster::bounds($run->month);
            self::lockEmployees($last, $employeeId);
            $locked = self::lockDraft($run->id);

            $stats = $this->apply($locked, $employeeId);

            // One person who is not in the roster and has no line: nothing changed, nothing to record.
            if ($employeeId !== null && $stats['lines'] === 0 && $stats['removed'] === []) {
                return $locked;
            }

            Audit::record('payroll', 'Payroll recalculated', $locked, [
                ...self::auditProperties($locked, $stats),
                'employee' => $employeeId,
            ], $by);

            return $locked->fresh() ?? $locked;
        }, 3);
    }

    /**
     * Recalculates inside a transaction that already holds the run row FOR UPDATE (a draft; PayrollLineInputs). Writes
     * no audit entry (the caller audits its own change).
     *
     * @internal
     *
     * @return array{lines: int, added: int, changed: int, removed: list<array{id: int, name: string}>}
     */
    public function recalculateLocked(PayrollRun $locked, ?int $employeeId = null): array
    {
        return $this->apply($locked, $employeeId);
    }

    /**
     * The run row FOR UPDATE; a run that is not a draft is refused [run].
     *
     * @internal
     */
    public static function lockDraft(int $runId): PayrollRun
    {
        $run = PayrollRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();

        if (! $run->isDraft()) {
            throw ValidationException::withMessages(['run' => self::LOCKED_MESSAGE]);
        }

        return $run;
    }

    /**
     * Each person's fresh line: the PayResult and the payroll_items attributes it fills (zeroed for an excluded line).
     * HR's inputs are read from the existing lines ($items: employee id => PayrollItem with adjustments loaded).
     *
     * @internal
     *
     * @param  Collection<int, Employee>  $employees  complete models (PayrollRoster::forMonth()->included)
     * @param  Collection<int, PayrollItem>  $items  keyed by employee id
     * @return array<int, array{result: PayResult, attributes: array<string, mixed>}>
     */
    public static function calculateLines(string $month, Collection $employees, Collection $items): array
    {
        $inputs = [];
        foreach ($employees as $employee) {
            $item = $items->get($employee->id);

            if ($item === null) {
                continue;
            }

            $inputs[(int) $employee->id] = [
                'adjustments' => array_values($item->adjustments->sortBy('id')->map(fn (PayrollAdjustment $a): PayAdjustment => new PayAdjustment(
                    (int) $a->id, $a->label, $a->type, (int) $a->amount, (bool) $a->is_taxable,
                ))->all()),
                'tax_override' => $item->tax_overridden ? (int) $item->tax_override : null,
                'tax_override_reason' => $item->tax_overridden ? $item->tax_override_reason : null,
            ];
        }

        $loaded = PayInputLoader::load($month, $employees, $inputs);

        $lines = [];
        foreach ($employees as $employee) {
            $id = (int) $employee->id;
            $result = PayCalculator::calculate($loaded[$id]);
            $lines[$id] = ['result' => $result, 'attributes' => self::lineAttributes($result, (bool) $items->get($id)?->excluded)];
        }

        return $lines;
    }

    /**
     * Writes the run's headcount (non-excluded lines), totals, the roster lists and calculated_at. One aggregate query.
     *
     * @internal
     */
    public static function refreshTotals(PayrollRun $run, RosterResult $roster): void
    {
        $t = PayrollItem::query()
            ->where('payroll_run_id', $run->id)
            ->where('excluded', false)
            ->toBase()
            ->selectRaw('count(*) as headcount, coalesce(sum(gross), 0) as gross, '
                .'coalesce(sum(attendance_deduction + fixed_deductions + other_deductions + loan_deduction), 0) as deductions, '
                .'coalesce(sum(tax), 0) as tax, coalesce(sum(net), 0) as net')
            ->first();

        $summary = $run->summary ?? [];
        $summary['settled_separately'] = $roster->settledSeparately;
        $summary['left_without_last_day'] = $roster->leftWithoutLastDay;

        $run->forceFill([
            'headcount' => (int) ($t->headcount ?? 0),
            'gross' => (int) ($t->gross ?? 0),
            'deductions' => (int) ($t->deductions ?? 0),
            'tax' => (int) ($t->tax ?? 0),
            'net' => (int) ($t->net ?? 0),
            'summary' => $summary,
            'calculated_at' => now(),
        ])->save();
    }

    /**
     * The run's totals as the audit log records them: paisa plus a formatted copy.
     *
     * @internal
     *
     * @return array<string, mixed>
     */
    public static function totals(PayrollRun $run): array
    {
        return [
            'headcount' => $run->headcount,
            'gross' => $run->gross,
            'deductions' => $run->deductions,
            'tax' => $run->tax,
            'net' => $run->net,
            'totals_formatted' => [
                'gross' => Money::format($run->gross),
                'deductions' => Money::format($run->deductions),
                'tax' => Money::format($run->tax),
                'net' => Money::format($run->net),
            ],
        ];
    }

    /** @return array{lines: int, added: int, changed: int, removed: list<array{id: int, name: string}>} */
    private function apply(PayrollRun $run, ?int $employeeId): array
    {
        $roster = PayrollRoster::forMonth($run->month);
        $items = PayrollItem::query()
            ->where('payroll_run_id', $run->id)
            ->with('adjustments')
            ->get()
            ->keyBy('employee_id');

        $targets = $employeeId === null
            ? $roster->included
            : $roster->included->filter(fn (Employee $e): bool => (int) $e->id === $employeeId)->values();
        $targetIds = array_map('intval', $targets->pluck('id')->all());

        $candidates = $employeeId === null
            ? array_map('intval', $items->keys()->all())
            : ($items->has($employeeId) ? [$employeeId] : []);
        $removeIds = array_values(array_diff($candidates, $targetIds));

        if ($employeeId !== null && $targetIds === [] && $removeIds === []) {
            return ['lines' => 0, 'added' => 0, 'changed' => 0, 'removed' => []];
        }

        $lines = $targets->isEmpty() ? [] : self::calculateLines($run->month, $targets, $items);

        $added = 0;
        $changed = 0;
        foreach ($targets as $employee) {
            $id = (int) $employee->id;
            $item = $items->get($id);

            if ($item === null) {
                $item = new PayrollItem;
                $item->forceFill(['payroll_run_id' => $run->id, 'employee_id' => $id]);
                $added++;
            }

            $item->forceFill($lines[$id]['attributes']);
            if (! $item->exists) {
                $item->save();
            } elseif ($item->isDirty()) {
                $changed++;
                $item->save();
            }
        }

        $removed = [];
        if ($removeIds !== []) {
            $removed = array_values(Employee::query()
                ->whereIn('id', $removeIds)
                ->orderBy('id')
                ->get(['id', 'first_name', 'last_name'])
                ->map(fn (Employee $e): array => ['id' => (int) $e->id, 'name' => $e->full_name])
                ->all());

            $itemIds = $items->only($removeIds)->pluck('id')->all();
            PayrollAdjustment::query()->whereIn('payroll_item_id', $itemIds)->delete();
            PayrollItem::query()->whereIn('id', $itemIds)->delete();
        }

        self::refreshTotals($run, $roster);

        return ['lines' => count($targetIds), 'added' => $added, 'changed' => $changed, 'removed' => $removed];
    }

    /** @return array<string, mixed> */
    private static function lineAttributes(PayResult $result, bool $excluded): array
    {
        $attributes = $result->toItemAttributes();

        if ($excluded) {
            foreach (PayrollItem::FIGURES as $column) {
                $attributes[$column] = 0;
            }
            $attributes['blocking'] = [];
        }

        return $attributes;
    }

    /**
     * Share-locks the employees the run's lines can belong to, in id order: one employee, or everyone joined by the
     * month's last day (a superset of the roster). A locking read, so no read snapshot is fixed before the run lock.
     */
    private static function lockEmployees(string $last, ?int $employeeId): void
    {
        $query = Employee::query()->orderBy('id')->sharedLock();

        if ($employeeId !== null) {
            $query->whereKey($employeeId);
        } else {
            $query->where('joining_date', '<=', $last);
        }

        $query->get(['id']);
    }

    /**
     * The latest approved / paid month on or after $month, or null. Before the transaction a plain read (a check before
     * the real one). With $locking (under the run row lock): the LATER months' run rows only (the run's own row is
     * already locked for update and checked), selected by month only and read LOCK IN SHARE MODE, so an approval of a
     * later month cannot commit unseen while this generation runs.
     */
    private static function laterLocked(string $month, bool $locking = false): ?string
    {
        $statuses = (array) config('hrm.payroll.locked_statuses');

        if ($locking) {
            $later = PayrollRun::query()->where('month', '>', $month)->orderByDesc('month')->sharedLock()->pluck('status', 'month')
                ->filter(fn ($status): bool => in_array($status, $statuses, true))
                ->keys()
                ->first();

            return $later === null ? null : (string) $later;
        }

        $later = PayrollRun::query()
            ->where('month', '>=', $month)
            ->whereIn('status', $statuses)
            ->orderByDesc('month')
            ->value('month');

        return $later === null ? null : (string) $later;
    }

    /** Refusals that need no lock (checked again under the run lock where they can change). */
    private function checkMonth(string $month): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw ValidationException::withMessages(['month' => 'Choose a month.']);
        }

        $current = substr(app(AttendanceClock::class)->attendanceToday(), 0, 7);
        if ($month > $current) {
            throw ValidationException::withMessages(['month' => 'You can generate payroll up to '.PayrollRun::monthLabel($current).'.']);
        }

        if (($later = self::laterLocked($month)) !== null) {
            throw ValidationException::withMessages(['month' => 'Payroll for '.PayrollRun::monthLabel($later).' is already approved.']);
        }
    }

    private static function authorize(User $by): void
    {
        if (! $by->can('payroll.run')) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array{lines: int, added: int, changed: int, removed: list<array{id: int, name: string}>}  $stats
     * @return array<string, mixed>
     */
    private static function auditProperties(PayrollRun $run, array $stats): array
    {
        return [
            'month' => $run->month,
            ...self::totals($run),
            'lines' => $stats['lines'],
            'added' => $stats['added'],
            'changed' => $stats['changed'],
            'removed' => $stats['removed'],
        ];
    }
}
