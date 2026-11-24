<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns punches into `attendances` rows: loads each employee-day's inputs, asks DayCalculator, writes the result.
 *
 *   $rebuilder = app(AttendanceRebuilder::class);
 *   $rebuilder->rebuild($employee, '2026-11-24');      // one employee-day
 *   $rebuilder->rebuildPending();                      // rows marked needs_rebuild (by punch ingestion), oldest first
 *   $rebuilder->rebuildRange('2026-11-01', '2026-11-30', [$employee->id]);   // explicit, with the shift in force now
 *   $rebuilder->closeDay('2026-11-24');                // the nightly close for one past date
 *
 * Rules kept here (the day rules themselves are in DayCalculator):
 *   - A manual row (is_manual) is never written, deleted or marked: not even its needs_rebuild flag is cleared. Pending
 *     selection leaves manual rows out, so a manual day marked by a new punch waits until the manual flag is cleared.
 *   - No row before the joining date or after the last working day: a non-manual row there is deleted.
 *   - A day with no punches (absent / weekend / holiday / exempt present) is recorded for an employee whose status is
 *     inactive, resigned or terminated only when a last working day is set (the date is then inside it); an existing row
 *     is kept up to date, a missing one is not created. Nor is such a row created for a date after today.
 *   - The shift: the employee's shift in force (Shift::forEmployee semantics). A row that is already closed keeps the
 *     shift it was decided with (its shift_id) unless the rebuild is an explicit range rebuild ($currentShift).
 *   - closed = the date is before AttendanceClock::today().
 *
 * Every batch reads its inputs with a fixed number of queries (rows, employees, shifts, holidays, punches), whatever the
 * number of employee-days, inside one transaction that locks the existing rows first, so a punch stored meanwhile either
 * is seen or re-marks the row after this commit.
 */
final class AttendanceRebuilder
{
    /** Employee statuses for which no new no-punch day is created unless a last working day is set. */
    private const LEAVING_STATUSES = ['inactive', 'resigned', 'terminated'];

    public function __construct(
        private readonly DayCalculator $calculator,
        private readonly AttendanceClock $clock,
    ) {}

    /** Rebuilds one employee-day ("Y-m-d"). A manual row is left exactly as it is. */
    public function rebuild(Employee $e, string $date): void
    {
        $this->process([[(int) $e->id, $this->date($date)]], false);
    }

    /** Rebuilds up to $limit non-manual rows marked needs_rebuild, oldest date first. Returns how many were handled. */
    public function rebuildPending(int $limit = 500): int
    {
        $pairs = Attendance::query()->toBase()
            ->where('needs_rebuild', true)
            ->where('is_manual', false)
            ->orderBy('date')
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get(['employee_id', 'date'])
            ->map(fn (object $row): array => [(int) $row->employee_id, substr((string) $row->date, 0, 10)])
            ->all();

        return $this->process($pairs, false);
    }

    /**
     * Explicit rebuild of every day from $from to $to (inclusive, "Y-m-d", not after today) for the given employees (all
     * when null), with the shift in force now, also for days already closed. Manual rows are left alone.
     *
     * @param  list<int>|null  $employeeIds
     * @return int employee-days written or deleted
     */
    public function rebuildRange(string $from, string $to, ?array $employeeIds = null): int
    {
        $from = $this->date($from);
        $to = $this->date($to);
        $today = $this->clock->today()->format('Y-m-d');

        if ($from > $to || $to > $today) {
            throw new InvalidArgumentException('The range must run forwards and end no later than today.');
        }

        $query = Employee::query()->with('shift')
            ->where('joining_date', '<', $this->nextDay($to))
            ->where(fn ($q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $from));

        if ($employeeIds !== null) {
            $query->whereIn('id', $employeeIds);
        }

        $employees = $query->get()->keyBy('id');
        $written = 0;

        for ($day = $from; $day <= $to; $day = $this->nextDay($day)) {
            $pairs = $employees->keys()->map(fn ($id): array => [(int) $id, $day])->all();

            // Rows of employees outside the query (joined later, left earlier) are included so they get removed.
            $others = Attendance::query()->toBase()->where('date', $day)->where('is_manual', false)
                ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
                ->whereNotIn('employee_id', $employees->keys()->all())
                ->pluck('employee_id');

            foreach ($others as $id) {
                $pairs[] = [(int) $id, $day];
            }

            $written += $this->process($pairs, true, $employees);
        }

        return $written;
    }

