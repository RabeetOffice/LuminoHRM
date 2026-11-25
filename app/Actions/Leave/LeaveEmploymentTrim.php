<?php

namespace App\Actions\Leave;

use App\Mail\LeaveRequestDecided;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Audit\Audit;
use App\Support\Leave\LeaveDays;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Trims an employee's leave to their employment window after the window shrank (plan open decision 13; default, owner
 * may change). Called by UpdateEmployee (joining date or last working day changed) and ChangeEmployeeStatus (status or
 * last working day changed), next to RebuildMarks::outsideEmployment, inside their transaction with the employee row
 * already locked.
 *
 * Window: joining date to last working day; for a leaver without one (Leavers::withoutLastDay) it ends the day before
 * AttendanceClock::attendanceToday() (so their leave from today goes), exactly as LeaveDays::plan counts.
 *
 * - Approved requests with leave days outside the window: those days go through LeaveDayRemoval (balance refunded by
 *   derivation, attendance re-marked; a day in a locked payroll month stays). No day left → cancelled. Days removed
 *   after the window's end → it stays approved with cancelled_from = the first removed date ("partly cancelled"). Days
 *   removed only before a later joining date → it stays approved with `days` reduced (cancelled_from would wrongly say
 *   "cancelled from" a date whose later days stay).
 * - Open requests with any counted day outside the window → cancelled.
 * - cancel_reason "Employment ends {date}.", "Joining date moved to {date}." or both (days removed at both ends);
 *   cancelled_by stays empty (the system cancelled it). A request HR had already cancelled in part keeps HR's
 *   cancelled_by / cancelled_at and gets the trim's reason appended to HR's. One audit entry
 *   `Leave trimmed to employment` per request, causer = who changed the employee (`removed` dates, `refunded`,
 *   `outcome`, `automatic` = true).
 * - Mail after the commit, only to an active login (a leaver's login is usually disabled in the same transaction, so
 *   they get none): `cancelled` for approved leave cancelled wholly or from a date, `refunded` for days trimmed before
 *   a later joining date, `withdrawn` for an open request cancelled this way (reason as the note).
 *
 * Idempotent: a second run finds no day outside the window and no open request outside it.
 */
final class LeaveEmploymentTrim
{
    public function __construct(
        private readonly LeaveDayRemoval $removal,
        private readonly AttendanceClock $clock,
    ) {}

    /** @return int the number of requests touched */
    public function apply(Employee $employee, User $by): int
    {
        return DB::transaction(function () use ($employee, $by): int {
            // The employee row first (the callers hold it already; locking it again is harmless and makes this safe on
            // its own), then the leave rows.
            $current = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

            $from = $current->joining_date->format('Y-m-d');
            $to = $current->last_working_day?->format('Y-m-d');

            if ($to === null && Leavers::withoutLastDay($current)) {
                $to = (new DateTimeImmutable($this->clock->attendanceToday()))->modify('-1 day')->format('Y-m-d');
            }

            $mails = [];
            $touched = $this->trimApproved($current, $from, $to, $by, $mails)
                + $this->cancelOpen($current, $from, $to, $by, $mails);

            if ($mails !== []) {
                $employeeId = (int) $current->id;
                DB::afterCommit(fn () => $this->mail($employeeId, $mails));
            }

            return $touched;
        }, 3);
    }

    /**
     * @param  list<array{0: LeaveRequest, 1: string, 2: string}>  $mails  [request, outcome, note], filled here
     */
    private function trimApproved(Employee $employee, string $from, ?string $to, User $by, array &$mails): int
    {
        $outside = LeaveDay::query()->toBase()
            ->where('employee_id', $employee->id)
            ->where(fn ($q) => $to === null ? $q->where('date', '<', $from) : $q->where('date', '<', $from)->orWhere('date', '>', $to))
            ->orderBy('date')
            ->get(['leave_request_id', 'date']);

        if ($outside->isEmpty()) {
            return 0;
        }

        $datesByRequest = [];

        foreach ($outside as $row) {
            $datesByRequest[(int) $row->leave_request_id][] = substr((string) $row->date, 0, 10);
        }

        $requests = LeaveRequest::query()->whereIn('id', array_keys($datesByRequest))->with('type:id,name')
            ->orderBy('id')->lockForUpdate()->get();

        $results = [];

        foreach ($requests as $request) {
            $request->setRelation('employee', $employee);
            $result = $this->removal->remove($request, array_values(array_unique($datesByRequest[$request->id])), 'Employment window changed', $by);

            if ($result['removed'] !== []) {
                $results[$request->id] = [$request, $result];
            }
        }

        if ($results === []) {
            return 0;
        }

        $left = [];

        foreach (LeaveDay::query()->toBase()->whereIn('leave_request_id', array_keys($results))->get(['leave_request_id', 'amount']) as $row) {
            $left[(int) $row->leave_request_id] = ($left[(int) $row->leave_request_id] ?? 0) + LeaveDays::halves($row->amount);
        }

        foreach ($results as $id => [$request, $result]) {
            $none = ! isset($left[$id]);
            $removed = $result['removed'];
            $afterEnd = array_values(array_filter($removed, fn (string $d): bool => $to !== null && $d > $to));
            $beforeStart = array_values(array_filter($removed, fn (string $d): bool => $d < $from));
            $reason = $this->reason($beforeStart !== [] ? $from : null, $afterEnd !== [] ? $to : null);

            $request->fill(['days' => LeaveDays::fromHalves($left[$id] ?? 0)]);

            if ($none) {
                $outcome = 'cancelled';
                $request->fill(['status' => 'cancelled', 'cancelled_from' => null, ...$this->cancelFields($request, $reason)]);
            } elseif ($afterEnd !== []) {
                $outcome = 'partly_cancelled';
                // A request cancelled from a later date before keeps the earlier cancel-from only when it is earlier.
                $first = $afterEnd[0];
                $existing = $request->cancelled_from?->format('Y-m-d');
                $request->fill([
                    'cancelled_from' => $existing !== null && $existing < $first ? $existing : $first,
                    ...$this->cancelFields($request, $reason),
                ]);
            } else {
                $outcome = 'trimmed_start';
            }

            $request->save();

            $this->audit($request, $by, [
                'outcome' => $outcome,
                'removed' => $removed,
                'refunded' => $result['amount'],
                'kept_locked' => $result['kept_locked'] === [] ? null : $result['kept_locked'],
                'days' => $request->days,
                'reason' => $reason,
            ]);

            $mails[] = [$request, $outcome === 'trimmed_start' ? 'refunded' : 'cancelled', $reason];
        }

        return count($results);
    }

