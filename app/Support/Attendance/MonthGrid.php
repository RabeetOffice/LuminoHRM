<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use DateTimeImmutable;
use DateTimeZone;

/**
 * One calendar month of attendance as a compact grid: the monthly screen (MonthlyController) and the Excel export
 * (MonthlyAttendanceExport) both read it, so the screen and the file always agree.
 *
 *   $grid = MonthGrid::for('2026-11', app(AttendanceClock::class));   // null for anything that is not "YYYY-MM"
 *   $grid->isFuture();                    // the month has not started yet (refused by the screens)
 *   $grid->days();                        // one entry per day: number, date, weekday, future, holiday name
 *   $grid->rows($employees);              // per employee id: cells, totals, employment window
 *
 * A cell is null (nothing to show: no record, outside the employment window, or a day still to come) or a list
 * [code, flags, in, out, worked, late, early, overtime]:
 *   - code: P present, L late, H half day, A absent, V on leave, O holiday, W weekend, "-" pending calculation
 *     (attendances.status null);
 *   - flags: FLAG_MANUAL | FLAG_EARLY | FLAG_OVERTIME | FLAG_ATTENTION;
 *   - in / out: "H:i" wall-clock times as recorded (or null); worked, late, early, overtime: minutes.
 *
 * The employment window of a person in this month is from max(joining date, settings('attendance.start_date') when
 * set) to the earliest of the last working day and the current attendance day (AttendanceClock::attendanceToday()).
 * Days outside it are blank whatever is stored.
 *
 * Totals count only days inside the window with a decided status:
 *   present-equivalent = present + late + 0.5 x half day
 *   working days       = present + late + half day + absent      (on leave, holiday, weekend and pending are not)
 *
 * Reads: one query for the month's holidays, one query per 500 employees for their attendance rows; nothing per day
 * or per cell.
 *
 * @phpstan-type Stored array{status: string|null, in: string|null, out: string|null, worked: int, late: int, early: int, overtime: int, manual: bool, attention: bool}
 */
final class MonthGrid
{
    public const CODES = [
        'present' => 'P',
        'late' => 'L',
        'half_day' => 'H',
        'absent' => 'A',
        'on_leave' => 'V',
        'holiday' => 'O',
        'weekend' => 'W',
    ];

    public const PENDING = '-';

    public const FLAG_MANUAL = 1;

    public const FLAG_EARLY = 2;

    public const FLAG_OVERTIME = 4;

    public const FLAG_ATTENTION = 8;

    private const CHUNK = 500;

    /** @var array<string, string>|null date => holiday name */
    private ?array $holidays = null;

    private function __construct(
        public readonly string $month,
        public readonly string $first,
        public readonly string $last,
        public readonly int $dayCount,
        public readonly string $today,
        private readonly ?string $startDate,
    ) {}