    /**
     * The nightly close of one past date: every employee who should have a record that day and has none gets one
     * (absent, weekend, holiday, or present when exempt; decided from their punches if they have any), every non-manual
     * row still open is decided again as a closed day (so a single punch is flagged), the day's non-manual rows are
     * marked closed, then pending rows are rebuilt. Running it again changes nothing.
     *
     * @return int employee-days written or deleted for $date
     */
    public function closeDay(string $date): int
    {
        $date = $this->date($date);

        if ($date >= $this->clock->today()->format('Y-m-d')) {
            throw new InvalidArgumentException("Only a past day can be closed [{$date}].");
        }

        $employees = Employee::query()->with('shift')
            ->where('joining_date', '<', $this->nextDay($date))
            ->where(fn ($q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $date))
            ->get()
            ->keyBy('id');

        $rows = Attendance::query()->toBase()->where('date', $date)
            ->get(['employee_id', 'is_manual', 'closed'])
            ->keyBy('employee_id');

        $pairs = [];

        foreach ($employees as $id => $employee) {
            if (! $rows->has($id)) {
                $pairs[(int) $id] = [(int) $id, $date];
            }
        }

        foreach ($rows as $id => $row) {
            if (! $row->is_manual && ! $row->closed) {
                $pairs[(int) $id] = [(int) $id, $date];
            }
        }

        $written = $this->process(array_values($pairs), false, $employees);

        // Safety net: anything non-manual still open on that day (normally nothing left) is marked closed.
        Attendance::query()->where('date', $date)->where('closed', false)->where('is_manual', false)->update(['closed' => true]);

        $this->rebuildPending();

        return $written;
    }

