<?php

namespace App\Support;

use App\Models\FinalSettlement;
use App\Models\PayrollRun;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Whether a payroll month is locked: it has an approved or paid run (config('hrm.payroll.locked_statuses')). A locked
 * month is frozen: nothing may add or remove leave days, attendance, holidays, employment dates or salary records that
 * touch it. Every write that does calls this (leave since Phase 4; attendance, holidays, employees and salary in
 * Phase 5).
 *
 *   PayrollLock::isLocked('2026-11');                              // true once November 2026 is approved
 *   PayrollLock::firstLocked(['2026-11-24', '2026-12-01']);        // "2026-11", or null when neither month is locked
 *   PayrollLock::lockedMonths(['2026-10', '2026-11']);             // ["2026-11"] (one query)
 *   PayrollLock::latestLocked();                                   // "2026-11", or null when nothing is locked yet
 *   PayrollLock::rangeTouchesLocked('2026-10-15', '2026-12-02');   // "2026-11" (either order, both days included)
 *   PayrollLock::message('2026-11');                               // "Payroll for November 2026 is approved, so ..."
 *
 * Races: inside a transaction (DB::transactionLevel() > 0) the read takes a shared lock on the run rows it reads, so a
 * write racing an approval (which locks the run row for update first) waits for the approval to commit and then sees
 * the month locked. Outside a transaction it is a plain read (a check before the real write, which checks again).
 * Every read here is a plain top-level SELECT ... LOCK IN SHARE MODE (value / pluck), never exists(), and selects
 * the run rows by month only (never by status in SQL), so a draft run row in the range is share-locked as well.
 *
 * Lock order (binding for every payroll-related write, to rule out deadlocks): employee rows first, then the
 * payroll_runs row.
 *   - Per-employee writers (leave, attendance, salary profiles, loans, employment dates) lock the employee row(s)
 *     first and only then call PayrollLock (the shared lock on the run row).
 *   - Run-level writes that insert, change or delete payroll lines (generate, recalculate, approve) FIRST take shared
 *     locks on all the run's employees in id order
 *     (Employee::query()->whereIn('id', $ids)->orderBy('id')->sharedLock()->get(['id'])), THEN lockForUpdate() the run
 *     row. The run row lock is the first statement after the employee locks: no plain read of the run before it
 *     (under REPEATABLE READ a plain read fixes the snapshot before the lock is held).
 *   - Never call PayrollLock (shared lock on a run row) and then lockForUpdate() the same run row in one transaction:
 *     two such transactions both hold the shared lock and deadlock upgrading it.
 *   - Avoid latestLocked() inside write transactions: it share-locks every locked run row, widening what the
 *     transaction holds.
 *
 * Tests lock a month by creating the run: payrollRun('2026-11', 'approved') (tests/Pest.php).
 *
 * Per person (Phase 6c): an approved or paid final settlement freezes that person's settlement month exactly as an
 * approved run freezes a month for everybody. Every per-employee writer asks the *For() variants, true when the month
 * is locked (as above) OR that person has an approved / paid settlement row for it:
 *
 *   PayrollLock::isLockedFor(7, '2026-11');                            // month locked, or 7's November is settled
 *   PayrollLock::lockedMonthsFor(7, ['2026-10', '2026-11']);           // ["2026-11"]
 *   PayrollLock::firstLockedFor(7, ['2026-11-24']);                    // "2026-11" or null
 *   PayrollLock::rangeTouchesLockedFor(7, '2026-10-15', '2026-12-02'); // earliest locked or settled month in range
 *   PayrollLock::settledMonths([7, 9], ['2026-11']);                   // [7 => ['2026-11']] (one query, batch readers)
 *   PayrollLock::messageForEmployee(7, '2026-11');                     // the run message, else the settled message
 *
 * Settlement rows are read like run rows: selected by employee_id (and month) only, the status filtered in PHP, with a
 * shared lock inside a transaction, so a DRAFT settlement row is share-locked too and its approval (FOR UPDATE) waits.
 *
 * Lock order with settlements: employee row(s) -> payroll_runs (shared) -> final_settlements (shared). The *For()
 * methods always read payroll_runs first, then final_settlements. Settlement approval takes the employee row FOR UPDATE,
 * the resignation row, the runs it checks (shared), then its own settlement row FOR UPDATE, then loans, and never
 * reads PayrollLock on its own row, so it cannot cycle with a writer: per-employee writers wait on the employee row;
 * writers that lock no employee row (rebuilder, ingestor, rebuild marks, manual correction) share-lock the settlement
 * row before any attendance row and so serialise with the approval's FOR UPDATE.
 */
