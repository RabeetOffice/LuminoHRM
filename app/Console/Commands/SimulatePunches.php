<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\PunchIngestor;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * LOCAL TESTING ONLY. Makes up a realistic day of machine punches and sends them through the real /iclock/cdata handler,
 * in-process (routing, device check, PunchIngestor, device_logs), exactly as the MB20 would. Refuses to run unless both
 * the app environment and the configured APP_ENV are local or testing. Sends as the LOCAL-SIMULATOR device unless
 * --serial names another.
 *
 * Each chosen employee who should work that day gets a clock-in around their shift start and a clock-out around its
 * end; --late, --absent and --missing-out are percentages. The same --seed gives the same punches.
 */
class SimulatePunches extends Command
{
    protected $signature = 'hrm:simulate-punches
        {--date= : Day to simulate (Y-m-d); default: the current attendance day}
        {--employees=all : "all", or a comma-separated list of employee ids or codes}
        {--late=10 : Percent of people who arrive late}
        {--absent=5 : Percent of people who do not come}
        {--missing-out=3 : Percent of people who forget to punch out}
        {--serial= : Serial number of an active device to send as; default: the LOCAL-SIMULATOR device (created when missing)}
        {--assign-ids : Give a machine user ID to chosen employees who have none}
        {--seed= : Random seed, for repeatable punches; default: derived from the date}';

    protected $description = 'LOCAL ONLY: send simulated machine punches through the real /iclock endpoint';

    public const SIMULATOR_SERIAL = 'LOCAL-SIMULATOR';

    public const SIMULATOR_NAME = 'Local simulator (not a real machine)';

    /** Punch lines per simulated upload (the endpoint caps the body size). */
    private const LINES_PER_UPLOAD = 200;

