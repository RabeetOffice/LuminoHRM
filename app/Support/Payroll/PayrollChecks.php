<?php

namespace App\Support\Payroll;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\Attendance\AttendanceClock;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;

/**
 * What blocks the approval of a payroll run and what the approver should know (design 5.6 "Checks").
 *
 *   PayrollChecks::for($run);       // list of {key, severity: block|warn, message, people: [{id, name}], count}
 *   PayrollChecks::blocking($run);  // the blocking keys only
 *
 * Blocking (BLOCKING): the month has not ended (the run's month is not before the current attendance month); an
 * earlier month's run is still a draft; a non-excluded line with no salary profile or with net pay below zero; for
 * non-excluded people, inside each person's counted window (from max(joining date, the 1st, attendance.start_date) to
 * the month's last day): attendance rows needing attention (missing clock-out), rows still being calculated (status
 * null or needs_rebuild) dated before today, past counted days with no attendance row at all
 * (PayInputLoader::missingDays; skipped while attendance.start_date is not set), and leave requests still open
 * (pending or waiting for HR) touching the month.
 *
 * Warnings: no bank account (paid outside the bank sheet), settled separately, left without a last working day,
 * excluded lines, tax overridden, installment reduced, unpaid leave on a day off / on a day corrected as worked, days
 * without a salary profile, the salary changed mid-month, attendance started inside or after the month (or not at all).
 *
 * Only checks that apply are listed, blocking ones first; count = the number of people (0 for month-wide checks).
 * A fixed number of queries whatever the headcount (whereIn reads: one per 500 people).
 */
final class PayrollChecks
{
    public const BLOCKING = [
        'month_not_ended', 'earlier_draft', 'no_profile', 'negative_net', 'missing_clock_out', 'pending_days',
        'missing_days', 'open_leave',
    ];

    /** Line warning keys written by PayCalculator, in display order. */
    private const LINE_WARNINGS = [
        'installment_reduced' => 'A loan installment was reduced because pay is short for %s.',
        'unpaid_leave_on_off_day' => 'Unpaid leave on a day off was not deducted for %s.',
        'unpaid_leave_on_worked_day' => 'Unpaid leave on a day corrected as worked was deducted for %s. Cancel the leave if they worked.',
        'days_without_profile' => 'Some days of the month have no salary profile and are not paid for %s.',
    ];

    private const CHUNK = 500;

    /** @return list<array{key: string, severity: 'block'|'warn', message: string, people: list<array{id: int, name: string}>, count: int}> */
    public static function for(PayrollRun $run): array
    {
        [$first, $last] = PayrollRoster::bounds($run->month);
        $today = app(AttendanceClock::class)->attendanceToday();
        $startDate = AttendanceClock::startDate();

        /** @var Collection<int, PayrollItem> $items */
        $items = PayrollItem::query()
            ->where('payroll_run_id', $run->id)
            ->with('employee.bankAccount')
            ->orderBy('employee_id')
            ->get();
        $active = $items->reject(fn (PayrollItem $i): bool => $i->excluded)->values();
        /** @var Collection<int, Employee> $people */
        $people = $active->map(fn (PayrollItem $i): Employee => $i->employee)->keyBy('id');
        $ids = array_map('intval', $people->keys()->all());

        $block = [];
        $warn = [];

        // The month must be over.
        if ($run->month >= substr($today, 0, 7)) {
            $next = (new DateTimeImmutable($last, new DateTimeZone('UTC')))->modify('+1 day');
            $block[] = self::check('month_not_ended', 'block',
                "{$run->label()} has not ended yet. It can be approved from ".$next->format('j M Y').'.', []);
        }

        // Months are approved in order.
        $earlier = PayrollRun::query()
            ->where('month', '<', $run->month)
            ->where('status', 'draft')
            ->orderBy('month')
            ->pluck('month')
            ->all();
        if ($earlier !== []) {
            $labels = implode(', ', array_map(fn ($m): string => PayrollRun::monthLabel((string) $m), $earlier));
            $block[] = self::check('earlier_draft', 'block',
                "Payroll for {$labels} is still a draft. Approve the months in order.", []);
        }

        $withBlocking = fn (string $key): Collection => $active->filter(fn (PayrollItem $i): bool => in_array($key, (array) $i->blocking, true));

        $noProfile = self::peopleOf($withBlocking('no_profile'));
        if ($noProfile !== []) {
            $block[] = self::check('no_profile', 'block',
                self::count($noProfile, 'person has', 'people have').' no salary profile for this month. Add one or exclude them.', $noProfile);
        }

        $negative = self::peopleOf($withBlocking('negative_net'));
        if ($negative !== []) {
            $block[] = self::check('negative_net', 'block',
                'Net pay is below zero for '.self::count($negative, 'person', 'people').'. Reduce the deductions or exclude them.', $negative);
        }

        // Attendance facts inside each person's counted window.
        $windows = [];
        foreach ($people as $id => $employee) {
            $from = max($employee->joining_date->format('Y-m-d'), $first, $startDate ?? '');
            $to = min($last, $employee->last_working_day?->format('Y-m-d') ?? $last);
            $windows[(int) $id] = [$from, $to];
        }

        $attention = [];
        $pending = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            Attendance::query()
                ->whereIn('employee_id', $chunk)
                ->betweenDates($first, $last)
                ->where(fn ($q) => $q->where('needs_attention', true)->orWhereNull('status')->orWhere('needs_rebuild', true))
                ->toBase()
                ->get(['employee_id', 'date', 'status', 'needs_attention', 'needs_rebuild'])
                ->each(function (object $row) use ($windows, $today, &$attention, &$pending): void {
                    $id = (int) $row->employee_id;
                    $date = substr((string) $row->date, 0, 10);
                    [$from, $to] = $windows[$id];

                    if ($date < $from || $date > $to) {
                        return;
                    }

                    if ((bool) $row->needs_attention) {
                        $attention[$id][] = $date;
                    }

                    if (($row->status === null || (bool) $row->needs_rebuild) && $date < $today) {
                        $pending[$id][] = $date;
                    }
                });
        }

