<?php

namespace App\Support\Leave;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The one place balances are worked out (resolve from the container: app(LeaveBalances::class)).
 *
 * The formula (Phase 4 plan, Global Constraints):
 *
 *   remaining(Y) = allocated + carried(Y) + adjustment − opening_used − used(Y)
 *   used(Y)      = sum of leave_days.amount of that type dated in Y
 *   pending(Y)   = days of that type in Y of the employee's open requests (pending / manager_approved)
 *   available(Y) = remaining(Y) − pending(Y)
 *   carried(Y)   = 0 for a type without carry-forward, or when there is no Y−1 balance row;
 *                  else min(max(0, remaining(Y−1) − pending(Y−1)), carry_forward_cap). Open requests of Y−1 always
 *                  reserve: also after 1 January, a December request still waiting (or applied backdated) lowers what
 *                  carries into the new year until it is decided (owner ruling, phase 4 final fix round).
 *
 * Carried is never stored: it is worked out by walking the employee's balance rows for that type from the earliest
 * year up to Y, in memory, so a backdated leave day, an adjustment or a refund in an earlier year flows forward by
 * itself. A year with no row yet uses allocationFor() as a projected allocation (nothing is written; the line says
 * projected). Unpaid types have no rows and are never limited.
 *
 * Pending per year: an open request inside one year counts its stored `days`; a request across 31 Dec → 1 Jan is
 * recounted as it stands now (LeaveDays::planMany) and split with LeavePlan::byYear() (if that recount is refused, its
 * stored days count in its first year).
 *
 * "The current leave year" is the first four characters of AttendanceClock::attendanceToday(). All sums are done in
 * whole half days (ints; LeaveDays::halves / fromHalves), so figures are exact multiples of 0.5 with no float drift.
 */
class LeaveBalances
{
    public function __construct(private readonly AttendanceClock $clock) {}

    /**
     * The days a balance row for $year starts with: the type's full yearly quota; when setting
     * leave.prorate_new_joiners is on and the employee joined during $year after January,
     * floor(quota × monthsInclusive / 12 × 2) / 2 with monthsInclusive = 13 − joining month (rounded down to a half
     * day). A year before the joining year → 0. An unpaid type → 0.
     */
    public function allocationFor(Employee $employee, LeaveType $type, int $year): float
    {
        return LeaveDays::fromHalves($this->allocationHalves($employee, $type, $year, $this->prorates()));
    }