    public function handle(AttendanceClock $clock): int
    {
        // Both must agree: the console takes the app environment from argv (--env=local), so the configured APP_ENV is
        // checked as well, and a production server cannot be talked into it.
        $configured = config('app.env');

        if (! app()->environment(['local', 'testing']) || ! in_array($configured, ['local', 'testing'], true)) {
            $this->error('hrm:simulate-punches only runs when APP_ENV is local or testing. Nothing was sent.');

            return self::FAILURE;
        }

        $options = $this->readOptions($clock);

        if (is_string($options)) {
            $this->error($options);

            return self::FAILURE;
        }

        ['date' => $date, 'late' => $late, 'absent' => $absent, 'missingOut' => $missingOut, 'seed' => $seed] = $options;

        $device = $this->device();

        if ($device === null) {
            return self::FAILURE;
        }

        $employees = $this->employees($date);

        if ($employees === null) {
            return self::FAILURE;
        }

        if ($this->option('assign-ids')) {
            $this->assignIds($employees);
        }

        $random = new Randomizer(new Mt19937($seed));
        $now = $clock->now()->format('Y-m-d H:i:s');
        $holiday = Holiday::query()->where('date', $date)->exists();
        $weekday = strtolower((new DateTimeImmutable($date))->format('l'));

        $lines = [];
        $table = [];
        $counts = ['present' => 0, 'late' => 0, 'absent' => 0, 'missing_out' => 0, 'off' => 0, 'skipped' => 0, 'not_yet' => 0];

        foreach ($employees as $employee) {
            // Every employee draws the same number of random values, so one person's outcome never shifts another's.
            $rolls = [
                'absent' => $random->getInt(1, 100),
                'late' => $random->getInt(1, 100),
                'missing' => $random->getInt(1, 100),
                'in' => $random->getInt(0, 1000),
                'out' => $random->getInt(0, 1000),
                'inSec' => $random->getInt(0, 59),
                'outSec' => $random->getInt(0, 59),
            ];

            $label = trim("{$employee->employee_code} {$employee->full_name}");
            $pin = (string) $employee->device_user_id;

            if ($pin === '') {
                $counts['skipped']++;
                $table[] = [$label, '-', 'skipped: no machine user ID (use --assign-ids)', '', ''];

                continue;
            }

            $shift = Shift::forEmployee($employee);

            if ($holiday || in_array($weekday, $shift->weekly_offs, true)) {
                $counts['off']++;
                $table[] = [$label, $pin, $holiday ? 'holiday: no punches' : 'weekly off: no punches', '', ''];

                continue;
            }

            if ($rolls['absent'] <= $absent) {
                $counts['absent']++;
                $table[] = [$label, $pin, 'absent', '', ''];

                continue;
            }

            $start = $this->minutes($shift->start_time);
            $end = $this->minutes($shift->end_time);
            $grace = (int) $shift->grace_minutes;
            $isLate = $rolls['late'] <= $late;

            // Late: 1 to 75 minutes past the grace period. On time: 20 minutes early up to the end of the grace period.
            $in = $isLate
                ? $start + $grace + 1 + intdiv($rolls['in'] * 74, 1000)
                : $start - 20 + intdiv($rolls['in'] * (20 + $grace), 1000);
            // Out: 10 minutes before the shift end up to 45 minutes after it. Shifts cannot span midnight in this version.
            $out = $end - 10 + intdiv($rolls['out'] * 55, 1000);
            $forgetsOut = $rolls['missing'] <= $missingOut;

            $inAt = $this->at($date, $in, $rolls['inSec']);
            $outAt = $forgetsOut ? null : $this->at($date, max($out, $in + 60), $rolls['outSec']);

            if ($inAt > $now) {
                $counts['not_yet']++;
                $table[] = [$label, $pin, 'not in yet (shift starts later today)', '', ''];

                continue;
            }

            $lines[] = "{$pin}\t{$inAt}\t0\t1\t0\t0";
            $outShown = '';

            if ($outAt !== null && $outAt <= $now) {
                $lines[] = "{$pin}\t{$outAt}\t1\t1\t0\t0";
                $outShown = substr($outAt, 11, 5);
            }

            $counts[$isLate ? 'late' : 'present']++;

            if ($forgetsOut) {
                $counts['missing_out']++;
            }

            $table[] = [
                $label,
                $pin,
                ($isLate ? 'late' : 'on time').($forgetsOut ? ', forgets to punch out' : ($outShown === '' ? ', still at work' : '')),
                substr($inAt, 11, 5),
                $outShown,
            ];
        }

        $this->info("Simulating {$date} as device \"{$device->name}\" ({$device->serial_no}), seed {$seed}.");
        $this->table(['Employee', 'Machine ID', 'Outcome', 'In', 'Out'], $table);

        $replies = $this->send($device, $lines);

        $this->line(sprintf(
            'Sent %d punch line(s): %d on time, %d late, %d absent, %d without a clock-out, %d off, %d not yet in, %d skipped.',
            count($lines),
            $counts['present'],
            $counts['late'],
            $counts['absent'],
            $counts['missing_out'],
            $counts['off'],
            $counts['not_yet'],
            $counts['skipped'],
        ));

        foreach ($replies as $reply) {
            $this->line('Endpoint replied: '.trim($reply));
        }

        $failed = array_filter($replies, fn (string $r) => ! str_starts_with($r, 'OK'));

        if ($failed !== []) {
            $this->error('The endpoint did not accept every upload. See device_logs and the Laravel log.');

            return self::FAILURE;
        }

        $this->comment('Punches only mark days. Run "php artisan hrm:attendance-rebuild" to work them out, and "php artisan hrm:attendance-close <date>" for a past day to record absences (use your PHP 8.4 binary for "php", for example C:\php84\php.exe on the Windows dev machine).');

        return self::SUCCESS;
    }

    /**
     * The checked options, or an error message.
     *
     * @return array{date: string, late: int, absent: int, missingOut: int, seed: int}|string
     */
    private function readOptions(AttendanceClock $clock): array|string
    {
        $today = $clock->attendanceToday();
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' && $date !== 'today' ? $date : $today;
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return "--date must be a date like 2026-11-24, not \"{$date}\".";
        }

        if ($date > $today) {
            return "--date cannot be in the future (the current attendance day is {$today}).";
        }

        $percent = [];

        foreach (['late' => 'late', 'absent' => 'absent', 'missingOut' => 'missing-out'] as $key => $name) {
            $value = (string) $this->option($name);

            if (! ctype_digit($value) || (int) $value > 100) {
                return "--{$name} must be a whole percentage from 0 to 100.";
            }

            $percent[$key] = (int) $value;
        }

        $seed = $this->option('seed');

        if ($seed !== null && $seed !== '' && ! preg_match('/^\d{1,9}$/', (string) $seed)) {
            return '--seed must be a whole number.';
        }

