<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Shift;
use App\Support\PayrollLock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Flags stored attendance days as needing a rebuild after a change to the rules they were worked out with (a shift, a
 * holiday, a person's employment window, or their approved leave). Only the needs_rebuild column is written (forLeaveDays
 * may also create bare pending rows); the scheduled rebuild does the work. A manual day is never flagged (a rebuild
 * would skip it anyway).
 *
 * Payroll lock: a row dated in a locked payroll month (an approved or paid run) is never flagged or created, so an
 * approved month keeps exactly the days it was paid on. Each call reads the locked months once (PayrollLock::lockedMonths
 * over the months the call could touch; inside a transaction that read takes the shared lock on payroll_runs before the
 * UPDATE locks any attendance row) and excludes them with a month expression (substr(date, 1, 7), valid on SQLite and
 * MariaDB). Never one lock query per row.
 */
class RebuildMarks
{
    /**
     * A shift's rules changed: the days from the attendance day of now (AttendanceClock::attendanceToday(), so at 00:30
     * yesterday is still included while its punch window runs) of the people who work it that are not closed yet.
     * Closed days are not flagged here. People with no shift of their own work the default shift.
     *
     * @return int the number of days flagged
     */
    public static function forShift(Shift $shift): int
    {
        $employees = Employee::query()->select('id')
            ->where(fn ($q) => $shift->is_default
                ? $q->where('shift_id', $shift->id)->orWhereNull('shift_id')
                : $q->where('shift_id', $shift->id));

        return self::forEmployeesFromToday($employees);
    }

    /**
     * The default moved to another shift: the pending days of people with no shift of their own.
     *
     * @return int the number of days flagged
     */
    public static function forEmployeesWithoutShift(): int
    {
        return self::forEmployeesFromToday(Employee::query()->select('id')->whereNull('shift_id'));
    }

    /**
     * One employee's shift or attendance-exempt flag changed: their days from today on that are not closed yet.
     *
     * @return int the number of days flagged
     */
    public static function forEmployee(int $employeeId): int
    {
        return self::forEmployeesFromToday(Employee::query()->select('id')->whereKey($employeeId));
    }

