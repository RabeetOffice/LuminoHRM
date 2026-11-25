<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\EmployeeFiles;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's attendance figures for one group of people: whoever the given employee query (already narrowed by
 * EmployeeAccess::scope) contains. Everything is read for the current attendance day (AttendanceClock::attendanceToday()),
 * which is always the open day, and from a fixed number of queries whatever the number of people or days:
 *
 *   today()   rows of today (1), and once attendance has started: the expected people (1), the shifts (1), today's
 *             holiday (1); the latest punch (1)
 *   late()    the names of today's late people (1, only when someone is late)
 *   trend()   one grouped query over the last 30 closed days
 *
 * Definitions:
 *   present    a row today with status present or late AND a clock-in ("Present Today" = people who clocked in)
 *   late       a row today with status late
 *   not_in     expected today and no row yet ("Not in yet"; the day is open, so nobody is Absent from the punches yet).
 *              Expected = employed today (joined, not past the last working day, not inactive / resigned / terminated
 *              without a last working day), not attendance-exempt, a working day of their shift, not a holiday.
 *              Null while attendance has not started (attendance.start_date unset or after today): nobody can be said
 *              to be missing then.
 *   absent     a row today with status absent (only a manual correction can set it on the open day)
 *   pending    a row today with a null status (punches waiting to be calculated; never counted as present)
 */
final class DashboardAttendance
{
    /** Statuses drawn on the 30-day chart. Pending (null), leave, holidays and weekends are not. */
    public const TREND_STATUSES = ['present', 'late', 'half_day', 'absent'];

    public const TREND_DAYS = 30;

    /** At most this many names on the "Late Arrivals Today" card (the tile has the full count). */
    public const LATE_LIST_LIMIT = 20;

    private const LEAVING_STATUSES = ['inactive', 'resigned', 'terminated'];

    private readonly string $today;

    /**
     * @param  Builder<Employee>  $people  the people in the viewer's scope (EmployeeAccess::scope(Employee::query()))
     * @param  bool  $allPunches  the viewer sees everyone: the latest punch is any punch, even of an unknown machine user
     */
    public function __construct(
        private readonly AttendanceClock $clock,
        private readonly Builder $people,
        private readonly bool $allPunches,
    ) {
        $this->today = $clock->attendanceToday();
    }

    /**
     * @return array{date: string, open: bool, started: bool, start_date: string|null, present: int, late: int, absent: int, pending: int, not_in: int|null, expected: int|null, holiday: bool, last_punch: array{date: string, time: string}|null}
     */
    public function today(): array
    {
        $rows = $this->todayRows();
        $start = self::startDate();
        $started = $start !== null && $start <= $this->today;

        $present = 0;
        $late = 0;
        $absent = 0;
        $pending = 0;

        foreach ($rows as $row) {
            match ($row['status']) {
                null => $pending++,
                'absent' => $absent++,
                default => null,
            };

            if (in_array($row['status'], ['present', 'late'], true) && $row['clock_in'] !== null) {
                $present++;
            }

            if ($row['status'] === 'late') {
                $late++;
            }
        }

        $notIn = null;
        $expected = null;
        $holiday = false;

        if ($started) {
            $holiday = Holiday::query()->onDate($this->today)->exists();
            $expectedIds = $holiday ? [] : $this->expectedIds();
            $expected = count($expectedIds);
            $notIn = count(array_diff($expectedIds, array_keys($rows)));
        }

        return [
            'date' => $this->today,
            // The dashboard always shows the current attendance day, which is open until the next day's cutoff.
            'open' => true,
            'started' => $started,
            'start_date' => $start,
            'present' => $present,
            'late' => $late,
            'absent' => $absent,
            'pending' => $pending,
            'not_in' => $notIn,
            'expected' => $expected,
            'holiday' => $holiday,
            'last_punch' => $this->lastPunch(),
        ];
    }

    /**
     * Today's late people in the scope, earliest clock-in first: name, clock-in ("H:i") and minutes late.
     *
     * @return array{total: int, people: list<array{id: int, name: string, photo_url: string|null, clock_in: string|null, late_minutes: int}>}
     */
    public function late(): array
    {
        $late = array_filter($this->todayRows(), fn (array $row) => $row['status'] === 'late');

        uasort($late, fn (array $a, array $b) => [$a['clock_in'] ?? '99:99', $a['employee_id']] <=> [$b['clock_in'] ?? '99:99', $b['employee_id']]);
        $shown = array_slice($late, 0, self::LATE_LIST_LIMIT, true);

        if ($shown === []) {
            return ['total' => 0, 'people' => []];
        }

        $employees = Employee::query()
            ->whereKey(array_keys($shown))
            ->get(['id', 'first_name', 'last_name', 'photo_path', 'updated_at'])
            ->keyBy('id');

        $people = [];
        foreach ($shown as $id => $row) {
            $employee = $employees->get($id);

            if (! $employee instanceof Employee) {
                continue;
            }

            $people[] = [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'photo_url' => EmployeeFiles::photoUrl($employee),
                'clock_in' => $row['clock_in'],
                'late_minutes' => $row['late_minutes'],
            ];
        }

        return ['total' => count($late), 'people' => $people];
    }

