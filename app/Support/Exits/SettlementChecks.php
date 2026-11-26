<?php

namespace App\Support\Exits;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\LeaveRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Resignation;
use App\Models\SalaryProfile;
use App\Support\Attendance\AttendanceClock;
use App\Support\Payroll\PayInputLoader;
use App\Support\Payroll\PayrollRoster;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What blocks the approval of a settlement and what the approver should know (design 5.5 "Approve"), for one person
 * and their final month, the same facts PayrollChecks reads for a run.
 *
 *   SettlementChecks::for($settlement);       // list of {key, severity: block|warn, message}, blocking ones first
 *   SettlementChecks::blocking($settlement);  // the blocking keys, in order
 *
 * Blocking (in this order; approval is refused with the first): not_approved_exit, last_day_not_passed (today, from
 * AttendanceClock, must be after the last working day), clearance_incomplete (ClearanceStatus), no_profile (no salary
 * profile in force on the last working day), missing_days (PayInputLoader::missingDays; skipped while attendance has
 * not started), missing_clock_out, pending_days (status null or needs_rebuild before today), all inside the counted
 * window max(joining date, the 1st, attendance start) to the last working day, open_leave (pending / manager-approved
 * requests touching the month), paid_in_run (the month's approved / paid run holds a non-excluded line of the person,
 * so the settlement would pay the month twice: paidInRun()), previous_month_unpaid (payroll was live in the previous
 * month, i.e. the earliest approved or paid run is that month or earlier, the person was in that run (joined by its
 * last day), and that month has no approved or paid run). Plus
 * `stale` when the last working day moved since the last calculation.
 *
 * Warnings: those the last calculation stored (loan shortfall, owed by employee, encashment type missing, ...).
 * Plain reads: the approval calls it after taking its locks.
 */
final class SettlementChecks
{
    public const STALE = 'The last working day changed. Recalculate the settlement first.';

    public const NOT_APPROVED_EXIT = 'The exit must be approved before a settlement is prepared.';

    public const LAST_DAY_NOT_PASSED = 'The last working day has not passed yet.';

    /** @return list<array{key: string, severity: 'block'|'warn', message: string}> */
    public static function for(FinalSettlement $settlement): array
    {
        $resignation = Resignation::query()->findOrFail($settlement->resignation_id);
        $employee = Employee::query()->findOrFail($settlement->employee_id);
        $resignation->setRelation('employee', $employee);
        $month = (string) $settlement->month;
        $label = PayrollRun::monthLabel($month);
        $lastDay = $resignation->last_working_day->format('Y-m-d');
        $today = app(AttendanceClock::class)->attendanceToday();
        [$first, $last] = PayrollRoster::bounds($month);
        $to = min($last, $lastDay);
        $startDate = AttendanceClock::startDate();
        $from = max($employee->joining_date->format('Y-m-d'), $first, $startDate ?? '');

        $block = [];

        if ($resignation->status !== ExitStates::APPROVED) {
            $block[] = self::check('not_approved_exit', 'block', self::NOT_APPROVED_EXIT);
        }

        if ($settlement->stale) {
            $block[] = self::check('stale', 'block', self::STALE);
        }

        if ($today <= $lastDay) {
            $block[] = self::check('last_day_not_passed', 'block', self::LAST_DAY_NOT_PASSED);
        }

        $clearance = ClearanceStatus::for($resignation);
        if (! $clearance['complete']) {
            $block[] = self::check('clearance_incomplete', 'block', 'Clearance is not complete: '.implode(', ', $clearance['missing']).'.');
        }

        $hasProfile = SalaryProfile::query()->where('employee_id', $employee->id)->where('effective_from', '<=', $lastDay)->exists();
        if (! $hasProfile) {
            $block[] = self::check('no_profile', 'block', "{$employee->full_name} has no salary profile for {$label}.");
        }

        if ($startDate !== null) {
            $missing = count(PayInputLoader::missingDays($month, new Collection([$employee]))[(int) $employee->id] ?? []);
            if ($missing > 0) {
                $block[] = self::check('missing_days', 'block', self::openDays($missing));
            }
        }

        $attention = 0;
        $pending = 0;
        if ($from <= $to) {
            Attendance::query()
                ->where('employee_id', $employee->id)
                ->betweenDates($from, $to)
                ->where(fn ($q) => $q->where('needs_attention', true)->orWhereNull('status')->orWhere('needs_rebuild', true))
                ->toBase()
                ->get(['date', 'status', 'needs_attention', 'needs_rebuild'])
                ->each(function (object $row) use ($today, &$attention, &$pending): void {
                    if ((bool) $row->needs_attention) {
                        $attention++;
                    }

                    if (($row->status === null || (bool) $row->needs_rebuild) && substr((string) $row->date, 0, 10) < $today) {
                        $pending++;
                    }
                });
        }

        if ($attention > 0) {
            $block[] = self::check('missing_clock_out', 'block', self::openDays($attention));
        }

        if ($pending > 0) {
            $block[] = self::check('pending_days', 'block', self::openDays($pending));
        }

        $openLeave = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->where('from_date', '<=', $last)
            ->where('to_date', '>=', $first)
            ->exists();
        if ($openLeave) {
            $block[] = self::check('open_leave', 'block', "Leave requests touching {$label} are still open.");
        }

        $paid = self::paidInRun($employee, $month);
        if ($paid !== null) {
            $block[] = self::check('paid_in_run', 'block', $paid);
        }

        $previous = (new DateTimeImmutable($first, new DateTimeZone('UTC')))->modify('-1 month')->format('Y-m');
        [, $previousLast] = PayrollRoster::bounds($previous);
        $locked = (array) config('hrm.payroll.locked_statuses');
        $runs = PayrollRun::query()->orderBy('month')->pluck('status', 'month');
        // Payroll was live in the previous month: the earliest approved / paid run is that month or before it. A go-live
        // run in the settlement month itself (no earlier run can ever be made) does not gate.
        $earliest = $runs->filter(fn ($status): bool => in_array($status, $locked, true))->keys()->first();
        $live = $earliest !== null && (string) $earliest <= $previous;
        $previousDone = in_array($runs->get($previous), $locked, true);

        if ($live && ! $previousDone && $employee->joining_date->format('Y-m-d') <= $previousLast) {
            $block[] = self::check('previous_month_unpaid', 'block', 'Approve the payroll run for '.PayrollRun::monthLabel($previous).' first.');
        }

        $warn = [];
        foreach ((array) ($settlement->breakdown['warnings'] ?? []) as $warning) {
            if (is_array($warning) && isset($warning['key'], $warning['message'])) {
                $warn[] = self::check((string) $warning['key'], 'warn', (string) $warning['message']);
            }
        }

        return [...$block, ...$warn];
    }

