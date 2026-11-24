<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Turns punches into `attendances` rows: loads each employee-day's inputs, asks DayCalculator, writes the result.
 *
 *   $rebuilder = app(AttendanceRebuilder::class);
 *   $rebuilder->rebuild($employee, '2026-11-24');      // one employee-day (throws if it cannot be decided)
 *   $rebuilder->rebuildPending();                      // rows marked needs_rebuild (by punch ingestion), oldest first
 *   $rebuilder->rebuildRange('2026-11-01', '2026-11-30', [$employee->id]);   // explicit, with the shift rules in force now
 *   $rebuilder->closeDay('2026-11-24');                // the nightly close for one past date
 *
 * Rules kept here (the day rules themselves are in DayCalculator):
 *   - A manual row (is_manual) is never written, deleted or marked: not even its needs_rebuild flag is cleared. Pending
 *     selection leaves manual rows out, so a manual day marked by a new punch waits until the manual flag is cleared.
 *   - Attendance start (settings('attendance.start_date'), "Y-m-d" or null): while it is null attendance has not
 *     started, and no day WITHOUT punches (absent / weekend / holiday / exempt present) is recorded at all: an existing
 *     non-manual punch-less row is removed. Days with punches are still decided. Once set, the employment window starts
 *     at max(joining date, start date).
 *   - No row outside the employment window (before its start or after the last working day): a non-manual row there
 *     is deleted.
 *   - A day with no punches is recorded for an employee whose status is inactive, resigned or terminated only when a
 *     last working day is set (the date is then inside it); an existing row is kept up to date, a missing one is not
 *     created. Nor is such a row created for a date after today.
 *   - The shift rules: every decided row stores the rules it was decided with in shift_rules (a JSON snapshot of the
 *     shift's start, end, grace, half-day hours and weekly offs). A row that is already closed is decided again with
 *     that snapshot (falling back to its shift_id's current values, then the employee's shift), so editing a shift
 *     never silently changes past days; the explicit range rebuild applies the rules in force now and refreshes the
 *     snapshot. Open rows always use the employee's shift in force (Shift::forEmployee semantics).
 *   - closed = the date is before AttendanceClock::today().
 *   - status null = pending calculation (a bare row made by punch ingestion). A pending row is always decided (or
 *     removed); the close never leaves one behind except a day that cannot be decided, which is flagged.
 *   - One employee-day that cannot be decided (bad data) never blocks the others: it is logged (class and ids only),
 *     its row (if any) gets needs_attention = true and needs_rebuild = false (so it cannot stay at the head of the
 *     pending queue), and the rest of the batch is written.
 *
 * Every batch reads its inputs with a fixed number of queries (rows, employees, default shift, stored shifts,
 * holidays, punches) and writes with a fixed number of statements per 200 rows (bulk insert, bulk upsert, one
 * delete), inside one transaction that locks the existing rows first, so a punch stored meanwhile either is seen or
 * re-marks the row after this commit.
 */
class AttendanceRebuilder
{
    /** Employee statuses for which no new no-punch day is created unless a last working day is set. */
    private const LEAVING_STATUSES = ['inactive', 'resigned', 'terminated'];

    /** Default of settings('attendance.duplicate_punch_minutes') when the stored value is unusable. */
    private const DEFAULT_DUPLICATE_MINUTES = 5;

    private const CHUNK = 200; // 200 rows x 17 columns stays under every driver's bindings limit

    /** The columns a decided day writes (besides employee_id, date and is_manual). */
    private const DECIDED = [
        'shift_id', 'shift_rules', 'clock_in', 'clock_out', 'worked_minutes', 'late_minutes', 'early_minutes',
        'overtime_minutes', 'status', 'needs_attention', 'needs_rebuild', 'closed',
    ];

    public function __construct(
        private readonly DayCalculator $calculator,
        private readonly AttendanceClock $clock,
    ) {}

    /** Rebuilds one employee-day ("Y-m-d"). A manual row is left exactly as it is. */
    public function rebuild(Employee $e, string $date): void
    {
        $this->process([[(int) $e->id, $this->date($date)]], false);
    }

    /**
     * Rebuilds up to $limit non-manual rows marked needs_rebuild, oldest date first. Returns how many were handled.
     * A row that cannot be decided is flagged needs_attention and unmarked, so it never starves the queue.
     */
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

        return $this->processSafely($pairs, false);
    }

    /**
     * Explicit rebuild of every day from $from to $to (inclusive, "Y-m-d", not after today) for the given employees (all
     * when null), with the shift rules in force now, also for days already closed (their snapshot is refreshed).
     * Manual rows are left alone.
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

            $written += $this->processSafely($pairs, true, $employees);
        }

        return $written;
    }

    /**
     * The nightly close of one past date: once attendance has started (settings('attendance.start_date') set and not
     * after $date), every employee who should have a record that day and has none gets one (absent, weekend, holiday,
     * or present when exempt; decided from their punches if they have any); every non-manual row still open or still
     * pending (status null) is decided again as a closed day (so a single punch is flagged); the day's decided
     * non-manual rows are marked closed, then pending rows are rebuilt. Running it again changes nothing.
     *
     * @return int employee-days written or deleted for $date
     */
    public function closeDay(string $date): int
    {
        $date = $this->date($date);

        if ($date >= $this->clock->today()->format('Y-m-d')) {
            throw new InvalidArgumentException("Only a past day can be closed [{$date}].");
        }

        $start = $this->startDate();
        $createMissing = $start !== null && $date >= $start;

        $employees = ! $createMissing ? null : Employee::query()->with('shift')
            ->where('joining_date', '<', $this->nextDay($date))
            ->where(fn ($q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $date))
            ->get()
            ->keyBy('id');

        $rows = Attendance::query()->toBase()->where('date', $date)
            ->get(['employee_id', 'is_manual', 'closed', 'status'])
            ->keyBy('employee_id');

        $pairs = [];

        foreach ($employees ?? [] as $id => $employee) {
            if (! $rows->has($id)) {
                $pairs[(int) $id] = [(int) $id, $date];
            }
        }

        if (! $createMissing) {
            // Attendance not started (or not yet on this date): only days with punches are decided.
            $punched = DevicePunch::query()->toBase()->where('punch_date', $date)->whereNotNull('employee_id')
                ->distinct()->pluck('employee_id');

            foreach ($punched as $id) {
                if (! $rows->has($id)) {
                    $pairs[(int) $id] = [(int) $id, $date];
                }
            }
        }

        foreach ($rows as $id => $row) {
            if (! $row->is_manual && (! $row->closed || $row->status === null)) {
                $pairs[(int) $id] = [(int) $id, $date];
            }
        }

        $written = $this->processSafely(array_values($pairs), false, $employees);

        // Safety net: anything decided, non-manual and still open on that day (normally nothing left) is marked closed.
        // A row still pending here could not be decided: it stays open (the next close tries again) and is flagged.
        Attendance::query()->where('date', $date)->where('closed', false)->where('is_manual', false)
            ->whereNotNull('status')->update(['closed' => true]);
        Attendance::query()->where('date', $date)->where('is_manual', false)->whereNull('status')
            ->where('needs_attention', false)->update(['needs_attention' => true]);

        $this->rebuildPending();

        return $written;
    }

    /**
     * process(), but a batch that fails as a whole is retried one employee-day at a time, and an employee-day that
     * still fails is flagged (see flagFailed) instead of stopping the others.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs
     * @param  Collection<int|string, Employee>|null  $employees
     */
    private function processSafely(array $pairs, bool $currentRules, ?Collection $employees = null): int
    {
        try {
            return $this->process($pairs, $currentRules, $employees, true);
        } catch (Throwable) {
            $written = 0;

            foreach ($pairs as $pair) {
                try {
                    $written += $this->process([$pair], $currentRules, $employees, true);
                } catch (Throwable $e) {
                    $this->flagFailed($pair[0], $pair[1], $e);
                }
            }

            return $written;
        }
    }

    /**
     * Decides and writes the given employee-days in one transaction with a fixed number of reads.
     *
     * @param  array<int, array{0: int, 1: string}>  $pairs  [employee id, "Y-m-d"]
     * @param  bool  $currentRules  true: the explicit range rebuild (closed days too take the rules in force now)
     * @param  Collection<int|string, Employee>|null  $employees  already loaded (with shift), keyed by id
     * @param  bool  $isolate  true: an employee-day whose calculation throws is flagged and left out, the rest is written
     * @return int rows written or deleted
     */
    private function process(array $pairs, bool $currentRules, ?Collection $employees = null, bool $isolate = false): int
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
        $start = $this->startDate();
        $duplicateMinutes = $this->duplicateMinutes();
        $cutoff = $this->clock->dayCutoff();

        $failed = [];

        $written = DB::transaction(function () use (
            $unique, $employeeIds, $dates, $today, $start, $duplicateMinutes, $cutoff, $currentRules, $employees, $isolate, &$failed,
        ): int {
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

            // Closed rows without a snapshot fall back to their shift_id's current values.
            $storedShiftIds = $currentRules ? [] : $rows
                ->filter(fn (Attendance $row) => $row->closed && ! $row->is_manual && $row->shift_id !== null
                    && $this->snapshotOf($row) === null)
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

                try {
                    $dayPunches = $punches[$key] ?? [];

                    if (! $this->employedOn($employee, $date, $start)) {
                        if ($row !== null) {
                            $deletes[] = $row->id;
                        }

                        continue;
                    }

                    // Attendance has not started: no punch-less day is recorded.
                    if ($dayPunches === [] && $start === null) {
                        if ($row !== null) {
                            $deletes[] = $row->id;
                        }

                        continue;
                    }

                    if ($row === null && $dayPunches === [] && ($date > $today || $this->leftWithoutDate($employee))) {
                        continue;
                    }

                    $snapshot = null;

                    if (! $currentRules && $row !== null && $row->closed) {
                        $snapshot = $this->snapshotOf($row);
                    }

                    $rules = $snapshot ?? $this->rulesOf(
                        (! $currentRules && $row !== null && $row->closed && $row->shift_id !== null)
                            ? ($storedShifts->get($row->shift_id) ?? $this->shiftOf($employee, $default))
                            : $this->shiftOf($employee, $default),
                    );

                    $closed = $date < $today;

                    $result = $this->calculator->calculate(new DayInput(
                        date: $date,
                        shiftStart: $rules['start_time'],
                        shiftEnd: $rules['end_time'],
                        graceMinutes: $rules['grace_minutes'],
                        halfDayHours: $rules['half_day_hours'],
                        weeklyOffs: $rules['weekly_offs'],
                        isHoliday: isset($holidays[$date]),
                        isExempt: (bool) $employee->attendance_exempt,
                        punches: $dayPunches,
                        closed: $closed,
                        overtimeMinMinutes: $overtimeMin,
                        onLeave: false, // the Leave phase supplies approved leave
                        duplicatePunchMinutes: $duplicateMinutes,
                        dayCutoff: $cutoff,
                    ));
                } catch (Throwable $e) {
                    if (! $isolate) {
                        throw $e;
                    }

                    $failed[] = [$id, $date, $e];

                    continue;
                }

                if ($result === null) {
                    if ($row !== null) {
                        $deletes[] = $row->id;
                    }

                    continue;
                }

                $values = [
                    // A snapshot keeps the row's own shift_id (the shift itself may have been deleted since).
                    'shift_id' => $snapshot !== null ? $row?->shift_id : $rules['shift_id'],
                    'shift_rules' => json_encode($rules, JSON_THROW_ON_ERROR),
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

                $record = ['employee_id' => $id, 'date' => $date, 'is_manual' => false, ...$values];

                if ($row !== null) {
                    $updates[] = $record;
                } else {
                    $inserts[] = $record;
                }
            }

            $stamp = (new Attendance)->freshTimestampString();

            foreach (array_chunk($inserts, self::CHUNK) as $chunk) {
                // A row created meanwhile (by punch ingestion) wins; it is marked for rebuild and decided next round.
                Attendance::query()->insertOrIgnore(array_map(
                    fn (array $r) => [...$r, 'created_at' => $stamp, 'updated_at' => $stamp],
                    $chunk,
                ));
            }

            // The rows are locked and known not to be manual: one statement per chunk updates them by (employee, date).
            foreach (array_chunk($updates, self::CHUNK) as $chunk) {
                Attendance::query()->upsert($chunk, ['employee_id', 'date'], self::DECIDED);
            }

            if ($deletes !== []) {
                Attendance::query()->whereIn('id', $deletes)->where('is_manual', false)->delete();
            }

            return count($inserts) + count($updates) + count($deletes);
        });

        foreach ($failed as [$id, $date, $e]) {
            $this->flagFailed($id, $date, $e);
        }

        return $written;
    }

    /**
     * An employee-day that could not be decided: logged (exception class and ids only, never data), and its row, if
     * any and not manual, gets needs_attention = true and needs_rebuild = false so it cannot block the pending queue.
     * HR fixing the cause (for example the shift) marks it for a rebuild again.
     */
    private function flagFailed(int $employeeId, string $date, Throwable $e): void
    {
        Log::warning('Attendance day could not be decided', [
            'exception' => $e::class,
            'employee_id' => $employeeId,
            'date' => $date,
        ]);

        Attendance::query()->where('employee_id', $employeeId)->where('date', $date)->where('is_manual', false)
            ->update(['needs_attention' => true, 'needs_rebuild' => false]);
    }

    /**
     * The shift rules a day is decided with, as stored in attendances.shift_rules.
     *
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>}
     */
    private function rulesOf(Shift $shift): array
    {
        return [
            'shift_id' => (int) $shift->id,
            'name' => (string) $shift->name,
            'start_time' => (string) $shift->start_time,
            'end_time' => (string) $shift->end_time,
            'grace_minutes' => (int) $shift->grace_minutes,
            'half_day_hours' => (float) $shift->half_day_hours,
            'weekly_offs' => $shift->weekly_offs, // a list of day names: the Shift model normalises it on every save
        ];
    }

    /**
     * The row's stored shift-rule snapshot, or null when it has none or it is unusable.
     *
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>}|null
     */
    private function snapshotOf(Attendance $row): ?array
    {
        $raw = $row->getAttributes()['shift_rules'] ?? null;
        $rules = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);

        if (! is_array($rules)
            || ! is_string($rules['start_time'] ?? null) || ! is_string($rules['end_time'] ?? null)
            || ! is_int($rules['grace_minutes'] ?? null) || ! is_numeric($rules['half_day_hours'] ?? null)
            || ! is_array($rules['weekly_offs'] ?? null)) {
            return null;
        }

        return [
            'shift_id' => is_int($rules['shift_id'] ?? null) ? $rules['shift_id'] : null,
            'name' => is_string($rules['name'] ?? null) ? $rules['name'] : '',
            'start_time' => $rules['start_time'],
            'end_time' => $rules['end_time'],
            'grace_minutes' => $rules['grace_minutes'],
            'half_day_hours' => (float) $rules['half_day_hours'],
            'weekly_offs' => array_values(array_map(fn (mixed $d): string => is_scalar($d) ? (string) $d : '', $rules['weekly_offs'])),
        ];
    }

    /** settings('attendance.start_date') as "Y-m-d", or null (attendance not started / unusable value). */
    private function startDate(): ?string
    {
        $value = settings('attendance.start_date');

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return $this->date($value);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function duplicateMinutes(): int
    {
        $value = settings('attendance.duplicate_punch_minutes');

        return is_int($value) && $value >= 0 && $value <= 60 ? $value : self::DEFAULT_DUPLICATE_MINUTES;
    }

    private function shiftOf(Employee $employee, Shift $default): Shift
    {
        return $employee->shift_id !== null && $employee->shift !== null ? $employee->shift : $default;
    }

    /** Inside the employment window: from max(joining date, attendance start date) to the last working day. */
    private function employedOn(Employee $employee, string $date, ?string $start): bool
    {
        $from = $employee->joining_date->format('Y-m-d');
        $from = $start !== null && $start > $from ? $start : $from;
        $last = $employee->last_working_day?->format('Y-m-d');

        return $date >= $from && ($last === null || $date <= $last);
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