    /**
     * The last 30 closed days (oldest first) and, from one grouped query, how many people had each charted status on
     * each of them. Days before attendance.start_date are listed but never counted.
     *
     * @return array{days: list<string>, counts: list<array{date: string, status: string, count: int}>}
     */
    public function trend(): array
    {
        $todayDate = new DateTimeImmutable($this->today.' 00:00:00', new DateTimeZone('UTC'));
        $days = [];

        for ($i = self::TREND_DAYS; $i >= 1; $i--) {
            $days[] = $todayDate->modify("-{$i} day")->format('Y-m-d');
        }

        $from = $days[0];
        $to = $days[count($days) - 1];
        $start = self::startDate();

        if ($start === null || $start > $to) {
            // Attendance has not started (or starts later): nothing is counted, but the days are still drawn.
            return ['days' => $days, 'counts' => []];
        }

        $from = max($from, $start);

        $grouped = Attendance::query()
            ->betweenDates($from, $to)
            // Closed days only: a past day the close has not reached yet still has people missing who will be absent.
            ->where('closed', true)
            ->whereIn('employee_id', (clone $this->people)->select('employees.id'))
            ->whereIn('status', self::TREND_STATUSES)
            ->toBase()
            ->selectRaw('date, status, COUNT(*) as aggregate')
            ->groupBy('date', 'status')
            ->get()
            ->map(fn (object $row) => [
                'date' => substr((string) $row->date, 0, 10),
                'status' => (string) $row->status,
                'count' => (int) $row->aggregate,
            ])
            ->sortBy([['date', 'asc'], ['status', 'asc']])
            ->all();

        return ['days' => $days, 'counts' => array_values($grouped)];
    }

    /** @var array<int, array{employee_id: int, status: string|null, clock_in: string|null, late_minutes: int}>|null */
    private ?array $rows = null;

    /**
     * Today's rows of the people in scope, keyed by employee id (read once).
     *
     * @return array<int, array{employee_id: int, status: string|null, clock_in: string|null, late_minutes: int}>
     */
    private function todayRows(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        $this->rows = [];

        Attendance::query()
            ->onDate($this->today)
            ->whereIn('employee_id', (clone $this->people)->select('employees.id'))
            ->toBase()
            ->get(['employee_id', 'status', 'clock_in', 'late_minutes'])
            ->each(function (object $row): void {
                $clockIn = is_string($row->clock_in) && strlen($row->clock_in) >= 16 ? substr($row->clock_in, 11, 5) : null;

                $this->rows[(int) $row->employee_id] = [
                    'employee_id' => (int) $row->employee_id,
                    'status' => is_string($row->status) ? $row->status : null,
                    'clock_in' => $clockIn,
                    'late_minutes' => (int) $row->late_minutes,
                ];
            });

        return $this->rows;
    }

    /**
     * Ids of the people expected at work today (see the class comment), from one query for the people and one for
     * the shifts.
     *
     * @return list<int>
     */
    private function expectedIds(): array
    {
        $today = $this->today;

        $people = (clone $this->people)
            ->where('attendance_exempt', false)
            ->where('joining_date', '<=', $today)
            ->where(fn (Builder $w) => $w->whereNull('last_working_day')->orWhere('last_working_day', '>=', $today))
            ->where(fn (Builder $w) => $w->whereNotIn('status', self::LEAVING_STATUSES)->orWhereNotNull('last_working_day'))
            ->toBase()
            ->get(['employees.id', 'employees.shift_id']);

        if ($people->isEmpty()) {
            return [];
        }

        $shifts = Shift::query()->get(['id', 'weekly_offs', 'is_default'])->keyBy('id');
        $default = $shifts->first(fn (Shift $s) => $s->is_default) ?? Shift::default();
        $weekday = strtolower((new DateTimeImmutable($today.' 00:00:00', new DateTimeZone('UTC')))->format('l'));

        $ids = [];
        foreach ($people as $person) {
            $shift = $person->shift_id !== null ? ($shifts->get((int) $person->shift_id) ?? $default) : $default;

            if (! in_array($weekday, (array) $shift->weekly_offs, true)) {
                $ids[] = (int) $person->id;
            }
        }

        return $ids;
    }

    /**
     * The latest stored punch (the machine's wall-clock time, as stored): its calendar date and "H:i", or null when
     * there is none. A viewer who sees everyone gets any punch; others only punches of people in their scope.
     *
     * @return array{date: string, time: string}|null
     */
    private function lastPunch(): ?array
    {
        $query = DevicePunch::query();

        if (! $this->allPunches) {
            $query->whereIn('employee_id', (clone $this->people)->select('employees.id'));
        }

        $latest = $query->toBase()->max('punch_time');

        if (! is_string($latest) || strlen($latest) < 16) {
            return null;
        }

        return ['date' => substr($latest, 0, 10), 'time' => substr($latest, 11, 5)];
    }

    /**
     * Which day the dashboard's "today" figures are for, so the page can say so when it is not the calendar date:
     * after midnight and before the day cutoff the figures are still the previous attendance day's.
     *
     * @return array{date: string, calendar_date: string, differs: bool, cutoff: string}
     */
    public static function day(AttendanceClock $clock): array
    {
        $date = $clock->attendanceToday();
        $calendar = $clock->today()->format('Y-m-d');

        return [
            'date' => $date,
            'calendar_date' => $calendar,
            'differs' => $date !== $calendar,
            'cutoff' => $clock->dayCutoff(),
        ];
    }

    /** settings('attendance.start_date') when it is a real "Y-m-d" date, else null. */
    public static function startDate(): ?string
    {
        $value = settings('attendance.start_date');

        if (! is_string($value)) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? $value : null;
    }
}