final class PayrollLock
{
    /**
     * @param  string  $month  "Y-m"
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function isLocked(string $month): bool
    {
        self::assertMonth($month);

        // A plain top-level SELECT ... LOCK IN SHARE MODE, never exists(): Laravel compiles exists() as
        // "select exists(select ... lock in share mode)", and whether MariaDB takes the lock inside that subquery is
        // not something to rely on.
        return self::isLockedStatus(self::rows()->where('month', $month)->value('status'));
    }

    /**
     * The first "Y-m" (in the order given) among these dates whose payroll month is locked, or null when none is.
     * Every date is checked for form first; then one query.
     *
     * @param  iterable<string>  $dates  "Y-m-d"
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function firstLocked(iterable $dates): ?string
    {
        $months = [];

        foreach ($dates as $date) {
            self::assertDate($date);
            $months[substr($date, 0, 7)] = true;
        }

        if ($months === []) {
            return null;
        }

        $locked = array_flip(self::lockedMonths(array_keys($months)));

        foreach (array_keys($months) as $month) {
            if (isset($locked[$month])) {
                return $month;
            }
        }

        return null;
    }

    /**
     * The locked months among these, ascending, each once. One query (none for an empty list).
     *
     * @param  array<int|string, string>  $months  "Y-m"
     * @return list<string>
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function lockedMonths(array $months): array
    {
        $months = array_values(array_unique($months));

        foreach ($months as $month) {
            self::assertMonth($month);
        }

        if ($months === []) {
            return [];
        }

        return self::lockedOf(self::rows()->whereIn('month', $months)->orderBy('month')->pluck('status', 'month')->all());
    }

    /** The latest locked month ("Y-m"), or null when no month is locked. */
    public static function latestLocked(): ?string
    {
        return self::lockedOf(self::rows()->orderByDesc('month')->pluck('status', 'month')->all())[0] ?? null;
    }

    /**
     * The first (earliest) locked "Y-m" whose month lies between the months of two dates, both included, in either
     * order; null when none does. One query. Used for a range such as an employment date change (old to new).
     *
     * @param  string  $from  "Y-m-d"
     * @param  string  $to  "Y-m-d"
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function rangeTouchesLocked(string $from, string $to): ?string
    {
        self::assertDate($from);
        self::assertDate($to);

        [$low, $high] = $from <= $to ? [$from, $to] : [$to, $from];

        $rows = self::rows()
            ->whereBetween('month', [substr($low, 0, 7), substr($high, 0, 7)])
            ->orderBy('month')
            ->pluck('status', 'month')
            ->all();

        return self::lockedOf($rows)[0] ?? null;
    }

    /** "Payroll for November 2026 is approved, so this can no longer change." */
    public static function message(string $month): string
    {
        self::assertMonth($month);

        return 'Payroll for '.PayrollRun::monthLabel($month).' is approved, so this can no longer change.';
    }

    /**
     * Whether $employeeId's $month is frozen: the month is locked, or the person has an approved / paid settlement for
     * it. Two queries at most (runs first, then settlements).
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function isLockedFor(int $employeeId, string $month): bool
    {
        return self::isLocked($month) || self::isSettled($employeeId, $month);
    }

    /**
     * The months among these frozen for this person (locked or settled), ascending, each once. Two queries at most.
     *
     * @param  array<int|string, string>  $months  "Y-m"
     * @return list<string>
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function lockedMonthsFor(int $employeeId, array $months): array
    {
        $locked = self::lockedMonths($months);

        if ($months === []) {
            return [];
        }

        $all = array_values(array_unique([...$locked, ...(self::settledMonths([$employeeId], $months)[$employeeId] ?? [])]));
        sort($all);

        return $all;
    }

    /**
     * The first "Y-m" (in the order given) among these dates frozen for this person, or null.
     *
     * @param  iterable<string>  $dates  "Y-m-d"
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function firstLockedFor(int $employeeId, iterable $dates): ?string
    {
        $months = [];

        foreach ($dates as $date) {
            self::assertDate($date);
            $months[substr($date, 0, 7)] = true;
        }

        if ($months === []) {
            return null;
        }

        $locked = array_flip(self::lockedMonthsFor($employeeId, array_keys($months)));

        foreach (array_keys($months) as $month) {
            if (isset($locked[$month])) {
                return $month;
            }
        }

        return null;
    }

    /**
     * The earliest month between the months of two dates (both included, either order) that is locked or settled for
     * this person; null when none is. Two queries (runs, then this person's settlement rows).
     *
     * @param  string  $from  "Y-m-d"
     * @param  string  $to  "Y-m-d"
     *
     * @throws InvalidArgumentException a malformed date
     */
    public static function rangeTouchesLockedFor(int $employeeId, string $from, string $to): ?string
    {
        $run = self::rangeTouchesLocked($from, $to);

        [$low, $high] = $from <= $to ? [$from, $to] : [$to, $from];

        $rows = self::settlementRows()
            ->where('employee_id', $employeeId)
            ->orderBy('month')
            ->get(['employee_id', 'month', 'status'])
            ->all();

        $settled = self::frozenOf($rows, substr($low, 0, 7), substr($high, 0, 7))[$employeeId][0] ?? null;

        if ($run === null || $settled === null) {
            return $run ?? $settled;
        }

        return min($run, $settled);
    }