        if ($attention !== []) {
            $days = array_sum(array_map('count', $attention));
            $block[] = self::check('missing_clock_out', 'block',
                self::count($days, 'day has', 'days have').' a missing clock-out to resolve on the Daily screen.', self::named($people, array_keys($attention)));
        }

        if ($pending !== []) {
            $days = array_sum(array_map('count', $pending));
            $block[] = self::check('pending_days', 'block',
                self::count($days, 'day is', 'days are').' still being calculated. Wait for attendance to finish or rebuild it.', self::named($people, array_keys($pending)));
        }

        if ($startDate !== null && $people->isNotEmpty()) {
            $missing = array_filter(PayInputLoader::missingDays($run->month, $people->values()), fn (array $dates): bool => $dates !== []);
            if ($missing !== []) {
                $days = array_sum(array_map('count', $missing));
                $block[] = self::check('missing_days', 'block',
                    self::count($days, 'past day has', 'past days have').' no attendance record. Close those days or correct them.', self::named($people, array_keys($missing)));
            }
        }

        $openLeave = [];
        $openRequests = 0;
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            LeaveRequest::query()
                ->whereIn('employee_id', $chunk)
                ->whereIn('status', (array) config('hrm.leave.open_statuses'))
                ->where('from_date', '<=', $last)
                ->where('to_date', '>=', $first)
                ->toBase()
                ->get(['id', 'employee_id'])
                ->each(function (object $row) use (&$openLeave, &$openRequests): void {
                    $openLeave[(int) $row->employee_id] = true;
                    $openRequests++;
                });
        }
        if ($openLeave !== []) {
            $block[] = self::check('open_leave', 'block',
                self::count($openRequests, 'leave request touching this month is', 'leave requests touching this month are').' still open. Approve or reject them first.',
                self::named($people, array_keys($openLeave)));
        }

        // Warnings.
        $noBank = self::peopleOf($active->filter(fn (PayrollItem $i): bool => trim((string) $i->employee->bankAccount?->account_number) === ''));
        if ($noBank !== []) {
            $warn[] = self::check('no_bank_account', 'warn',
                self::count($noBank, 'person has', 'people have').' no bank account and will be paid outside the bank sheet.', $noBank);
        }

        $settled = self::listed($run->summary['settled_separately'] ?? []);
        if ($settled !== []) {
            $warn[] = self::check('settled_separately', 'warn',
                self::count($settled, 'person leaves', 'people leave').' this month and will be settled separately, not by this payroll.', $settled);
        }

        $left = self::listed($run->summary['left_without_last_day'] ?? []);
        if ($left !== []) {
            $warn[] = self::check('left_without_last_day', 'warn',
                self::count($left, 'person has', 'people have').' left with no last working day and will not be paid. Set the date on their record.', $left);
        }

        $excluded = self::peopleOf($items->filter(fn (PayrollItem $i): bool => $i->excluded));
        if ($excluded !== []) {
            $warn[] = self::check('excluded', 'warn',
                self::count($excluded, 'line is', 'lines are').' excluded and pay nothing.', $excluded);
        }

        $overridden = self::peopleOf($active->filter(fn (PayrollItem $i): bool => $i->tax_overridden));
        if ($overridden !== []) {
            $warn[] = self::check('tax_overridden', 'warn',
                'Tax is overridden on '.self::count($overridden, 'line', 'lines').'.', $overridden);
        }

        foreach (self::LINE_WARNINGS as $key => $message) {
            $who = self::peopleOf($active->filter(
                fn (PayrollItem $i): bool => in_array($key, array_column((array) $i->warnings, 'key'), true),
            ));
            if ($who !== []) {
                $warn[] = self::check($key, 'warn', sprintf($message, self::count($who, 'person', 'people')), $who);
            }
        }

        $midMonth = self::peopleOf($active->filter(fn (PayrollItem $i): bool => count((array) ($i->breakdown['segments'] ?? [])) > 1));
        if ($midMonth !== []) {
            $warn[] = self::check('profile_changed_mid_month', 'warn',
                'The salary changed during the month for '.self::count($midMonth, 'person', 'people').'; each salary pays for its own days.', $midMonth);
        }

        if ($startDate === null) {
            $warn[] = self::check('attendance_start', 'warn',
                'Attendance has not started, so absences, half days and late days were not checked.', []);
        } elseif ($startDate > $first) {
            $warn[] = self::check('attendance_start', 'warn',
                'Attendance started on '.(new DateTimeImmutable($startDate, new DateTimeZone('UTC')))->format('j M Y').'; days before it were not checked.', []);
        }

        return [...$block, ...$warn];
    }

    /** @return list<string> the keys of the blocking checks that apply */
    public static function blocking(PayrollRun $run): array
    {
        return array_values(array_map(
            fn (array $c): string => $c['key'],
            array_filter(self::for($run), fn (array $c): bool => $c['severity'] === 'block'),
        ));
    }

    /**
     * @param  'block'|'warn'  $severity
     * @param  list<array{id: int, name: string}>  $people
     * @return array{key: string, severity: 'block'|'warn', message: string, people: list<array{id: int, name: string}>, count: int}
     */
    private static function check(string $key, string $severity, string $message, array $people): array
    {
        return ['key' => $key, 'severity' => $severity, 'message' => $message, 'people' => $people, 'count' => count($people)];
    }

    /**
     * @param  Collection<int, PayrollItem>  $items  with employee loaded
     * @return list<array{id: int, name: string}>
     */
    private static function peopleOf(Collection $items): array
    {
        return array_values($items->map(fn (PayrollItem $i): array => [
            'id' => (int) $i->employee_id,
            'name' => $i->employee->full_name,
        ])->all());
    }

    /**
     * @param  Collection<int, Employee>  $people  keyed by id
     * @param  list<int>  $ids
     * @return list<array{id: int, name: string}>
     */
    private static function named(Collection $people, array $ids): array
    {
        sort($ids);

        return array_map(fn (int $id): array => ['id' => $id, 'name' => $people[$id]->full_name], $ids);
    }

    /**
     * A roster list stored on the run summary as {id, name}.
     *
     * @param  mixed  $list
     * @return list<array{id: int, name: string}>
     */
    private static function listed($list): array
    {
        return array_values(array_map(
            fn (array $p): array => ['id' => (int) ($p['id'] ?? 0), 'name' => (string) ($p['name'] ?? '')],
            array_filter(is_array($list) ? $list : [], 'is_array'),
        ));
    }

    /**
     * "1 person has" / "3 people have" (a count, or a list whose length is counted).
     *
     * @param  int|list<mixed>  $n
     */
    private static function count(int|array $n, string $one, string $many): string
    {
        $n = is_array($n) ? count($n) : $n;

        return $n.' '.($n === 1 ? $one : $many);
    }
}
