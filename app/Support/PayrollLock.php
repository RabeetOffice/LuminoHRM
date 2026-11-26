<?php

namespace App\Support;

use App\Models\PayrollRun;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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
 * Every read here is a plain top-level SELECT ... LOCK IN SHARE MODE (value / pluck), never exists().
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
        return self::query()->where('month', $month)->value('id') !== null;
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

        /** @var list<string> $locked */
        $locked = self::query()->whereIn('month', $months)->orderBy('month')->pluck('month')->map(fn ($m) => (string) $m)->all();

        return $locked;
    }

    /** The latest locked month ("Y-m"), or null when no month is locked. */
    public static function latestLocked(): ?string
    {
        $month = self::query()->orderByDesc('month')->value('month');

        return $month === null ? null : (string) $month;
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

        $month = self::query()
            ->whereBetween('month', [substr($low, 0, 7), substr($high, 0, 7)])
            ->orderBy('month')
            ->value('month');

        return $month === null ? null : (string) $month;
    }

    /** "Payroll for November 2026 is approved, so this can no longer change." */
    public static function message(string $month): string
    {
        self::assertMonth($month);

        return 'Payroll for '.PayrollRun::monthLabel($month).' is approved, so this can no longer change.';
    }

    /**
     * Runs in a locked status; with a shared lock (SELECT ... LOCK IN SHARE MODE / FOR SHARE) inside a transaction.
     * Public for tests only.
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
