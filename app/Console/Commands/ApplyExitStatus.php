<?php

namespace App\Console\Commands;

use App\Actions\Employees\ChangeEmployeeStatus;
use App\Actions\Exits\ExitGuards;
use App\Exceptions\AccountRuleViolation;
use App\Models\Resignation;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\Exits\ExitStates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * hrm:exit-apply-status {--date=} (routes/console.php, hourly at :50). The day after an approved exit's last working day the
 * person's status becomes resigned (resignation) or terminated (termination) and their login is disabled, through
 * ChangeEmployeeStatus with no actor (the system).
 *
 * Picks approved exits with last_working_day before the date (default AttendanceClock::attendanceToday(); --date= is for
 * tests and catch-up) and no status_applied_at, oldest first, and handles each in its own transaction (employee row, then
 * resignation row, and the state is re-read after the locks). On success status_applied_at is set, status_error cleared and
 * "Exit status applied" audited. A refusal (for example the last active Admin) is logged, stored in status_error and
 * retried by the next run; the exit code is failure when any exit failed. Idempotent: an applied exit is never picked
 * again, and a person HR already set to a leaving status is only marked applied.
 */
class ApplyExitStatus extends Command
{
    protected $signature = 'hrm:exit-apply-status {--date= : Apply exits whose last working day is before this date (Y-m-d); default today}';

    protected $description = 'Set the status of people whose approved exit has passed its last working day';

    public function handle(AttendanceClock $clock, ChangeEmployeeStatus $change): int
    {
        $date = $this->option('date');
        $date = is_string($date) && $date !== '' ? $date : $clock->attendanceToday();

        if (! ExitGuards::isDate($date)) {
            $this->error('The date must be a real date as YYYY-MM-DD.');

            return self::FAILURE;
        }

        $ids = Resignation::query()
            ->where('status', ExitStates::APPROVED)
            ->whereNull('status_applied_at')
            ->where('last_working_day', '<', $date)
            ->orderBy('last_working_day')
            ->orderBy('id')
            ->pluck('id');

        $applied = 0;
        $failed = 0;

        foreach ($ids as $id) {
            try {
                $applied += DB::transaction(fn (): int => $this->apply((int) $id, $date, $change), 3);
            } catch (Throwable $e) {
                $failed++;
                $message = $this->messageOf($e);
                $this->error("Exit {$id}: {$message}");
                report($e);

                // A narrow update: only the error text, never the exit's state.
                Resignation::query()->whereKey($id)->whereNull('status_applied_at')->update(['status_error' => mb_substr($message, 0, 500)]);
            }
        }

        $this->info("Applied {$applied}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return int 1 when the exit was handled, 0 when it no longer needed it */
    private function apply(int $id, string $date, ChangeEmployeeStatus $change): int
    {
        $probe = Resignation::query()->whereKey($id)->firstOrFail(['id', 'employee_id']);
        $employee = ExitGuards::lockEmployee((int) $probe->employee_id);
        $exit = ExitGuards::lockResignation($id, $employee);
        $day = $exit->last_working_day->format('Y-m-d');

        if ($exit->status !== ExitStates::APPROVED || $exit->status_applied_at !== null || $day >= $date) {
            return 0;
        }

        $status = $exit->type === 'termination' ? 'terminated' : 'resigned';
        $before = $employee->status;

        if (! in_array($employee->status, Leavers::STATUSES, true)) {
            $change->handle($employee, $status, $day, null);
        }

        $exit->status_applied_at = now();
        $exit->status_error = null;
        $exit->save();

        Audit::record('exit', 'Exit status applied', $exit, [
            ...ExitGuards::context($exit, $employee),
            'old' => ['status' => $before],
            'attributes' => ['status' => $employee->fresh()->status, 'last_working_day' => $day],
        ]);

        return 1;
    }

    private function messageOf(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return (string) (collect($e->errors())->flatten()->first() ?? $e->getMessage());
        }

        return $e instanceof AccountRuleViolation ? $e->getMessage() : 'Unexpected error: '.$e->getMessage();
    }
}