    /**
     * Decides and writes the given employee-days in one transaction with a fixed number of reads.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs  [employee id, "Y-m-d"]
     * @param  Collection<int|string, Employee>|null  $employees  already loaded (with shift), keyed by id
     * @return int rows written or deleted
     */
    private function process(array $pairs, bool $currentShift, ?Collection $employees = null): int
    {
        $unique = [];
        foreach ($pairs as [$id, $date]) {
            $unique["{$id}|{$date}"] = [$id, $date];
        }

        if ($unique === []) {
            return 0;
        }

        $employeeIds = array_values(array_unique(array_column($unique, 0)));
        $dates = array_values(array_unique(array_column($unique, 1)));
        $today = $this->clock->today()->format('Y-m-d');

        return DB::transaction(function () use ($unique, $employeeIds, $dates, $today, $currentShift, $employees): int {
            // Lock first, then read the punches: a punch committed meanwhile is either seen here or re-marks the row
            // (needs_rebuild) after this transaction commits.
            $rows = Attendance::query()
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('date', $dates)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Attendance $row) => $row->employee_id.'|'.$this->rawDate($row));

            // Plain id => Employee array (Eloquent's Collection::only() would re-index by position).
            $byId = [];
            foreach ($employees ?? [] as $employee) {
                $byId[(int) $employee->id] = $employee;
            }

            $missing = array_values(array_diff($employeeIds, array_keys($byId)));

            if ($missing !== []) {
                foreach (Employee::query()->with('shift')->whereIn('id', $missing)->get() as $employee) {
                    $byId[(int) $employee->id] = $employee;
                }
            }

            $default = Shift::default();

            $storedShiftIds = $currentShift ? [] : $rows
                ->filter(fn (Attendance $row) => $row->closed && ! $row->is_manual && $row->shift_id !== null)
                ->pluck('shift_id')->unique()->values()->all();
            $storedShifts = $storedShiftIds === [] ? collect() : Shift::query()->whereIn('id', $storedShiftIds)->get()->keyBy('id');

            $holidays = array_flip(Holiday::query()->toBase()->whereIn('date', $dates)->pluck('date')
                ->map(fn ($d) => substr((string) $d, 0, 10))->all());

            $punches = [];
            DevicePunch::query()->toBase()
                ->whereIn('employee_id', $employeeIds)
                ->whereIn('punch_date', $dates)
                ->orderBy('punch_time')
                ->get(['employee_id', 'punch_date', 'punch_time'])
                ->each(function (object $p) use (&$punches): void {
                    $punches[$p->employee_id.'|'.substr((string) $p->punch_date, 0, 10)][] = substr((string) $p->punch_time, 0, 19);
                });

            $overtimeMin = settings('attendance.overtime_min_minutes');
            $overtimeMin = is_int($overtimeMin) && $overtimeMin >= 0 ? $overtimeMin : 0;

            $inserts = [];
            $updates = [];
            $deletes = [];

            foreach ($unique as $key => [$id, $date]) {
                $employee = $byId[$id] ?? null;
                $row = $rows->get($key);

                if ($employee === null || ($row !== null && $row->is_manual)) {
                    continue;
                }

                if (! $this->employedOn($employee, $date)) {
                    if ($row !== null) {
                        $deletes[] = $row->id;
                    }

                    continue;
                }

                $dayPunches = $punches[$key] ?? [];

                if ($row === null && $dayPunches === [] && ($date > $today || $this->leftWithoutDate($employee))) {
                    continue;
                }

                $shift = (! $currentShift && $row !== null && $row->closed && $row->shift_id !== null)
                    ? ($storedShifts->get($row->shift_id) ?? $this->shiftOf($employee, $default))
                    : $this->shiftOf($employee, $default);

                $closed = $date < $today;

                $result = $this->calculator->calculate(new DayInput(
                    date: $date,
                    shiftStart: $shift->start_time,
                    shiftEnd: $shift->end_time,
                    graceMinutes: (int) $shift->grace_minutes,
                    halfDayHours: (float) $shift->half_day_hours,
                    weeklyOffs: array_values((array) $shift->weekly_offs),
                    isHoliday: isset($holidays[$date]),
                    isExempt: (bool) $employee->attendance_exempt,
                    punches: $dayPunches,
                    closed: $closed,
                    overtimeMinMinutes: $overtimeMin,
                    onLeave: false, // the Leave phase supplies approved leave
                ));

                if ($result === null) {
                    if ($row !== null) {
                        $deletes[] = $row->id;
                    }

                    continue;
                }

                $values = [
                    'shift_id' => $shift->id,
                    'clock_in' => $result->clockIn,
                    'clock_out' => $result->clockOut,
                    'worked_minutes' => $result->workedMinutes,
                    'late_minutes' => $result->lateMinutes,
                    'early_minutes' => $result->earlyMinutes,
                    'overtime_minutes' => $result->overtimeMinutes,
                    'status' => $result->status,
                    'needs_attention' => $result->needsAttention,
                    'needs_rebuild' => false,
                    'closed' => $closed,
                ];

                if ($row !== null) {
                    $updates[$row->id] = $values;
                } else {
                    $inserts[] = ['employee_id' => $id, 'date' => $date, 'is_manual' => false, ...$values];
                }
            }

            $stamp = (new Attendance)->freshTimestampString();

            foreach (array_chunk($inserts, 200) as $chunk) { // 200 x 16 columns stays under every driver's bindings limit
                // A row created meanwhile (by punch ingestion) wins; it is marked for rebuild and decided next round.
                Attendance::query()->insertOrIgnore(array_map(
                    fn (array $r) => [...$r, 'created_at' => $stamp, 'updated_at' => $stamp],
                    $chunk,
                ));
            }

            foreach ($updates as $rowId => $values) {
                Attendance::query()->whereKey($rowId)->where('is_manual', false)->update($values);
            }

            if ($deletes !== []) {
                Attendance::query()->whereIn('id', $deletes)->where('is_manual', false)->delete();
            }

            return count($inserts) + count($updates) + count($deletes);
        });
    }

    private function shiftOf(Employee $employee, Shift $default): Shift
    {
        return $employee->shift_id !== null && $employee->shift !== null ? $employee->shift : $default;
    }

    private function employedOn(Employee $employee, string $date): bool
    {
        $joined = $employee->joining_date->format('Y-m-d');
        $last = $employee->last_working_day?->format('Y-m-d');

        return $date >= $joined && ($last === null || $date <= $last);
    }

    private function leftWithoutDate(Employee $employee): bool
    {
        return in_array($employee->status, self::LEAVING_STATUSES, true) && $employee->last_working_day === null;
    }

    private function rawDate(Attendance $row): string
    {
        return substr((string) ($row->getAttributes()['date'] ?? ''), 0, 10);
    }

    private function date(string $date): string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));

        if ($parsed === false || $parsed->format('Y-m-d') !== trim($date)) {
            throw new InvalidArgumentException("Not a date [{$date}].");
        }

        return $parsed->format('Y-m-d');
    }

    private function nextDay(string $date): string
    {
        return (new \DateTimeImmutable($date.' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    }
}