    /**
     * @param  list<array{0: LeaveRequest, 1: string, 2: string}>  $mails  [request, outcome, note], filled here
     */
    private function cancelOpen(Employee $employee, string $from, ?string $to, User $by, array &$mails): int
    {
        $candidates = LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->where(fn ($q) => $to === null ? $q->where('from_date', '<', $from) : $q->where('from_date', '<', $from)->orWhere('to_date', '>', $to))
            ->with('type:id,name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($candidates->isEmpty()) {
            return 0;
        }

        $plans = LeaveDays::planMany(array_values($candidates->map(fn (LeaveRequest $r): array => [
            $employee,
            $r->from_date->format('Y-m-d'),
            $r->to_date->format('Y-m-d'),
            $r->is_half_day ? $r->half : null,
        ])->all()));

        $count = 0;

        foreach ($candidates->values() as $i => $request) {
            $plan = $plans[$i];

            // Only the window refusal cancels: count() refuses a counted day outside the window before anything else
            // that could still apply here (nothing to count), and offs or holidays outside the window do not count.
            if (! $plan->isRefused() || in_array($plan->refusal, [
                LeaveDays::REFUSAL_END_BEFORE_START,
                LeaveDays::REFUSAL_HALF_DAY_RANGE,
                LeaveDays::REFUSAL_NOTHING_TO_COUNT,
            ], true)) {
                continue;
            }

            $request->setRelation('employee', $employee);
            $reason = $this->reason(
                $request->from_date->format('Y-m-d') < $from ? $from : null,
                $to !== null && $request->to_date->format('Y-m-d') > $to ? $to : null,
            );

            $request->fill([
                'status' => 'cancelled',
                'cancelled_by' => null,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ])->save();

            $this->audit($request, $by, [
                'outcome' => 'open_cancelled',
                'step' => 'open',
                'days' => $request->days,
                'reason' => $reason,
            ]);

            $mails[] = [$request, 'withdrawn', $reason];
            $count++;
        }

        return $count;
    }

    /** @param  list<array{0: LeaveRequest, 1: string, 2: string}>  $mails */
    private function mail(int $employeeId, array $mails): void
    {
        $user = User::query()->where('employee_id', $employeeId)->where('is_active', true)->first();

        if ($user === null) {
            return;
        }

        foreach ($mails as [$request, $outcome, $note]) {
            try {
                Mail::to($user)->queue(new LeaveRequestDecided($request, $user->name, $outcome, $note));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(LeaveRequest $request, User $by, array $properties): void
    {
        Audit::record('leave', 'Leave trimmed to employment', $request->employee, array_filter([
            'request' => $request->id,
            'type' => $request->type?->name,
            'from' => $request->from_date->format('Y-m-d'),
            'to' => $request->to_date->format('Y-m-d'),
            'half' => $request->is_half_day ? $request->half : null,
            'automatic' => true,
            ...$properties,
        ], fn ($v) => $v !== null), $by);
    }

    /**
     * "Joining date moved to {date}.", "Employment ends {date}." or both (a save that moved the joining date later and
     * the last day earlier removes days at both ends).
     */
    private function reason(?string $joining, ?string $lastDay): string
    {
        return implode(' ', array_filter([
            $joining !== null ? "Joining date moved to {$this->dayLabel($joining)}." : null,
            $lastDay !== null ? "Employment ends {$this->dayLabel($lastDay)}." : null,
        ]));
    }

    /**
     * The cancel fields for a trim. A request HR had already cancelled in part keeps who cancelled it and when (that
     * decision stays theirs; the trim is in the audit entry) and the trim's reason is appended to HR's; otherwise the
     * system cancels it (cancelled_by empty).
     *
     * @return array{cancelled_by?: int|null, cancelled_at?: mixed, cancel_reason: string}
     */
    private function cancelFields(LeaveRequest $request, string $reason): array
    {
        $earlier = trim((string) $request->cancel_reason);

        if ($request->cancelled_at === null) {
            return ['cancelled_by' => null, 'cancelled_at' => now(), 'cancel_reason' => $reason];
        }

        if ($earlier === '' || str_contains($earlier, $reason)) {
            return ['cancel_reason' => $earlier === '' ? $reason : $earlier];
        }

        return ['cancel_reason' => (preg_match('/[.!?]$/u', $earlier) === 1 ? $earlier : "{$earlier}.").' '.$reason];
    }

    /** "Mon 1 Dec 2026" from "Y-m-d" (no time zone involved). */
    private function dayLabel(string $date): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed === false ? $date : $parsed->format('D j M Y');
    }
}