    /**
     * The employee's balance row for a paid type and year, created with allocationFor() when missing
     * (insertOrIgnore, then read, so two callers at once still end with one row). Never changes an existing row.
     *
     * @throws InvalidArgumentException for an unpaid type (unpaid types have no balance rows)
     */
    public function ensureRow(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        if ($type->isUnpaid()) {
            throw new InvalidArgumentException("The unpaid leave type [{$type->name}] has no balance rows.");
        }

        $stamp = now();

        LeaveBalance::query()->insertOrIgnore([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'year' => $year,
            'allocated' => $this->allocationFor($employee, $type, $year),
            'adjustment' => 0,
            'opening_used' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        return LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->where('year', $year)
            ->firstOrFail();
    }

    /**
     * ensureRow() for every active paid type. Does nothing for a year before the employee's joining year, after the
     * year of their last working day, or — for a leaver without a last working day (Leavers::withoutLastDay) — after
     * the current leave year.
     */
    public function ensureYear(Employee $employee, int $year): void
    {
        self::assertComplete($employee);

        $joinYear = (int) $employee->joining_date->format('Y');
        $lastYear = $employee->last_working_day !== null ? (int) $employee->last_working_day->format('Y') : null;

        if ($lastYear === null && Leavers::withoutLastDay($employee)) {
            $lastYear = (int) substr($this->clock->attendanceToday(), 0, 4);
        }

        if ($year < $joinYear || ($lastYear !== null && $year > $lastYear)) {
            return;
        }

        foreach (LeaveType::query()->active()->where('is_paid', true)->orderBy('sort_order')->orderBy('id')->get() as $type) {
            $this->ensureRow($employee, $type, $year);
        }
    }

    /**
     * The employee's balance lines for $year: every active type, plus inactive types that have a balance row, leave
     * days or pending days that year; in sort_order.
     *
     * $employee must be a COMPLETE Employee model (joining_date, last_working_day, status, shift_id, first/last name):
     * pro-rating, the employment window and the pending recount read them. A model loaded with a narrow select() is
     * refused with a LogicException naming the missing joining_date rather than failing later on null.
     *
     * @return list<BalanceLine>
     *
     * @throws LogicException an employee model without joining_date
     */
    public function lines(Employee $employee, int $year): array
    {
        return array_values($this->compute(new Collection([$employee]), $year, null, null, false)[$employee->id] ?? []);
    }

    /**
     * One balance line (any type, active or not). $ignoreRequestId leaves that open request out of pending (the
     * approval re-check: the request being approved must not reserve days against itself).
     *
     * Deciding money (submit / approve re-check) — MariaDB snapshot caveat: InnoDB's default isolation is REPEATABLE
     * READ, so plain SELECTs inside a transaction read the snapshot taken at the transaction's FIRST read, not the
     * latest committed rows. Two approvals running at once could each see the other's days as not yet taken. A caller
     * that decides on this line inside a transaction must either
     *   - lock the employee row (Employee::lockForUpdate()) as the FIRST statement of the transaction, so concurrent
     *     deciders for the same person queue and each sees the other's committed work; or
     *   - pass $locking = true: the balance rows, leave days and open requests are then read with sharedLock()
     *     (LOCK IN SHARE MODE), which reads the latest committed versions and blocks writers to them until commit.
     * SQLite (tests) ignores both locks; it serialises writers anyway.
     *
     * $extraPending (year => days) adds days to pending as if one more open request held them: they count in every
     * year's pending AND in the carry walk (a share in Y lowers what carries into Y+1). The submit and
     * approval checks pass the request's own per-year shares here, so a request across 31 Dec cannot use the same
     * days twice (once in Y, once again as carry into Y+1). A caller checking a request must then check every year
     * from its first year up to lastYearInUse(), not only the years it touches.
     *
     * @param  array<int, float|int>  $extraPending  year => days
     *
     * @throws LogicException an employee model without joining_date (see lines())
     */
    public function line(Employee $employee, LeaveType $type, int $year, ?int $ignoreRequestId = null, bool $locking = false, array $extraPending = []): BalanceLine
    {
        return $this->compute(new Collection([$employee]), $year, $type, $ignoreRequestId, $locking, $extraPending)[$employee->id][$type->id];
    }

    /**
     * The last year the employee has a balance row, approved leave days or open-request days for this type
     * ($ignoreRequestId left out), or null when there is none. A request's effect on carry can reach any year up to here.
     */
    public function lastYearInUse(Employee $employee, LeaveType $type, ?int $ignoreRequestId = null): ?int
    {
        $rowYear = DB::table('leave_balances')
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)->max('year');

        $lastTo = DB::table('leave_requests')
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->when($ignoreRequestId !== null, fn ($q) => $q->where('id', '!=', $ignoreRequestId))
            ->max('to_date');

        $lastDay = DB::table('leave_days')
            ->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->max('date');

        $years = array_filter([
            $rowYear !== null ? (int) $rowYear : null,
            $lastTo !== null ? (int) substr((string) $lastTo, 0, 4) : null,
            $lastDay !== null ? (int) substr((string) $lastDay, 0, 4) : null,
        ], fn (?int $y): bool => $y !== null);

        return $years === [] ? null : max($years);
    }

    /**
     * Lines for many employees at once (Balances screen, exports): employee id → type id → line, types chosen per
     * employee as in lines(). A fixed number of queries whatever the number of people: types, balance rows for years
     * ≤ $year, grouped leave-day sums per employee / type / year, open requests (plus a fixed set for recounting
     * requests across a year boundary).
     *
     * Every model must be a COMPLETE Employee (joining_date, last_working_day, status, shift_id, first/last name), as
     * in lines(): load them with a plain Employee::query()->get(), not a narrow select().
     *
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<int, BalanceLine>>
     *
     * @throws LogicException an employee model without joining_date
     */
    public function forEmployees(Collection $employees, int $year): array
    {
        return $this->compute($employees, $year, null, null, false);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  array<int, float|int>  $extraPending  year => days, added for $onlyType of every employee (line() only)
     * @return array<int, array<int, BalanceLine>>
     */
    private function compute(Collection $employees, int $year, ?LeaveType $onlyType, ?int $ignoreRequestId, bool $locking, array $extraPending = []): array
    {
        $employees = $employees->keyBy('id');

        if ($employees->isEmpty()) {
            return [];
        }

        $employees->each(fn (Employee $e) => self::assertComplete($e));

        $ids = $employees->keys()->map(fn ($id): int => (int) $id)->all();
        $types = $onlyType !== null
            ? new Collection([$onlyType])
            : LeaveType::query()->orderBy('sort_order')->orderBy('id')->get();
        $typeIds = array_values(array_map(fn (LeaveType $t): int => $t->id, $types->all()));
        $current = (int) substr($this->clock->attendanceToday(), 0, 4);
        $prorate = $this->prorates();
        $nextYear = sprintf('%04d-01-01', $year + 1);

        // Balance rows: employee => type => year => [allocated, adjustment, opening_used] in halves.
        $rows = [];
        foreach (DB::table('leave_balances')
            ->whereIn('employee_id', $ids)->whereIn('leave_type_id', $typeIds)->where('year', '<=', $year)
            ->when($locking, fn ($q) => $q->sharedLock())
            ->get(['employee_id', 'leave_type_id', 'year', 'allocated', 'adjustment', 'opening_used']) as $r) {
            $rows[(int) $r->employee_id][(int) $r->leave_type_id][(int) $r->year] = [
                LeaveDays::halves($r->allocated), LeaveDays::halves($r->adjustment), LeaveDays::halves($r->opening_used),
            ];
        }

        // Used: employee => type => year => halves.
        $used = [];
        foreach (DB::table('leave_days')
            ->whereIn('employee_id', $ids)->whereIn('leave_type_id', $typeIds)->where('date', '<', $nextYear)
            ->where(fn ($q) => LeaveLedger::wholeDaysOnly($q, 'leave_days'))
            ->selectRaw('employee_id, leave_type_id, substr(date, 1, 4) as yr, sum(amount) as total')
            ->groupBy('employee_id', 'leave_type_id', DB::raw('substr(date, 1, 4)'))
            ->when($locking, fn ($q) => $q->sharedLock())
            ->get() as $r) {
            $used[(int) $r->employee_id][(int) $r->leave_type_id][(int) $r->yr] = LeaveDays::halves($r->total);
        }

        // Pending from the earliest balance row year: an open request of any earlier year lowers what carries forward.
        $earliestRow = $year;
        foreach ($rows as $byType) {
            foreach ($byType as $byYear) {
                $earliestRow = min($earliestRow, min(array_keys($byYear)));
            }
        }

        $pending = $this->pendingHalves($employees, $typeIds, min($year, $current, $earliestRow), $year, $ignoreRequestId, $locking);

        if ($onlyType !== null) {
            foreach ($extraPending as $y => $days) {
                foreach ($ids as $id) {
                    $pending[$id][$onlyType->id][(int) $y] = ($pending[$id][$onlyType->id][(int) $y] ?? 0) + LeaveDays::halves($days);
                }
            }
        }

        $result = [];

        foreach ($employees as $id => $employee) {
            foreach ($types as $type) {
                $typeRows = $rows[$id][$type->id] ?? [];
                $typeUsed = $used[$id][$type->id] ?? [];
                $typePending = $pending[$id][$type->id] ?? [];

                if ($onlyType === null && ! $type->is_active && ! isset($typeRows[$year])
                    && ($typeUsed[$year] ?? 0) === 0 && ($typePending[$year] ?? 0) === 0) {
                    continue;
                }

                $result[$id][$type->id] = $type->isUnpaid()
                    ? $this->unpaidLine($type, $year, $typeUsed[$year] ?? 0, $typePending[$year] ?? 0)
                    : $this->paidLine($employee, $type, $year, $prorate, $typeRows, $typeUsed, $typePending);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, array{0: int, 1: int, 2: int}>  $rows  year => [allocated, adjustment, opening_used] halves
     * @param  array<int, int>  $used  year => halves
     * @param  array<int, int>  $pending  year => halves
     */
    private function paidLine(Employee $employee, LeaveType $type, int $year, bool $prorate, array $rows, array $used, array $pending): BalanceLine
    {
        $cap = LeaveDays::halves($type->carry_forward_cap);
        $carried = 0; // carried into $y, walking up from the earliest row year (nothing carries into it)

        if ($rows !== []) {
            for ($y = min(array_keys($rows)); $y < $year; $y++) {
                if (! isset($rows[$y]) || ! $type->carry_forward) {
                    $carried = 0;

                    continue;
                }

                [$allocated, $adjustment, $openingUsed] = $rows[$y];
                // Open requests of year y are already spoken for, whether y is past (a December request still waiting
                // in January) or this year (carrying into a projected next year): they never carry.
                $remaining = $allocated + $carried + $adjustment - $openingUsed - ($used[$y] ?? 0) - ($pending[$y] ?? 0);

                $carried = min(max(0, $remaining), $cap);
            }
        }

        $row = $rows[$year] ?? null;
        $allocated = $row[0] ?? $this->allocationHalves($employee, $type, $year, $prorate);
        $adjustment = $row[1] ?? 0;
        $openingUsed = $row[2] ?? 0;
        $usedHalves = $used[$year] ?? 0;
        $pendingHalves = $pending[$year] ?? 0;
        $remaining = $allocated + $carried + $adjustment - $openingUsed - $usedHalves;

        return new BalanceLine(
            typeId: $type->id,
            typeName: $type->name,
            colour: $type->colour,
            year: $year,
            isPaid: true,
            allocated: LeaveDays::fromHalves($allocated),
            carried: LeaveDays::fromHalves($carried),
            adjustment: LeaveDays::fromHalves($adjustment),
            openingUsed: LeaveDays::fromHalves($openingUsed),
            used: LeaveDays::fromHalves($usedHalves),
            pending: LeaveDays::fromHalves($pendingHalves),
            remaining: LeaveDays::fromHalves($remaining),
            available: LeaveDays::fromHalves($remaining - $pendingHalves),
            overdrawn: $remaining < 0,
            projected: $row === null,
        );
    }

    private function unpaidLine(LeaveType $type, int $year, int $used, int $pending): BalanceLine
    {
        return new BalanceLine(
            typeId: $type->id,
            typeName: $type->name,
            colour: $type->colour,
            year: $year,
            isPaid: false,
            allocated: 0.0,
            carried: 0.0,
            adjustment: 0.0,
            openingUsed: 0.0,
            used: LeaveDays::fromHalves($used),
            pending: LeaveDays::fromHalves($pending),
            remaining: 0.0,
            available: INF,
            overdrawn: false,
        );
    }

    /**
     * Pending halves of open requests touching years $fromYear..$toYear: employee => type => year => halves.
     *
     * @param  Collection<int, Employee>  $employees  keyed by id
     * @param  list<int>  $typeIds
     * @return array<int, array<int, array<int, int>>>
     */
    private function pendingHalves(Collection $employees, array $typeIds, int $fromYear, int $toYear, ?int $ignoreRequestId, bool $locking = false): array
    {
        $requests = DB::table('leave_requests')
            ->whereIn('employee_id', $employees->keys()->all())
            ->whereIn('leave_type_id', $typeIds)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->where('to_date', '>=', sprintf('%04d-01-01', $fromYear))
            // "<" the next 1 January, not "<=" 31 December: SQLite may hold the date as "Y-m-d 00:00:00".
            ->where('from_date', '<', sprintf('%04d-01-01', $toYear + 1))
            ->when($ignoreRequestId !== null, fn ($q) => $q->where('id', '!=', $ignoreRequestId))
            ->orderBy('id')
            ->when($locking, fn ($q) => $q->sharedLock())
            ->get(['id', 'employee_id', 'leave_type_id', 'from_date', 'to_date', 'half', 'days']);

        $pending = [];
        $spanning = [];

        foreach ($requests as $r) {
            $from = substr((string) $r->from_date, 0, 10);
            $to = substr((string) $r->to_date, 0, 10);
            $fromYearOf = (int) substr($from, 0, 4);

            if ($fromYearOf === (int) substr($to, 0, 4)) {
                $pending[(int) $r->employee_id][(int) $r->leave_type_id][$fromYearOf] =
                    ($pending[(int) $r->employee_id][(int) $r->leave_type_id][$fromYearOf] ?? 0) + LeaveDays::halves($r->days);
            } else {
                $spanning[] = [$r, $from, $to];
            }
        }

        if ($spanning === []) {
            return $pending;
        }

        $plans = LeaveDays::planMany(array_map(
            fn (array $s): array => [$employees[(int) $s[0]->employee_id], $s[1], $s[2], $s[0]->half],
            $spanning,
        ));

        foreach ($spanning as $i => [$r, $from]) {
            $byYear = $plans[$i]->isRefused()
                ? [(int) substr($from, 0, 4) => (float) $r->days]
                : $plans[$i]->byYear();

            foreach ($byYear as $y => $days) {
                $pending[(int) $r->employee_id][(int) $r->leave_type_id][$y] =
                    ($pending[(int) $r->employee_id][(int) $r->leave_type_id][$y] ?? 0) + LeaveDays::halves($days);
            }
        }

        return $pending;
    }

    private function allocationHalves(Employee $employee, LeaveType $type, int $year, bool $prorate): int
    {
        if ($type->isUnpaid()) {
            return 0;
        }

        $quota = LeaveDays::halves($type->yearly_quota);
        $joinYear = (int) $employee->joining_date->format('Y');
        $joinMonth = (int) $employee->joining_date->format('n');

        if ($year < $joinYear) {
            return 0;
        }

        if ($year === $joinYear && $prorate && $joinMonth > 1) {
            // quota × (13 − month) / 12, rounded down to a half day: integer division on half days is exact.
            return intdiv($quota * (13 - $joinMonth), 12);
        }

        return $quota;
    }

    /**
     * Refuses an Employee model loaded without joining_date (a narrow select()), which would otherwise fail later as a
     * call on null.
     */
    private static function assertComplete(Employee $employee): void
    {
        if (! array_key_exists('joining_date', $employee->getAttributes()) || $employee->getAttributes()['joining_date'] === null) {
            throw new LogicException(
                "LeaveBalances needs complete Employee models: employee [{$employee->getKey()}] was loaded without joining_date "
                .'(load it with joining_date, last_working_day, status, shift_id and the names, e.g. a plain Employee::query()->get()).',
            );
        }
    }

    private function prorates(): bool
    {
        return (bool) settings('leave.prorate_new_joiners', true);
    }
}
