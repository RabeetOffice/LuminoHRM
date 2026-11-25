<?php

namespace App\Console\Commands;

use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The close (routes/console.php, hourly at :30). Without a date it closes each of the 7 days before the attendance day of
 * now (AttendanceClock::attendanceToday(): today, or yesterday while the clock is still before the day cutoff), oldest
 * first. So a day is closed by the first run after the NEXT morning's cutoff (04:30 with the 04:00 cutoff), once its
 * late-night punches can no longer arrive; a day a missed run left open is caught up. With a date it closes just that
 * day, which must be before attendanceToday(). Every run is idempotent: a day already closed is left as it is. A day whose close fails is
 * logged and reported, the later days are still closed, and the command then exits with failure (the next run retries).
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'hrm:attendance-close {date? : A past day (Y-m-d); default: the previous 7 days}';

    protected $description = 'Close past attendance days: create missing records, flag single punches, mark the days closed';

    public const CATCH_UP_DAYS = 7;

    public function handle(AttendanceRebuilder $rebuilder, AttendanceClock $clock): int
    {
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $clock->attendanceToday(), $clock->zone());
        $date = $this->argument('date');

        if (is_string($date) && $date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $this->error('The date must be Y-m-d.');

                return self::FAILURE;
            }

            if ($date >= $today->format('Y-m-d')) {
                $this->error("Only a day before the current attendance day can be closed (the current attendance day is {$today->format('Y-m-d')}).");

                return self::FAILURE;
            }

            $days = [$date];
        } else {
            $days = [];

            for ($i = self::CATCH_UP_DAYS; $i >= 1; $i--) {
                $days[] = $today->subDays($i)->format('Y-m-d');
            }
        }

        $failed = 0;

        foreach ($days as $day) {
            // One day that fails must not stop the later days: logged (class only), reported, and retried next run.
            try {
                $written = $rebuilder->closeDay($day);
            } catch (Throwable $e) {
                $failed++;
                Log::error('Attendance day close failed', ['exception' => $e::class, 'date' => $day]);
                $this->output->writeln("attendance_close date={$day} failed=".$e::class);

                continue;
            }

            $this->output->writeln("attendance_close date={$day} written={$written}");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