    /**
     * A holiday was added, moved or removed: every non-manual day on that date, closed or not.
     *
     * @return int the number of days flagged
     */
    public static function forDate(string $date): int
    {
        if (PayrollLock::isLocked(substr($date, 0, 7))) {
            return 0;
        }

        return Attendance::query()
            ->where('date', $date)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true]);
    }

    /**
     * One employee's joining date, last working day or status changed: their non-manual days that are now outside the
     * window attendance keeps for them (before the joining date, after the last working day, or, for a leaver without a
     * last working day, from the current attendance day on) are flagged, closed or not, so the rebuild removes them. One
     * bounded UPDATE on that employee's rows. Days the window gained are not flagged here: the hourly close backfills
     * missing days (CloseAttendanceDay).
     *
     * @return int the number of days flagged
     */
    public static function outsideEmployment(Employee $employee): int
    {
        $joined = $employee->joining_date->format('Y-m-d');
        $last = $employee->last_working_day?->format('Y-m-d');
        $leftWithoutDate = Leavers::withoutLastDay($employee);
        $today = app(AttendanceClock::class)->attendanceToday();

        return self::flagUnlocked((int) $employee->id, Attendance::query()
            ->where('employee_id', $employee->id)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->where(function ($q) use ($joined, $last, $leftWithoutDate, $today): void {
                $q->where('date', '<', $joined);

                if ($last !== null) {
                    $q->orWhere('date', '>', $last);
                }

                if ($leftWithoutDate) {
                    $q->orWhere('date', '>=', $today);
                }
            }));
    }

    /**
     * Leave days were added to or removed from these dates of one employee (approval, cancellation, holiday refund,
     * exit trimming): each date from AttendanceClock::startDate() up to AttendanceClock::attendanceToday() is marked so
     * the rebuild decides it again with the leave ledger. An existing non-manual row gets needs_rebuild = true, closed or
     * not; a date with no row gets a bare pending row (status null, needs_rebuild = true, is_manual = false) via
     * insert-or-ignore, exactly as punch ingestion does. Skipped: future dates (no row exists for a day not yet reached),
     * manual rows (manual attendance wins; the caller lists them as needing attention), and every date before the
     * attendance start date (design 5.7: leave there changes no attendance row, existing or not). While attendance has
     * not started (start date null) nothing is touched and 0 is returned.
     *
     * @param  list<string>  $dates  "Y-m-d", any order, duplicates allowed
     * @return int the rows flagged plus the rows created
     *
     * @throws \InvalidArgumentException a malformed date
     */
    public static function forLeaveDays(int $employeeId, array $dates): int
    {
        $today = app(AttendanceClock::class)->attendanceToday();
        $start = AttendanceClock::startDate();
        $wanted = [];

        foreach ($dates as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new \InvalidArgumentException("Not a date [{$date}].");
            }

            if ($start !== null && $date >= $start && $date <= $today) {
                $wanted[$date] = true;
            }
        }

        if ($wanted === []) {
            return 0;
        }

        // Dates in a locked payroll month, or in this person's month frozen by an approved final settlement, are left
        // alone (the leave paths already skip them; defensive). Two queries.
        $locked = array_flip(PayrollLock::lockedMonthsFor($employeeId, array_values(array_unique(array_map(
            fn (string $d): string => substr($d, 0, 7),
            array_keys($wanted),
        )))));
        $wanted = array_values(array_filter(array_keys($wanted), fn (string $d): bool => ! isset($locked[substr($d, 0, 7)])));

        if ($wanted === []) {
            return 0;
        }

        sort($wanted);

        $existing = Attendance::query()->toBase()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $wanted)
            ->pluck('date')
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->all();

        $missing = array_values(array_diff($wanted, $existing));

        $now = (new Attendance)->freshTimestampString();

        $flagged = Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $wanted)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true, 'updated_at' => $now]);

        $created = $missing === [] ? 0 : Attendance::query()->insertOrIgnore(array_map(fn (string $date) => [
            'employee_id' => $employeeId,
            'date' => $date,
            'status' => null, // pending calculation
            'is_manual' => false,
            'needs_rebuild' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $missing));

        return $flagged + $created;
    }

    /**
     * No payroll lock read here: every row it touches is dated from the current attendance day on, and approval refuses
     * a month that has not ended (PayrollChecks: the run's month must be before the current attendance month), so none
     * of these rows can be in a locked month. Leaving the read out also keeps the shift path (shift row -> attendance
     * rows) from taking a shared lock on payroll_runs after the shift row, which would close a lock cycle with a run
     * write that locks that shift's employees.
     *
     * @param  Builder<Employee>  $employees  a query selecting employee ids
     */
    private static function forEmployeesFromToday(Builder $employees): int
    {
        return Attendance::query()
            ->whereIn('employee_id', $employees)
            ->where('date', '>=', app(AttendanceClock::class)->attendanceToday())
            ->where('closed', false)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true]);
    }

    /**
     * Sets needs_rebuild on the rows $rows selects (one employee's), except those dated in a locked payroll month or in
     * that person's month frozen by an approved final settlement. Reads the distinct months of those rows (one plain
     * query), the frozen ones among them (two queries, none when there are no rows), then one UPDATE.
     *
     * @param  Builder<Attendance>  $rows
     */
    private static function flagUnlocked(int $employeeId, Builder $rows): int
    {
        $months = (clone $rows)->toBase()
            ->selectRaw('substr(date, 1, 7) as month')
            ->distinct()
            ->pluck('month')
            ->map(fn (mixed $m): string => (string) $m)
            ->all();

        if ($months === []) {
            return 0;
        }

        $locked = PayrollLock::lockedMonthsFor($employeeId, $months);

        if ($locked !== []) {
            $rows->whereNotIn(DB::raw('substr(date, 1, 7)'), $locked);
        }

        return $rows->update(['needs_rebuild' => true]);
    }
}