    /**
     * Per employee, the months among $months for which they have an approved or paid settlement (ascending). Only
     * employees with at least one such month are keys. ONE query (none for an empty list): rows selected by employee
     * and month only, the status filtered in PHP, shared lock inside a transaction.
     *
     * @param  array<int|string, int>  $employeeIds
     * @param  array<int|string, string>  $months  "Y-m"
     * @return array<int, list<string>>
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function settledMonths(array $employeeIds, array $months): array
    {
        $months = array_values(array_unique($months));
        $employeeIds = array_values(array_unique(array_map('intval', $employeeIds)));

        foreach ($months as $month) {
            self::assertMonth($month);
        }

        if ($months === [] || $employeeIds === []) {
            return [];
        }

        return self::frozenOf(self::settlementRows()
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('month', $months)
            ->orderBy('employee_id')
            ->orderBy('month')
            ->get(['employee_id', 'month', 'status'])
            ->all());
    }

    /**
     * Whether this person has an approved or paid settlement for $month (the run lock is not considered). One query.
     *
     * @throws InvalidArgumentException a malformed month
     */
    public static function isSettled(int $employeeId, string $month): bool
    {
        return (self::settledMonths([$employeeId], [$month])[$employeeId] ?? []) !== [];
    }

    /** The refusal for a frozen month: the settled message when $settled, else message(). */
    public static function messageFor(string $month, bool $settled): string
    {
        return $settled ? self::settledMessage($month) : self::message($month);
    }

    /** "The final settlement for November 2026 is approved, so this can no longer change." */
    public static function settledMessage(string $month): string
    {
        self::assertMonth($month);

        return 'The final settlement for '.PayrollRun::monthLabel($month).' is approved, so this can no longer change.';
    }

    /**
     * The refusal for a month already known to be frozen for this person (a *For() check returned it): message() when
     * the month itself is locked, else the settled message. One more read of that month's run, on the refusal path only.
     */
    public static function messageForEmployee(int $employeeId, string $month): string
    {
        return self::messageFor($month, ! self::isLocked($month));
    }

    /**
     * Runs in a locked status; with a shared lock (SELECT ... LOCK IN SHARE MODE / FOR SHARE) inside a transaction.
     * Public for tests (and one refusal label in SaveSalaryProfile). The readers above do NOT use it: they read by
     * month only through rows() and filter the status in PHP (see rows()).
     *
     * @internal
     *
     * @return Builder<PayrollRun>
     */
    public static function query(): Builder
    {
        $query = PayrollRun::query()->whereIn('status', (array) config('hrm.payroll.locked_statuses'));

        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query;
    }

    /**
     * Every run row (whatever its status) for the readers above: they select by month only (equality, list or range on
     * the unique month index) and filter the status in PHP. A `status in (...)` condition in SQL could let MariaDB read
     * through the status index and never share-lock a draft row, so an approval of that draft would not wait for the
     * reader. Shared lock inside a transaction, as query().
     */
    private static function rows(): QueryBuilder
    {
        $query = DB::table((new PayrollRun)->getTable());

        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query;
    }

    /** final_settlements rows for the *For() readers: shared lock inside a transaction, as rows(). */
    private static function settlementRows(): QueryBuilder
    {
        $query = DB::table((new FinalSettlement)->getTable());

        if (DB::transactionLevel() > 0) {
            $query->sharedLock();
        }

        return $query;
    }

    /**
     * Employee id => the frozen (approved / paid) months of these settlement rows, ascending, optionally only months
     * from $low to $high ("Y-m", both included).
     *
     * @param  array<int|string, \stdClass>  $rows  {employee_id, month, status}
     * @return array<int, list<string>>
     */
    private static function frozenOf(array $rows, ?string $low = null, ?string $high = null): array
    {
        $frozen = (array) config('hrm.exit.frozen_statuses');
        $result = [];

        foreach ($rows as $row) {
            $month = (string) $row->month;

            if (! in_array($row->status, $frozen, true) || ($low !== null && $month < $low) || ($high !== null && $month > $high)) {
                continue;
            }

            $result[(int) $row->employee_id][$month] = $month;
        }

        foreach ($result as $id => $list) {
            ksort($list);
            $result[$id] = array_values($list);
        }

        return $result;
    }

    private static function isLockedStatus(mixed $status): bool
    {
        return is_string($status) && in_array($status, (array) config('hrm.payroll.locked_statuses'), true);
    }

    /**
     * The months of these rows whose status is locked, in the rows' order.
     *
     * @param  array<int|string, mixed>  $statusByMonth  month => status
     * @return list<string>
     */
    private static function lockedOf(array $statusByMonth): array
    {
        $locked = [];

        foreach ($statusByMonth as $month => $status) {
            if (self::isLockedStatus($status)) {
                $locked[] = (string) $month;
            }
        }

        return $locked;
    }

    private static function assertMonth(mixed $month): void
    {
        if (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            $shown = is_scalar($month) ? (string) $month : get_debug_type($month);

            throw new InvalidArgumentException("Not a payroll month [{$shown}].");
        }
    }

    private static function assertDate(mixed $date): void
    {
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            $shown = is_scalar($date) ? (string) $date : get_debug_type($date);

            throw new InvalidArgumentException("Not a date [{$shown}].");
        }
    }
}
