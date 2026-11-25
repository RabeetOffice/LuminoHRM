<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;
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
 *     One exception: the nightly close finalises a manual row that is still open (corrected while the day was open)
 *     from its own stored times and snapshot, or only marks it closed when its status was an override.
 *   - Attendance start (settings('attendance.start_date'), "Y-m-d" or null): no day WITHOUT punches (absent / weekend /
 *     holiday / exempt present) is recorded before it, or at all while it is null: an existing non-manual punch-less
 *     row there is removed. Days WITH punches are decided and kept whatever the start date (also before it, or when it
 *     is in the future): they are days before attendance officially started, and screens / totals / payroll count from
 *     the start date. So moving the start date never deletes a day that has punches.
 *   - No row outside the employment window (before the joining date or after the last working day): a non-manual row
 *     there is deleted.
 *   - A day with no punches is recorded for an employee whose status is inactive, resigned or terminated only when a
 *     last working day is set (the date is then inside it); an existing row is kept up to date, a missing one is not
 *     created. Nor is such a row created for a date after today.
 *   - The shift rules: every decided row stores the rules it was decided with in shift_rules (a JSON snapshot of the
 *     shift's start, end, grace, half-day hours and weekly offs, plus the overtime minimum, the double-tap window and
 *     the employee's attendance-exempt flag at the time). A row that is already closed is decided again with that
 *     snapshot (falling back to its shift_id's current values, then the employee's shift; a snapshot written before the
 *     last three keys existed takes their current values), so editing a shift or a setting never silently changes past
 *     days; the explicit range rebuild applies the rules in force now and refreshes the snapshot. Open rows always use
 *     the employee's shift and the settings in force (Shift::forEmployee semantics).
 *   - closed = the date is before AttendanceClock::attendanceToday() (the attendance day of now under the day cutoff:
 *     at 00:30 with a 04:00 cutoff yesterday is still open, its late clock-out may still come).
 *   - status null = pending calculation (a bare row made by punch ingestion). A pending row is always decided (or
 *     removed); the close never leaves one behind except a day that cannot be decided, which is flagged.
 *   - One employee-day that cannot be decided (bad data) never blocks the others: it is logged (class and ids only),
 *     its row (if any) gets needs_attention = true and needs_rebuild = false inside the same locked transaction (so it
 *     cannot stay at the head of the pending queue, and a punch stored afterwards re-marks it), and the rest of the
 *     batch is written. A database error (QueryException / PDOException) is not bad data: the row gets
 *     needs_attention = true but keeps needs_rebuild = true, so the next minute retries it.
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
        $today = $this->clock->attendanceToday();

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
     * pending (status null) is decided again as a closed day (so a single punch is flagged); a manual row still open
     * (corrected while the day was open) is finalised (see finaliseManual); the day's decided non-manual rows are marked
     * closed, then pending rows are rebuilt. Running it again changes nothing.
     *
     * @return int employee-days written or deleted for $date
     */
    public function closeDay(string $date): int
    {
        $date = $this->date($date);

        if ($date >= $this->clock->attendanceToday()) {
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
            // Before the attendance start date (or while it is unset) no punch-less day is recorded, so only the
            // employees with punches that day are added; their rows are decided and kept (process() keeps punch days
            // before the start date, it removes punch-less automatic rows there).
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
        if ($rows->contains(fn (object $row): bool => (bool) $row->is_manual && ! $row->closed)) {
            $written += $this->finaliseManual($date);
        }

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
     * The manual rows of a closing day that are still open (corrected while the day was open), finalised under a row
     * lock: a row whose status was an explicit override (status_overridden) is only marked closed; any other is decided
     * again from its STORED times (never the punches) with closed-day rules and its own rule snapshot (decideEntered),
     * keeping is_manual, its times and its note, and marked closed. A row that cannot be decided is marked closed and
     * flagged. Returns how many rows were finalised.
     */
    private function finaliseManual(string $date): int
    {
        return DB::transaction(function () use ($date): int {
            $rows = Attendance::query()->where('date', $date)->where('is_manual', true)->where('closed', false)
                ->lockForUpdate()->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $employees = Employee::query()->with('shift')->whereIn('id', $rows->pluck('employee_id')->unique()->all())
                ->get()->keyBy('id');
            $isHoliday = Holiday::query()->toBase()->where('date', $date)->exists();

            foreach ($rows as $row) {
                $employee = $employees->get($row->employee_id);
                $raw = $row->getAttributes();
                $in = isset($raw['clock_in']) ? substr((string) $raw['clock_in'], 0, 19) : null;
                $out = isset($raw['clock_out']) ? substr((string) $raw['clock_out'], 0, 19) : null;

                if ($row->status_overridden || $employee === null || ($in === null && $out === null)) {
                    $row->forceFill(['closed' => true])->save();

                    continue;
                }

                try {
                    $rules = $this->rulesFor($employee, $row);
                    $result = $this->decideEntered($rules, $date, $in, $out, true, $isHoliday);
                } catch (Throwable $e) {
                    $this->logFailed((int) $row->employee_id, $date, $e);
                    $row->forceFill(['closed' => true, 'needs_attention' => true])->save();

                    continue;
                }

                $row->forceFill($result === null ? ['closed' => true] : [
                    'shift_rules' => $rules,
                    'status' => $result->status,
                    'worked_minutes' => $result->workedMinutes,
                    'late_minutes' => $result->lateMinutes,
                    'early_minutes' => $result->earlyMinutes,
                    'overtime_minutes' => $result->overtimeMinutes,
                    'needs_attention' => $result->needsAttention,
                    'closed' => true,
                ])->save();
            }

            return $rows->count();
        });
    }

    /**
     * The full rule snapshot for a manual correction of $row's day (ApplyManualCorrection, finaliseManual): the row's
     * usable snapshot (keys it predates take the current values), otherwise the employee's shift in force and the
     * current settings. The same shape every decided row stores in shift_rules.
     *
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, overtime_min_minutes: int, duplicate_punch_minutes: int, attendance_exempt: bool}
     */
    public function rulesFor(Employee $employee, ?Attendance $row = null): array
    {
        $current = [
            'overtime_min_minutes' => $this->overtimeMinMinutes(),
            'duplicate_punch_minutes' => $this->duplicateMinutes(),
            'attendance_exempt' => (bool) $employee->attendance_exempt,
        ];

        $snapshot = $row !== null ? $this->snapshotOf($row) : null;

        if ($snapshot === null) {
            $rules = $this->rulesOf($this->shiftOf($employee, Shift::default()), $current);

            return [
                ...$rules,
                'overtime_min_minutes' => $current['overtime_min_minutes'],
                'duplicate_punch_minutes' => $current['duplicate_punch_minutes'],
                'attendance_exempt' => $current['attendance_exempt'],
            ];
        }

        return [
            ...$snapshot,
            'overtime_min_minutes' => $snapshot['overtime_min_minutes'] ?? $current['overtime_min_minutes'],
            'duplicate_punch_minutes' => $snapshot['duplicate_punch_minutes'] ?? $current['duplicate_punch_minutes'],
            'attendance_exempt' => $snapshot['attendance_exempt'] ?? $current['attendance_exempt'],
        ];
    }

    /**
     * Decides a day from times a person entered (a manual correction) rather than from punches: the entered values are
     * deliberate and taken literally, so neither machine-tap rule applies: no double-tap window (duplicatePunchMinutes
     * 0) and no "two punches less than an hour apart are one punch" rule (closePairRule false), so 09:00-09:40 is 40
     * minutes worked (a half day once decided with closed-day rules). $closed true applies the closed-day rules (half
     * day, early minutes, single-time flag). Used by ApplyManualCorrection and by the nightly close (finaliseManual).
     *
     * @param  array{start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, overtime_min_minutes: int, attendance_exempt: bool}  $rules  from rulesFor()
     * @param  string|null  $clockIn  naive "Y-m-d H:i:s"
     * @param  string|null  $clockOut  naive "Y-m-d H:i:s" (after midnight: the next calendar day)
     * @param  bool|null  $isHoliday  null: looked up
     */
    public function decideEntered(array $rules, string $date, ?string $clockIn, ?string $clockOut, bool $closed, ?bool $isHoliday = null): ?DayResult
    {
        $date = $this->date($date);

        return $this->calculator->calculate(new DayInput(
            date: $date,
            shiftStart: $rules['start_time'],
            shiftEnd: $rules['end_time'],
            graceMinutes: $rules['grace_minutes'],
            halfDayHours: $rules['half_day_hours'],
            weeklyOffs: $rules['weekly_offs'],
            isHoliday: $isHoliday ?? Holiday::query()->toBase()->where('date', $date)->exists(),
            isExempt: $rules['attendance_exempt'],
            punches: array_values(array_filter([$clockIn, $clockOut])),
            closed: $closed,
            overtimeMinMinutes: $rules['overtime_min_minutes'],
            onLeave: false,
            duplicatePunchMinutes: 0, // entered times are deliberate, never a double tap
            dayCutoff: $this->clock->dayCutoff(),
            closePairRule: false, // nor a close pair: a short day entered by hand is a short day
        ));
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
            // The batch failed as a whole (a database error, or something outside the per-day isolation): one by one.
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
        $today = $this->clock->attendanceToday();
        $start = $this->startDate();
        $duplicateMinutes = $this->duplicateMinutes();
        $overtimeMin = $this->overtimeMinMinutes();
        $cutoff = $this->clock->dayCutoff();

        $failed = [];

        $written = DB::transaction(function () use (
            $unique, $employeeIds, $dates, $today, $start, $duplicateMinutes, $overtimeMin, $cutoff, $currentRules, $employees,
            $isolate, &$failed,
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

            $inserts = [];
            $updates = [];
            $deletes = [];
            $failedRows = [];

            foreach ($unique as $key => [$id, $date]) {
                $employee = $byId[$id] ?? null;
                $row = $rows->get($key);

                if ($employee === null || ($row !== null && $row->is_manual)) {
                    continue;
                }

                try {
                    $dayPunches = $punches[$key] ?? [];

                    if (! $this->employedOn($employee, $date)) {
                        if ($row !== null) {
                            $deletes[] = $row->id;
                        }

                        continue;
                    }

                    // Attendance has not started (no start date, or a date before it): no punch-less day is recorded.
                    // A day with punches is still decided and kept.
                    if ($dayPunches === [] && ($start === null || $date < $start)) {
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

                    $current = [
                        'overtime_min_minutes' => $overtimeMin,
                        'duplicate_punch_minutes' => $duplicateMinutes,
                        'attendance_exempt' => (bool) $employee->attendance_exempt,
                    ];

                    // A snapshot written before these keys existed takes the current values for them.
                    $rules = $snapshot !== null ? [...$snapshot, ...array_filter(
                        $current,
                        fn (string $key): bool => $snapshot[$key] === null,
                        ARRAY_FILTER_USE_KEY,
                    )] : $this->rulesOf(
                        (! $currentRules && $row !== null && $row->closed && $row->shift_id !== null)
                            ? ($storedShifts->get($row->shift_id) ?? $this->shiftOf($employee, $default))
                            : $this->shiftOf($employee, $default),
                        $current,
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
                        isExempt: (bool) $rules['attendance_exempt'],
                        punches: $dayPunches,
                        closed: $closed,
                        overtimeMinMinutes: (int) $rules['overtime_min_minutes'],
                        onLeave: false, // the Leave phase supplies approved leave
                        duplicatePunchMinutes: (int) $rules['duplicate_punch_minutes'],
                        dayCutoff: $cutoff,
                    ));

                    $rulesJson = json_encode($rules, JSON_THROW_ON_ERROR);
                } catch (Throwable $e) {
                    if (! $isolate) {
                        throw $e;
                    }

                    $failed[] = [$id, $date, $e];

                    if ($row !== null) {
                        $failedRows[] = $row->id;
                    }

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
                    'shift_rules' => $rulesJson,
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

            // Days that could not be decided (bad data), flagged under the same row locks: they leave the pending queue,
            // and a punch stored after this commit re-marks them (ingestion sets needs_rebuild where it is false).
            if ($failedRows !== []) {
                Attendance::query()->whereIn('id', $failedRows)->where('is_manual', false)
                    ->update(['needs_attention' => true, 'needs_rebuild' => false]);
            }

            return count($inserts) + count($updates) + count($deletes);
        });

        foreach ($failed as [$id, $date, $e]) {
            $this->logFailed($id, $date, $e);
        }

        return $written;
    }

    /** Logs an employee-day that could not be decided (exception class and ids only, never data). */
    private function logFailed(int $employeeId, string $date, Throwable $e): void
    {
        Log::warning('Attendance day could not be decided', [
            'exception' => $e::class,
            'employee_id' => $employeeId,
            'date' => $date,
        ]);
    }

    /**
     * An employee-day whose processing failed outside the per-day isolation (processSafely's one-by-one retry): logged,
     * and its row, if any and not manual, gets needs_attention = true.
     *   - A database error (QueryException / PDOException) is transient: needs_rebuild stays (is set) true, so the
     *     next minute's rebuild retries it.
     *   - Anything else is treated as bad data: needs_rebuild = false so it cannot block the pending queue, written
     *     under a row lock. HR fixing the cause (for example the shift) marks it for a rebuild again.
     */
    private function flagFailed(int $employeeId, string $date, Throwable $e): void
    {
        $this->logFailed($employeeId, $date, $e);
        $transient = self::isDatabaseError($e);

        try {
            DB::transaction(function () use ($employeeId, $date, $transient): void {
                $row = Attendance::query()->where('employee_id', $employeeId)->where('date', $date)
                    ->where('is_manual', false)->lockForUpdate()->first(['id']);

                $row?->newQuery()->whereKey($row->id)->where('is_manual', false)
                    ->update(['needs_attention' => true, 'needs_rebuild' => $transient]);
            });
        } catch (Throwable $flagError) {
            // The database may still be down: the row keeps its marker and is retried; nothing more can be done here.
            Log::warning('Attendance day could not be flagged', ['exception' => $flagError::class, 'employee_id' => $employeeId, 'date' => $date]);
        }
    }

    private static function isDatabaseError(Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rules a day is decided with, as stored in attendances.shift_rules: the shift's, plus the overtime minimum,
     * the double-tap window and the employee's exempt flag in force when it was decided.
     *
     * @param  array{overtime_min_minutes: int, duplicate_punch_minutes: int, attendance_exempt: bool}  $current
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, overtime_min_minutes: int|null, duplicate_punch_minutes: int|null, attendance_exempt: bool|null}
     */
    private function rulesOf(Shift $shift, array $current): array
    {
        return [
            'shift_id' => (int) $shift->id,
            'name' => (string) $shift->name,
            'start_time' => (string) $shift->start_time,
            'end_time' => (string) $shift->end_time,
            'grace_minutes' => (int) $shift->grace_minutes,
            'half_day_hours' => (float) $shift->half_day_hours,
            'weekly_offs' => $shift->weekly_offs, // a list of day names: the Shift model normalises it on every save
            ...$current,
        ];
    }

    /**
     * The row's stored shift-rule snapshot, or null when it has none or it is unusable. The overtime minimum, double-tap
     * window and exempt flag are null when the snapshot predates them (the caller then uses the current values).
     *
     * @return array{shift_id: int|null, name: string, start_time: string, end_time: string, grace_minutes: int, half_day_hours: float, weekly_offs: list<string>, overtime_min_minutes: int|null, duplicate_punch_minutes: int|null, attendance_exempt: bool|null}|null
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

        $overtime = $rules['overtime_min_minutes'] ?? null;
        $duplicate = $rules['duplicate_punch_minutes'] ?? null;
        $exempt = $rules['attendance_exempt'] ?? null;

        return [
            'shift_id' => is_int($rules['shift_id'] ?? null) ? $rules['shift_id'] : null,
            'name' => is_string($rules['name'] ?? null) ? $rules['name'] : '',
            'start_time' => $rules['start_time'],
            'end_time' => $rules['end_time'],
            'grace_minutes' => $rules['grace_minutes'],
            'half_day_hours' => (float) $rules['half_day_hours'],
            'weekly_offs' => array_values(array_map(fn (mixed $d): string => is_scalar($d) ? (string) $d : '', $rules['weekly_offs'])),
            'overtime_min_minutes' => is_int($overtime) && $overtime >= 0 ? $overtime : null,
            'duplicate_punch_minutes' => is_int($duplicate) && $duplicate >= 0 && $duplicate <= 60 ? $duplicate : null,
            'attendance_exempt' => is_bool($exempt) ? $exempt : null,
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

    private function overtimeMinMinutes(): int
    {
        $value = settings('attendance.overtime_min_minutes');

        return is_int($value) && $value >= 0 ? $value : 0;
    }

    private function shiftOf(Employee $employee, Shift $default): Shift
    {
        return $employee->shift_id !== null && $employee->shift !== null ? $employee->shift : $default;
    }

    /**
     * Inside the employment window: from the joining date to the last working day. (The attendance start date is not
     * part of it: before the start date only punch-less days are left out, see process().)
     */
    private function employedOn(Employee $employee, string $date): bool
    {
        $from = $employee->joining_date->format('Y-m-d');
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