    /** @return list<string> the keys of the blocking checks that apply, in order */
    public static function blocking(FinalSettlement $settlement): array
    {
        return array_values(array_map(
            fn (array $c): string => $c['key'],
            array_filter(self::for($settlement), fn (array $c): bool => $c['severity'] === 'block'),
        ));
    }

    /**
     * The refusal when $employee was already paid for $month by payroll: the month has an approved or paid run holding a
     * line of theirs that is not excluded (an excluded line paid nothing). Null otherwise. Two reads at most: the
     * month's run row, then that person's line, each LOCK IN SHARE MODE inside a transaction (selected by month, and by
     * run + employee, only; the status and the excluded flag are filtered in PHP). Callers hold the employee row first.
     */
    public static function paidInRun(Employee $employee, string $month): ?string
    {
        $runs = DB::table((new PayrollRun)->getTable())->where('month', $month);
        if (DB::transactionLevel() > 0) {
            $runs->sharedLock();
        }

        $run = $runs->first(['id', 'status']);
        if ($run === null || ! in_array($run->status, (array) config('hrm.payroll.locked_statuses'), true)) {
            return null;
        }

        $items = DB::table((new PayrollItem)->getTable())->where('payroll_run_id', $run->id)->where('employee_id', $employee->id);
        if (DB::transactionLevel() > 0) {
            $items->sharedLock();
        }

        $excluded = $items->value('excluded');
        if ($excluded === null || (bool) $excluded) {
            return null;
        }

        return self::paidMessage((string) $employee->full_name, $month);
    }

    /** "Sara Khan was already paid in the November 2026 payroll. Move the last working day to a later month first." */
    public static function paidMessage(string $name, string $month): string
    {
        return "{$name} was already paid in the ".PayrollRun::monthLabel($month).' payroll. Move the last working day to a later month first.';
    }

    private static function openDays(int $n): string
    {
        return "Attendance for the final month is not complete: {$n} days still open.";
    }

    /**
     * @param  'block'|'warn'  $severity
     * @return array{key: string, severity: 'block'|'warn', message: string}
     */
    private static function check(string $key, string $severity, string $message): array
    {
        return ['key' => $key, 'severity' => $severity, 'message' => $message];
    }
}
