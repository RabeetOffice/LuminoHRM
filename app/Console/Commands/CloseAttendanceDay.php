<?php

namespace App\Console\Commands;

use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\AttendanceRebuilder;
use Illuminate\Console\Command;

/**
 * The nightly close (routes/console.php). Without a date it closes each of the previous 7 days in the company zone
 * (AttendanceClock), oldest first: yesterday on a normal night, and any day a missed run left open. With a date it
 * closes just that past day. Every run is idempotent: a day already closed is left as it is.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'hrm:attendance-close {date? : A past day (Y-m-d); default: the previous 7 days}';

    protected $description = 'Close past attendance days: create missing records, flag single punches, mark the days closed';

    public const CATCH_UP_DAYS = 7;

    public function handle(AttendanceRebuilder $rebuilder, AttendanceClock $clock): int
    {
        $today = $clock->today();
        $date = $this->argument('date');

        if (is_string($date) && $date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $this->error('The date must be Y-m-d.');

                return self::FAILURE;
            }

            if ($date >= $today->format('Y-m-d')) {
                $this->error("Only a past day can be closed (today is {$today->format('Y-m-d')}).");

                return self::FAILURE;
            }

            $days = [$date];
        } else {
            $days = [];

            for ($i = self::CATCH_UP_DAYS; $i >= 1; $i--) {
                $days[] = $today->subDays($i)->format('Y-m-d');
            }
        }

        foreach ($days as $day) {
            $written = $rebuilder->closeDay($day);
            $this->output->writeln("attendance_close date={$day} written={$written}");
        }

        return self::SUCCESS;
    }
}