    /** The grid of a "YYYY-MM" month (years 2000 to 2999), or null when the value is anything else. */
    public static function for(mixed $month, AttendanceClock $clock): ?self
    {
        if (! is_string($month) || preg_match('/^2\d{3}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return null;
        }

        $first = self::date($month.'-01');

        return new self(
            $month,
            $first->format('Y-m-d'),
            $first->modify('last day of this month')->format('Y-m-d'),
            (int) $first->format('t'),
            $clock->attendanceToday(),
            self::startDate(),
        );
    }

    /** The month of the current attendance day ("YYYY-MM"). */
    public static function currentMonth(AttendanceClock $clock): string
    {
        return substr($clock->attendanceToday(), 0, 7);
    }

    /** The month has not started yet. */
    public function isFuture(): bool
    {
        return $this->first > $this->today;
    }

    /** "November 2026". */
    public function label(): string
    {
        return self::date($this->first)->format('F Y');
    }

    /** "YYYY-MM" of the month before / after. */
    public function shift(int $months): string
    {
        return self::date($this->first)->modify(($months >= 0 ? '+' : '').$months.' month')->format('Y-m');
    }

    /**
     * @return list<array{day: int, date: string, weekday: string, future: bool, holiday: string|null}>
     */
    public function days(): array
    {
        $holidays = $this->holidays();
        $days = [];

        for ($d = 1; $d <= $this->dayCount; $d++) {
            $date = sprintf('%s-%02d', $this->month, $d);
            $days[] = [
                'day' => $d,
                'date' => $date,
                'weekday' => self::date($date)->format('D'),
                'future' => $date > $this->today,
                'holiday' => $holidays[$date] ?? null,
            ];
        }

        return $days;
    }

    /**
     * The grid rows of these employees (each needs id, joining_date and last_working_day loaded), keyed by employee id.
     *
     * @param  iterable<Employee>  $employees
     * @return array<int, array{window: array{0: int, 1: int}|null, cells: list<list<int|string|null>|null>, totals: array<string, int|float>}>
     */
    public function rows(iterable $employees): array
    {
        $byId = [];
        foreach ($employees as $employee) {
            $byId[(int) $employee->id] = $employee;
        }

        $stored = [];
        foreach (array_chunk(array_keys($byId), self::CHUNK) as $ids) {
            Attendance::query()
                ->whereIn('employee_id', $ids)
                ->betweenDates($this->first, $this->last)
                ->toBase()
                ->get(['employee_id', 'date', 'status', 'clock_in', 'clock_out', 'worked_minutes', 'late_minutes',
                    'early_minutes', 'overtime_minutes', 'is_manual', 'needs_attention'])
                ->each(function (object $raw) use (&$stored): void {
                    $row = (array) $raw;
                    $stored[(int) $row['employee_id']][(int) substr((string) $row['date'], 8, 2)] = self::stored($row);
                });
        }

        $rows = [];
        foreach ($byId as $id => $employee) {
            $rows[$id] = $this->row($employee, $stored[$id] ?? []);
        }

        return $rows;
    }

    /**
     * An empty totals block (every key the screens and the file show).
     *
     * @return array<string, int|float>
     */
    public static function emptyTotals(): array
    {
        return [
            'present' => 0, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'on_leave' => 0, 'off' => 0,
            'worked_minutes' => 0, 'late_minutes' => 0, 'overtime_minutes' => 0,
            'equivalent' => 0, 'working_days' => 0,
        ];
    }

    /**
     * @param  array<int, Stored>  $stored  day number => stored day
     * @return array{window: array{0: int, 1: int}|null, cells: list<list<int|string|null>|null>, totals: array<string, int|float>}
     */
    private function row(Employee $employee, array $stored): array
    {
        $window = $this->window($employee);
        $totals = self::emptyTotals();
        $cells = array_fill(0, $this->dayCount, null);

        if ($window !== null) {
            for ($d = $window[0]; $d <= $window[1]; $d++) {
                $row = $stored[$d] ?? null;

                if ($row === null) {
                    continue;
                }

                $cells[$d - 1] = $this->cell($row);
                $this->count($totals, $row);
            }
        }

        $totals['equivalent'] = $totals['present'] + $totals['late'] + $totals['half_day'] / 2;
        $totals['working_days'] = $totals['present'] + $totals['late'] + $totals['half_day'] + $totals['absent'];

        return ['window' => $window, 'cells' => array_values($cells), 'totals' => $totals];
    }

    /**
     * First and last day number of this month inside the employment window (and not after today), or null.
     *
     * @return array{0: int, 1: int}|null
     */
    private function window(Employee $employee): ?array
    {
        $from = $employee->joining_date->format('Y-m-d');
        if ($this->startDate !== null && $this->startDate > $from) {
            $from = $this->startDate;
        }

        $to = $employee->last_working_day?->format('Y-m-d') ?? $this->last;
        $to = min($to, $this->today, $this->last);
        $from = max($from, $this->first);

        if ($from > $to) {
            return null;
        }

        return [(int) substr($from, 8, 2), (int) substr($to, 8, 2)];
    }

    /**
     * A raw attendance row (toBase) as typed values.
     *
     * @param  array<string, mixed>  $row
     * @return Stored
     */
    private static function stored(array $row): array
    {
        $int = fn (string $key): int => is_numeric($row[$key] ?? null) ? (int) $row[$key] : 0;

        return [
            'status' => is_string($row['status'] ?? null) ? $row['status'] : null,
            'in' => self::time($row['clock_in'] ?? null),
            'out' => self::time($row['clock_out'] ?? null),
            'worked' => $int('worked_minutes'),
            'late' => $int('late_minutes'),
            'early' => $int('early_minutes'),
            'overtime' => $int('overtime_minutes'),
            'manual' => (bool) ($row['is_manual'] ?? false),
            'attention' => (bool) ($row['needs_attention'] ?? false),
        ];
    }

    /**
     * @param  Stored  $row
     * @return list<int|string|null>
     */
    private function cell(array $row): array
    {
        $flags = ($row['manual'] ? self::FLAG_MANUAL : 0)
            | ($row['early'] > 0 ? self::FLAG_EARLY : 0)
            | ($row['overtime'] > 0 ? self::FLAG_OVERTIME : 0)
            | ($row['attention'] ? self::FLAG_ATTENTION : 0);

        return [
            $row['status'] === null ? self::PENDING : (self::CODES[$row['status']] ?? self::PENDING),
            $flags,
            $row['in'],
            $row['out'],
            $row['worked'],
            $row['late'],
            $row['early'],
            $row['overtime'],
        ];
    }

    /**
     * @param  array<string, int|float>  $totals
     * @param  Stored  $row
     */
    private function count(array &$totals, array $row): void
    {
        $status = $row['status'];

        if ($status === null || ! isset(self::CODES[$status])) {
            return; // pending: never counted
        }

        match ($status) {
            'holiday', 'weekend' => $totals['off']++,
            default => $totals[$status]++,
        };

        $totals['worked_minutes'] += $row['worked'];
        $totals['late_minutes'] += $row['late'];
        $totals['overtime_minutes'] += $row['overtime'];
    }

    /** @return array<string, string> */
    private function holidays(): array
    {
        return $this->holidays ??= Holiday::query()
            ->betweenDates($this->first, $this->last)
            ->toBase()
            ->get(['date', 'name'])
            ->mapWithKeys(fn (object $h) => [substr((string) $h->date, 0, 10) => (string) $h->name])
            ->all();
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && strlen($value) >= 16 ? substr($value, 11, 5) : null;
    }

    private static function startDate(): ?string
    {
        $value = settings('attendance.start_date');

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return self::date($value)->format('Y-m-d') === $value ? $value : null;
    }

    private static function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date.' 00:00:00', new DateTimeZone('UTC'));
    }
}
