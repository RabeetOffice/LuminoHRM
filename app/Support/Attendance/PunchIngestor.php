<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Support\PayrollLock;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stores the punch lines of one ATTLOG upload and marks the affected employee-days for a rebuild. It never computes
 * attendance (AttendanceRebuilder does that from the needs_rebuild marker).
 *
 * ingest() runs in ONE transaction: either every acceptable line is in device_punches (and its day marked, and the
 * device's heartbeat columns written) or nothing is, and the exception reaches the caller, which must not reply "OK".
 * A punch already stored (same device, machine user ID and time) is a duplicate: not an error, and acknowledged.
 *
 * The device row is locked first, so two uploads from the same machine run one after the other.
 *
 * A day row created here has status null ("pending calculation") and needs_rebuild = true; the rebuild decides it.
 *
 * Payroll lock: a day in a month whose payroll is approved or paid is never created or flagged here (one
 * PayrollLock::lockedMonths read per ingest / relink); its punches are still stored or relinked.
 */
class PunchIngestor
{
    /** Widths of the device_punches columns: a longer PIN is malformed, longer status / verify codes are cut. */
    private const PIN_MAX = 20;

    private const CODE_MAX = 10;

    private const EARLIEST = '2000-01-01 00:00:00';

    private const INSERT_CHUNK = 500;

    public function __construct(private readonly AttendanceClock $clock) {}

    public function ingest(Device $device, string $body, ?string $stamp): IngestResult
    {
        $now = $this->clock->now();
        $limit = $now->addDay()->format('Y-m-d H:i:s');

        // One compact entry per distinct punch: "pin\ttime" => "status\tverify". The insert rows are built from it a
        // chunk at a time, so a large upload never holds a full array of row arrays (I-1: memory per line).
        $punches = [];
        $pins = [];
        $faults = [];
        $valid = 0;
        $malformed = 0;
        $newest = null;

        foreach (self::lines($body) as $row) {
            if ($row === null) {
                $malformed++;

                continue;
            }

            [$pin, $time, $status, $verify] = $row;

            if (self::isClockFault($time, $now, $limit)) {
                $faults[] = "{$pin} {$time}";

                continue;
            }

            $valid++;
            $punches[$pin."\t".$time] ??= $status."\t".$verify;
            $pins[$pin] = true;

            if ($newest === null || $time > $newest) {
                $newest = $time;
            }
        }

        $stored = DB::transaction(function () use ($device, $punches, $pins, $stamp, $newest): int {
            $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            $employees = $this->employeeIds(array_keys($pins));
            $createdAt = Date::now()->format('Y-m-d H:i:s');
            $cutoff = $this->clock->dayCutoff(); // read once for the whole upload
            $days = [];
            $stored = 0;

            foreach (array_chunk($punches, self::INSERT_CHUNK, true) as $chunk) {
                $records = [];

                foreach ($chunk as $key => $codes) {
                    [$pin, $time] = explode("\t", (string) $key, 2);
                    [$status, $verify] = explode("\t", $codes, 2);
                    $employeeId = $employees[$pin] ?? null;
                    // The attendance day: a punch before the day cutoff is the previous day's (AttendanceClock::attendanceDateFor).
                    $date = AttendanceClock::dayOf($time, $cutoff);

                    $records[] = [
                        'device_id' => $locked->id,
                        'device_user_id' => $pin,
                        'employee_id' => $employeeId,
                        'punch_time' => $time,
                        'punch_date' => $date,
                        'verify_type' => $verify !== '' ? $verify : null,
                        'status_code' => $status !== '' ? $status : null,
                        'source' => 'device',
                        'created_at' => $createdAt,
                    ];

                    if ($employeeId !== null) {
                        $days[$employeeId][$date] = true;
                    }
                }

                $stored += DevicePunch::query()->insertOrIgnore($records);
                $this->assertStored((int) $locked->id, $records);
            }

            // Same order as AttendanceRebuilder's locks (employee, then date), so the two cannot deadlock (M-3).
            ksort($days);
            // Days in an approved payroll month get no row and no flag (the punches above are stored all the same).
            $allDates = [];

            foreach ($days as $dates) {
                $allDates += $dates;
            }

            $lockedMonths = self::lockedMonthsOf(array_map('strval', array_keys($allDates)));

            foreach ($days as $employeeId => $dates) {
                $dates = self::withoutLocked(array_map('strval', array_keys($dates)), $lockedMonths);
                sort($dates);
                $this->markDays($employeeId, $dates, true);
            }

            $this->touchDevice($locked, $newest, $stamp);

            return $stored;
        });

        return new IngestResult(
            stored: $stored,
            duplicates: $valid - $stored,
            skipped: $malformed + count($faults),
            clockFaults: $faults,
        );
    }

    /**
     * After an employee's machine user ID was set, changed or cleared. History that is already closed is never
     * rewritten:
     *   - detach (the old ID): this employee's punches under the old ID, only on days whose attendance row is not closed
     *     (no row counts as not closed). Punches on closed days stay with the employee and those days are not flagged.
     *   - attach (the new ID): punches under the new ID that are unlinked (employee_id null) and dated on or after the
     *     joining date. Punches linked to another employee are never taken. Nothing is attached to a leaver without a
     *     last working day (Leavers::withoutLastDay), as at ingestion.
     * Only the days actually changed are flagged for a rebuild, and never a manual day. Call inside the saving
     * transaction.
     */
    public function relink(Employee $employee, ?string $oldId): void
    {
        $newId = $employee->device_user_id;
        $newId = $newId !== null && $newId !== '' ? $newId : null;
        $oldId = $oldId !== null && $oldId !== '' ? $oldId : null;

        if ($oldId === $newId) {
            return;
        }

        $employeeId = (int) $employee->id;
        // employees.joining_date is NOT NULL, so there is always a lower bound.
        $joined = $employee->joining_date->format('Y-m-d');
        $leftWithoutDate = Leavers::withoutLastDay($employee);

        DB::transaction(function () use ($employeeId, $oldId, $newId, $joined, $leftWithoutDate): void {
            $detached = [];
            $attached = [];

            if ($oldId !== null) {
                $detach = DevicePunch::query()
                    ->where('employee_id', $employeeId)
                    ->where('device_user_id', $oldId)
                    ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('attendances')
                        ->where('attendances.employee_id', $employeeId)
                        ->whereColumn('attendances.date', 'device_punches.punch_date')
                        ->where('attendances.closed', true));
                $detached = $this->dates($detach->clone());
                $detach->update(['employee_id' => null]);
            }

            // A leaver without a last working day is given no punches (they stay unknown, as at ingestion).
            if ($newId !== null && ! $leftWithoutDate) {
                $attach = DevicePunch::query()
                    ->whereNull('employee_id')
                    ->where('device_user_id', $newId)
                    ->where('punch_date', '>=', $joined);
                $attached = $this->dates($attach->clone());
                $attach->update(['employee_id' => $employeeId]);
            }

            // Attached days get a row if they have none; a detached day only has its existing row flagged (the
            // employee may have no punch left there, so a pending row is not created for it). Days in an approved
            // payroll month are neither created nor flagged (the punches are relinked all the same).
            $all = array_values(array_unique([...$detached, ...$attached]));
            $all = self::withoutLocked($all, self::lockedMonthsOf($all));
            sort($all);
            $this->markDays($employeeId, array_values(array_intersect($all, $attached)), true);
            $this->markDays($employeeId, array_values(array_diff($all, $attached)), false);
        });
    }

    /**
     * Tab-separated ATTLOG lines: PIN, "Y-m-d H:i:s", status, verify; extra fields ignored. Blank lines are ignored;
     * any other line that cannot be stored (no PIN, a PIN longer than the column or not UTF-8, no or an impossible
     * date-time) is counted as malformed.
     *
     * @return array{rows: list<array{pin: string, punched_at: string, status: string, verify: string}>, malformed: int}
     */
    public static function parse(string $body): array
    {
        $rows = [];
        $malformed = 0;

        foreach (self::lines($body) as $row) {
            if ($row === null) {
                $malformed++;

                continue;
            }

            $rows[] = ['pin' => $row[0], 'punched_at' => $row[1], 'status' => $row[2], 'verify' => $row[3]];
        }

        return ['rows' => $rows, 'malformed' => $malformed];
    }

    /**
     * A machine clock fault: more than 24 hours ahead of $now (company wall clock) or before 2000-01-01.
     *
     * @param  string|null  $limit  $now + 24 h as "Y-m-d H:i:s", when the caller has it already
     */
    public static function isClockFault(string $punchedAt, CarbonImmutable $now, ?string $limit = null): bool
    {
        // Both sides are "Y-m-d H:i:s" wall-clock strings, which compare correctly as text.
        return $punchedAt < self::EARLIEST || $punchedAt > ($limit ?? $now->addDay()->format('Y-m-d H:i:s'));
    }

    /**
     * The body's non-blank lines, one at a time, without splitting the body into an array first: [pin, time, status,
     * verify] for a storable line, null for a malformed one. Lines end in "\n", "\r\n" or "\r".
     *
     * @return Generator<int, array{0: string, 1: string, 2: string, 3: string}|null>
     */
    private static function lines(string $body): Generator
    {
        $length = strlen($body);
        $offset = 0;

        while ($offset < $length) {
            $size = strcspn($body, "\r\n", $offset);
            $line = substr($body, $offset, $size);
            $offset += $size + 1;

            if (trim($line) === '') {
                continue;
            }

            $fields = explode("\t", $line, 5);
            $pin = trim($fields[0]);
            $time = trim($fields[1] ?? '');

            if ($pin === '' || $time === '' || strlen($pin) > self::PIN_MAX || ! mb_check_encoding($pin, 'UTF-8')
                || ! self::isDateTime($time)) {
                yield null;

                continue;
            }

            yield [$pin, $time, self::code($fields[2] ?? ''), self::code($fields[3] ?? '')];
        }
    }

    /**
     * Every row of $records is now in device_punches (inserted now or before). A row the insert-or-ignore skipped for
     * any reason other than the unique key would otherwise be acknowledged and lost: throw instead, so the machine
     * is told to retry (M-2).
     *
     * @param  list<array<string, mixed>>  $records  at most INSERT_CHUNK rows of one device
     */
    private function assertStored(int $deviceId, array $records): void
    {
        // Two IN lists on the unique key's columns (portable, index-served); the few extra rows they can match (another
        // PIN of this chunk at one of its times) are filtered out in PHP.
        $present = [];

        DevicePunch::query()->toBase()
            ->where('device_id', $deviceId)
            ->whereIn('device_user_id', array_values(array_unique(array_column($records, 'device_user_id'))))
            ->whereIn('punch_time', array_values(array_unique(array_column($records, 'punch_time'))))
            ->get(['device_user_id', 'punch_time'])
            ->each(function (object $row) use (&$present): void {
                $present[$row->device_user_id."\t".substr((string) $row->punch_time, 0, 19)] = true;
            });

        $missing = 0;

        foreach ($records as $record) {
            if (! isset($present[$record['device_user_id']."\t".$record['punch_time']])) {
                $missing++;
            }
        }

        if ($missing > 0) {
            throw new RuntimeException("Punches missing after the insert: {$missing} of ".count($records).'.');
        }
    }

    /**
     * Marks each (employee, date) for a rebuild: an existing row gets needs_rebuild = true, a manual day is not touched
     * at all (a rebuild never overwrites it anyway). With $create, a day with no row gets a pending one (status null).
     *
     * @param  list<string>  $dates  "Y-m-d", sorted
     */
    private function markDays(int $employeeId, array $dates, bool $create): void
    {
        if ($dates === []) {
            return;
        }

        $now = Date::now()->format('Y-m-d H:i:s');

        if ($create) {
            // Insert-or-ignore: a row created meanwhile by another request (or the rebuild) is left as it is.
            Attendance::query()->insertOrIgnore(array_map(fn (string $date) => [
                'employee_id' => $employeeId,
                'date' => $date,
                'status' => null, // pending calculation
                'needs_rebuild' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $dates));
        }

        Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $dates)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true, 'updated_at' => $now]);
    }

    /**
     * The locked payroll months among these dates' months: one PayrollLock read (none for no dates). Inside the
     * transaction it share-locks payroll_runs before markDays() touches attendance rows.
     *
     * @param  list<string>  $dates  "Y-m-d"
     * @return array<string, int> locked "Y-m" => index
     */
    private static function lockedMonthsOf(array $dates): array
    {
        $months = array_values(array_unique(array_map(fn (string $d): string => substr($d, 0, 7), $dates)));

        return $months === [] ? [] : array_flip(PayrollLock::lockedMonths($months));
    }

    /**
     * @param  list<string>  $dates  "Y-m-d"
     * @param  array<string, int>  $locked  from lockedMonthsOf()
     * @return list<string>
     */
    private static function withoutLocked(array $dates, array $locked): array
    {
        return $locked === [] ? $dates : array_values(array_filter($dates, fn (string $d): bool => ! isset($locked[substr($d, 0, 7)])));
    }

    /**
     * The distinct "Y-m-d" punch dates of a punch query, sorted.
     *
     * @param  Builder<DevicePunch>  $query
     * @return list<string>
     */
    private function dates(Builder $query): array
    {
        $dates = array_map(fn (mixed $date) => substr((string) $date, 0, 10), $query->distinct()->pluck('punch_date')->all());
        sort($dates);

        return array_values(array_unique($dates));
    }

    /**
     * last_seen_at (app time), last_punch_at (the newest punch time ever received, naive) and last_stamp. Runs under
     * the device row lock, so the forward-only comparisons below cannot race another upload of the same machine.
     */
    private function touchDevice(Device $device, ?string $newest, ?string $stamp): void
    {
        $current = $device->getRawOriginal('last_punch_at');
        $current = is_string($current) && $current !== '' ? substr($current, 0, 19) : null;

        $values = ['last_seen_at' => Date::now()];

        if ($newest !== null && ($current === null || $newest > $current)) {
            $values['last_punch_at'] = $newest;
        }

        if ($stamp !== null && self::isStamp($stamp)) {
            $previous = $device->last_stamp;

            // Forward only. A stored value that is not a valid stamp (older data) is replaced.
            if (! is_string($previous) || ! self::isStamp($previous) || self::compareStamps($stamp, $previous) > 0) {
                $values['last_stamp'] = $stamp;
            }
        }

        $device->forceFill($values)->save();
    }

    /** The machine's ATTLOGStamp: 1 to 20 digits. Anything else is ignored. */
    private static function isStamp(string $value): bool
    {
        return preg_match('/^\d{1,20}$/', $value) === 1;
    }

    /** Numeric comparison of two digit strings of any length (20 digits do not fit in an int). */
    private static function compareStamps(string $a, string $b): int
    {
        $a = ltrim($a, '0');
        $b = ltrim($b, '0');

        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }

    /**
     * Employee id by machine user ID for the given PINs.
     *
     * Read with a shared lock (M-1). On MariaDB (InnoDB, REPEATABLE READ) a plain read is a snapshot: it could miss a
     * relink committed meanwhile, and the punch would be stored unlinked after relink() had already attached the
     * unlinked ones. LOCK IN SHARE MODE reads the latest committed rows and holds next-key locks on the matched
     * device_user_id index entries and gaps, so an employee update giving or taking one of these PINs waits for this
     * upload to commit (and then its relink sees these punches), or this read waits for that update to commit (and
     * then links to the new holder). SQLite runs one writer at a time, so a test cannot show the race.
     *
     * @param  list<string>  $pins
     * @return array<string, int>
     */
    private function employeeIds(array $pins): array
    {
        $map = [];

        foreach (array_chunk($pins, self::INSERT_CHUNK) as $chunk) {
            // A leaver without a last working day is never linked: the punch stays unknown, so HR can give the ID to
            // the person really using it (Raw Punches > Unknown IDs). Same rule as relink().
            $employees = Leavers::exceptWithoutLastDay(Employee::query()->whereIn('device_user_id', $chunk))
                ->sharedLock()->get(['id', 'device_user_id']);

            foreach ($employees as $employee) {
                $map[(string) $employee->device_user_id] = $employee->id;
            }
        }

        return $map;
    }

    private static function isDateTime(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new DateTimeZone('UTC'));

        // Rejects overflow dates such as 2026-02-31 that PHP silently rolls over.
        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $value;
    }

    private static function code(string $value): string
    {
        $value = trim($value);

        return mb_check_encoding($value, 'UTF-8') ? mb_substr($value, 0, self::CODE_MAX) : '';
    }
}
