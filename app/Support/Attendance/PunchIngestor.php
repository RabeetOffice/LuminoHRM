<?php

namespace App\Support\Attendance;

use App\Models\Attendance;
use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Stores the punch lines of one ATTLOG upload and marks the affected employee-days for a rebuild. It never computes
 * attendance (AttendanceRebuilder does that from the needs_rebuild marker).
 *
 * ingest() runs in ONE transaction: either every acceptable line is in device_punches (and its day marked, and the
 * device's heartbeat columns written) or nothing is, and the exception reaches the caller, which must not reply "OK".
 * A punch already stored (same device, machine user ID and time) is a duplicate: not an error, and acknowledged.
 *
 * The device row is locked first, so two uploads from the same machine run one after the other.
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
        $parsed = self::parse($body);
        $now = $this->clock->now();

        $faults = [];
        $punches = []; // "pin\ttime" => row, so a line repeated within the body is stored once

        foreach ($parsed['rows'] as $row) {
            if (self::isClockFault($row['punched_at'], $now)) {
                $faults[] = "{$row['pin']} {$row['punched_at']}";

                continue;
            }

            $punches[$row['pin']."\t".$row['punched_at']] ??= $row;
        }

        $valid = count($parsed['rows']) - count($faults);

        $stored = DB::transaction(function () use ($device, $punches, $stamp): int {
            $locked = Device::query()->whereKey($device->getKey())->lockForUpdate()->firstOrFail();

            $employees = $this->employeeIds(array_unique(array_column($punches, 'pin')));
            $createdAt = Date::now()->format('Y-m-d H:i:s');
            $records = [];
            $days = [];

            foreach ($punches as $row) {
                $employeeId = $employees[$row['pin']] ?? null;
                $date = substr($row['punched_at'], 0, 10);

                $records[] = [
                    'device_id' => $locked->id,
                    'device_user_id' => $row['pin'],
                    'employee_id' => $employeeId,
                    'punch_time' => $row['punched_at'],
                    'punch_date' => $date,
                    'verify_type' => $row['verify'] !== '' ? $row['verify'] : null,
                    'status_code' => $row['status'] !== '' ? $row['status'] : null,
                    'source' => 'device',
                    'created_at' => $createdAt,
                ];

                if ($employeeId !== null) {
                    $days[$employeeId][$date] = true;
                }
            }

            $stored = 0;

            foreach (array_chunk($records, self::INSERT_CHUNK) as $chunk) {
                $stored += DevicePunch::query()->insertOrIgnore($chunk);
            }

            foreach ($days as $employeeId => $dates) {
                $this->markDays($employeeId, array_keys($dates));
            }

            $this->touchDevice($locked, $punches, $stamp);

            return $stored;
        });

        return new IngestResult(
            stored: $stored,
            duplicates: $valid - $stored,
            skipped: $parsed['malformed'] + count($faults),
            clockFaults: $faults,
        );
    }

    /**
     * After an employee's machine user ID was set or changed: punches stored under the old ID that were linked to this
     * employee are detached, unmatched punches under the new ID are attached, and every day either touched is marked
     * for a rebuild. Punches linked to some other employee are never taken. Call inside the saving transaction.
     */
    public function relink(Employee $employee, ?string $oldId): void
    {
        $newId = $employee->device_user_id;
        $newId = $newId !== null && $newId !== '' ? $newId : null;
        $oldId = $oldId !== null && $oldId !== '' ? $oldId : null;

        if ($oldId === $newId) {
            return;
        }

        DB::transaction(function () use ($employee, $oldId, $newId): void {
            $dates = [];

            if ($oldId !== null) {
                $detach = DevicePunch::query()->where('employee_id', $employee->id)->where('device_user_id', $oldId);
                $dates = $detach->clone()->distinct()->pluck('punch_date')->all();
                $detach->update(['employee_id' => null]);
            }

            if ($newId !== null) {
                $attach = DevicePunch::query()->whereNull('employee_id')->where('device_user_id', $newId);
                $dates = [...$dates, ...$attach->clone()->distinct()->pluck('punch_date')->all()];
                $attach->update(['employee_id' => $employee->id]);
            }

            $this->markDays($employee->id, array_values(array_unique(array_map(
                fn (mixed $date) => substr((string) $date, 0, 10),
                $dates,
            ))));
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

        foreach (preg_split('/\r\n|\n|\r/', $body) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            $fields = explode("\t", $line);
            $pin = trim($fields[0]);
            $time = trim($fields[1] ?? '');

            if ($pin === '' || $time === '' || strlen($pin) > self::PIN_MAX || ! mb_check_encoding($pin, 'UTF-8')
                || ! self::isDateTime($time)) {
                $malformed++;

                continue;
            }

            $rows[] = [
                'pin' => $pin,
                'punched_at' => $time,
                'status' => self::code($fields[2] ?? ''),
                'verify' => self::code($fields[3] ?? ''),
            ];
        }

        return ['rows' => $rows, 'malformed' => $malformed];
    }

    /** A machine clock fault: more than 24 hours ahead of $now (company wall clock) or before 2000-01-01. */
    public static function isClockFault(string $punchedAt, CarbonImmutable $now): bool
    {
        // Both sides are "Y-m-d H:i:s" wall-clock strings, which compare correctly as text.
        return $punchedAt < self::EARLIEST || $punchedAt > $now->addDay()->format('Y-m-d H:i:s');
    }

    /**
     * Marks each (employee, date) for a rebuild: a bare row is created when the day has none; an existing row only gets
     * needs_rebuild = true, and a manual day is not touched at all (a rebuild never overwrites it anyway).
     *
     * @param  list<string>  $dates  "Y-m-d"
     */
    private function markDays(int $employeeId, array $dates): void
    {
        if ($dates === []) {
            return;
        }

        $now = Date::now()->format('Y-m-d H:i:s');

        // Insert-or-ignore: a row created meanwhile by another request (or the rebuild) is left as it is.
        Attendance::query()->insertOrIgnore(array_map(fn (string $date) => [
            'employee_id' => $employeeId,
            'date' => $date,
            // The column needs a value; the rebuild replaces it within a minute.
            'status' => 'present',
            'needs_rebuild' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $dates));

        Attendance::query()
            ->where('employee_id', $employeeId)
            ->whereIn('date', $dates)
            ->where('is_manual', false)
            ->where('needs_rebuild', false)
            ->update(['needs_rebuild' => true, 'updated_at' => $now]);
    }

    /**
     * last_seen_at (app time), last_punch_at (the newest punch time ever received, naive) and last_stamp.
     *
     * @param  array<string, array{pin: string, punched_at: string, status: string, verify: string}>  $punches
     */
    private function touchDevice(Device $device, array $punches, ?string $stamp): void
    {
        $newest = $punches === [] ? null : max(array_column($punches, 'punched_at'));
        $current = $device->getRawOriginal('last_punch_at');
        $current = is_string($current) && $current !== '' ? substr($current, 0, 19) : null;

        $values = ['last_seen_at' => Date::now()];

        if ($newest !== null && ($current === null || $newest > $current)) {
            $values['last_punch_at'] = $newest;
        }

        $stamp = $stamp !== null ? trim($stamp) : '';

        if ($stamp !== '' && mb_check_encoding($stamp, 'UTF-8')) {
            $values['last_stamp'] = mb_substr($stamp, 0, 50);
        }

        $device->forceFill($values)->save();
    }

    /**
     * Employee id by machine user ID for the given PINs.
     *
     * @param  array<int|string, string>  $pins
     * @return array<string, int>
     */
    private function employeeIds(array $pins): array
    {
        $map = [];

        foreach (array_chunk(array_values($pins), self::INSERT_CHUNK) as $chunk) {
            foreach (Employee::query()->whereIn('device_user_id', $chunk)->get(['id', 'device_user_id']) as $employee) {
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
