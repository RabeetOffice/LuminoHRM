<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use Illuminate\Console\Command;

/**
 * Every minute (routes/console.php): decides again the attendance days that punch ingestion marked needs_rebuild,
 * in batches of 500 for at most about 45 seconds; then creates today's rows for people on approved leave today who have
 * none yet (AttendanceRebuilder::rebuildLeaveToday), so they show as On Leave before the nightly close.
 *
 * By hand, for days already closed (for example after changing someone's shift):
 *   php artisan hrm:attendance-rebuild --from=2026-11-01 --to=2026-11-30 --employee=12 --employee=LD-0007
 * rebuilds every day in the range with the shift in force now. Manual days are never touched.
 */
class RebuildAttendance extends Command
{
    protected $signature = 'hrm:attendance-rebuild
        {--from= : First day to rebuild (Y-m-d); without it only pending days are rebuilt}
        {--to= : Last day to rebuild (Y-m-d, not after today); defaults to --from}
        {--employee=* : Employee id or employee code (repeatable); all employees when left out}';

    protected $description = 'Rebuild attendance days marked for rebuild, or an explicit range of days';

    private const MAX_DAYS = 366;

    public function handle(AttendanceRebuilder $rebuilder, AttendanceClock $clock): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        /** @var list<string> $who */
        $who = array_values(array_filter(
            array_map(fn (mixed $v): string => is_scalar($v) ? trim((string) $v) : '', (array) $this->option('employee')),
            fn (string $v) => $v !== '',
        ));

        if ($from === null || $from === '') {
            if (($to !== null && $to !== '') || $who !== []) {
                $this->error('--to and --employee need --from.');

                return self::FAILURE;
            }

            $total = 0;
            $started = microtime(true);

            do {
                $done = $rebuilder->rebuildPending(500);
                $total += $done;
            } while ($done >= 500 && microtime(true) - $started < 45);

            $this->output->writeln("attendance_rebuild pending={$total}");

            // Today's approved leave without a row yet, so it shows as On Leave before the nightly close.
            $leave = $rebuilder->rebuildLeaveToday();
            $this->output->writeln("attendance_rebuild leave_today={$leave}");

            return self::SUCCESS;
        }

        $to = ($to === null || $to === '') ? $from : $to;

        if (! $this->isDate((string) $from) || ! $this->isDate((string) $to)) {
            $this->error('Dates must be Y-m-d.');

            return self::FAILURE;
        }

        // The current attendance day (yesterday until the day cutoff), as AttendanceRebuilder::rebuildRange checks it.
        $today = $clock->attendanceToday();

        if ($from > $to || $to > $today) {
            $this->error("The range must run forwards and end no later than the current attendance day ({$today}).");

            return self::FAILURE;
        }

        $days = (int) (new \DateTimeImmutable((string) $from))->diff(new \DateTimeImmutable((string) $to))->days + 1;

        if ($days > self::MAX_DAYS) {
            $this->error('At most '.self::MAX_DAYS.' days at a time.');

            return self::FAILURE;
        }

        $ids = null;

        if ($who !== []) {
            $ids = [];

            foreach ($who as $value) {
                $employee = Employee::query()->where('employee_code', $value)->first()
                    ?? (ctype_digit($value) ? Employee::query()->find((int) $value) : null);

                if ($employee === null) {
                    $this->error("No employee [{$value}].");

                    return self::FAILURE;
                }

                $ids[] = (int) $employee->id;
            }
        }

        $written = $rebuilder->rebuildRange((string) $from, (string) $to, $ids);

        $this->output->writeln("attendance_rebuild from={$from} to={$to} written={$written}");

        return self::SUCCESS;
    }

    private function isDate(string $value): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