        return [
            'date' => $date,
            ...$percent,
            'seed' => $seed !== null && $seed !== '' ? (int) $seed : (int) str_replace('-', '', $date),
        ];
    }

    /** The device to send as: --serial (must be active), else the local simulator device (never a real one silently). */
    private function device(): ?Device
    {
        $serial = strtoupper(trim((string) $this->option('serial')));

        if ($serial !== '') {
            $device = Device::query()->where('serial_no', $serial)->first();

            if ($device === null || ! $device->is_active) {
                $this->error("No active device has serial number \"{$serial}\". Add or activate it in Settings > ZKTeco Device.");

                return null;
            }

            return $device;
        }

        $device = Device::query()->firstOrCreate(
            ['serial_no' => self::SIMULATOR_SERIAL],
            ['name' => self::SIMULATOR_NAME, 'is_active' => true],
        );

        if (! $device->is_active) {
            $this->error('The local simulator device is deactivated. Activate it in Settings > ZKTeco Device, or pass --serial.');

            return null;
        }

        if ($device->wasRecentlyCreated) {
            $this->comment("Created device \"{$device->name}\" ({$device->serial_no}) for the simulation.");
        }

        return $device;
    }

    /**
     * Employees who work on $date: active or on probation, joined by then, limited to --employees.
     *
     * @return array<int, Employee>|null
     */
    private function employees(string $date): ?array
    {
        $query = Employee::query()
            ->whereIn('status', ['active', 'probation'])
            ->where('joining_date', '<=', $date)
            ->orderBy('id');

        $chosen = trim((string) $this->option('employees'));

        if ($chosen !== '' && strtolower($chosen) !== 'all') {
            $keys = array_values(array_filter(array_map('trim', explode(',', $chosen)), fn ($k) => $k !== ''));
            $ids = array_map('intval', array_filter($keys, 'ctype_digit'));
            $codes = array_values(array_filter($keys, fn ($k) => ! ctype_digit($k)));

            $query->where(fn (Builder $q) => $q->whereIn('id', $ids)->orWhereIn('employee_code', $codes));

            $found = (clone $query)->get(['id', 'employee_code']);
            $missing = array_filter($keys, fn ($k) => ! $found->contains(fn ($e) => (string) $e->id === $k || $e->employee_code === $k));

            if ($missing !== []) {
                $this->error('Not found, or not working on '.$date.': '.implode(', ', $missing).'. Nothing was sent.');

                return null;
            }
        }

        return $query->get()->all();
    }

    /**
     * Gives each chosen employee without a machine user ID the next free number (above every ID in use), then relinks
     * any stored punches under that number, as the employee form would.
     *
     * @param  array<int, Employee>  $employees
     */
    private function assignIds(array $employees): void
    {
        $used = Employee::query()->whereNotNull('device_user_id')->pluck('device_user_id')
            ->merge(DevicePunch::query()->distinct()->pluck('device_user_id'))
            ->filter(fn ($id) => ctype_digit((string) $id) && strlen((string) $id) <= 9)
            ->map(fn ($id) => (int) $id);
        $next = max(1000, (int) $used->max()) + 1;

        foreach ($employees as $employee) {
            if ($employee->device_user_id !== null && $employee->device_user_id !== '') {
                continue;
            }

            DB::transaction(function () use ($employee, $next): void {
                $employee->forceFill(['device_user_id' => (string) $next])->save();
                app(PunchIngestor::class)->relink($employee, null);
            });

            $this->comment("Gave {$employee->full_name} machine user ID {$next}.");
            $next++;
        }
    }

    /**
     * POSTs the lines to /iclock/cdata?table=ATTLOG through the HTTP kernel, in uploads of LINES_PER_UPLOAD lines.
     *
     * @param  list<string>  $lines
     * @return list<string> the endpoint's replies
     */
    private function send(Device $device, array $lines): array
    {
        $kernel = app(HttpKernel::class);
        $replies = [];

        foreach (array_chunk($lines, self::LINES_PER_UPLOAD) as $chunk) {
            $request = Request::create(
                // No Stamp: the device's stored ATTLOGStamp (what a real machine is told to resume from) is left alone.
                '/iclock/cdata?SN='.rawurlencode($device->serial_no).'&table=ATTLOG',
                'POST',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'text/plain', 'REMOTE_ADDR' => '127.0.0.1'],
                implode("\n", $chunk)."\n",
            );

            $response = $kernel->handle($request);
            $replies[] = (string) $response->getContent();
            $kernel->terminate($request, $response);
        }

        return $replies;
    }

    private function minutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return $h * 60 + $m;
    }

    /** "Y-m-d H:i:s" on $date at $minutes past midnight (kept within the day). */
    private function at(string $date, int $minutes, int $seconds): string
    {
        $minutes = max(0, min(23 * 60 + 59, $minutes));

        return sprintf('%s %02d:%02d:%02d', $date, intdiv($minutes, 60), $minutes % 60, $seconds);
    }
}
