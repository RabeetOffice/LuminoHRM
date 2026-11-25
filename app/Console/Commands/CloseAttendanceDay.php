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
 *
 * Backfill (runs without a date only, after the 7 days): older days, from max(attendance start date, today - 62 days) to
 * 8 days back, on which someone who should have a record has none (AttendanceRebuilder::daysWithMissingRows: two
 * queries, nothing at all while the start date is unset). That happens when the start date is set to a date more than a
 * week back, or when one person's window widens (joining date moved earlier, last working day extended or cleared, a
 * leaver set back to active). Each such day is closed with closeDay(), which only CREATES the missing rows and decides
 * days still open or pending: days already closed keep the rules they were decided with (never rebuildRange). At most
 * BACKFILL_DAYS days and about BACKFILL_SECONDS seconds per run, oldest first; the next hourly run continues.
 */
class CloseAttendanceDay extends Command
{
    protected $signature = 'hrm:attendance-close {date? : A past day (Y-m-d); default: the previous 7 days, then older days with missing records}';

    protected $description = 'Close past attendance days: create missing records, flag single punches, mark the days closed';

    public const CATCH_UP_DAYS = 7;

    /** How far back the backfill looks for days with missing records. */
    public const BACKFILL_WINDOW_DAYS = 62;

    /** At most this many older days are backfilled per run. */
    public const BACKFILL_DAYS = 10;

    /** No new backfill day is started after this many seconds of the run. */
    public const BACKFILL_SECONDS = 45;

    public function handle(AttendanceRebuilder $rebuilder, AttendanceClock $clock): int
    {
        $started = hrtime(true);
        $today = CarbonImmutable::createFromFormat('!Y-m-d', $clock->attendanceToday(), $clock->zone());
        $date = $this->argument('date');
        $explicit = is_string($date) && $date !== '';

        if ($explicit) {
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
            $failed += $this->close($rebuilder, $day, 'attendance_close') ? 0 : 1;
        }

        if (! $explicit) {
            $failed += $this->backfill($rebuilder, $today, $started);
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Closes the older days that still miss records, bounded per run. Returns how many failed.
     */
    private function backfill(AttendanceRebuilder $rebuilder, CarbonImmutable $today, int|float $started): int
    {
        try {
            $missing = $rebuilder->daysWithMissingRows(
                $today->subDays(self::BACKFILL_WINDOW_DAYS)->format('Y-m-d'),
                $today->subDays(self::CATCH_UP_DAYS + 1)->format('Y-m-d'),
            );
        } catch (Throwable $e) {
            Log::error('Attendance backfill could not list the days', ['exception' => $e::class]);
            $this->output->writeln('attendance_backfill failed='.$e::class);

            return 1;
        }

        $failed = 0;
        $done = 0;

        foreach ($missing as $day) {
            if ($done >= self::BACKFILL_DAYS || (hrtime(true) - $started) / 1e9 >= self::BACKFILL_SECONDS) {
                break;
            }

            $failed += $this->close($rebuilder, $day, 'attendance_backfill') ? 0 : 1;
            $done++;
        }

        if (count($missing) > $done) {
            $this->output->writeln('attendance_backfill remaining='.(count($missing) - $done));
        }

        return $failed;
    }

    /** Closes one day; one day that fails must not stop the later days: logged (class only), reported, retried next run. */
    private function close(AttendanceRebuilder $rebuilder, string $day, string $label): bool
    {
        try {
            $written = $rebuilder->closeDay($day);
        } catch (Throwable $e) {
            Log::error('Attendance day close failed', ['exception' => $e::class, 'date' => $day]);
            $this->output->writeln("{$label} date={$day} failed=".$e::class);

            return false;
        }

        $this->output->writeln("{$label} date={$day} written={$written}");

        return true;
    }
}
