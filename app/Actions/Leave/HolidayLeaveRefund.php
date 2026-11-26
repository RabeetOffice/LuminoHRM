<?php

namespace App\Actions\Leave;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\RebuildMarks;
use App\Support\Audit\Audit;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A holiday was added on a date, or moved onto it (plan open decision 14, design 5.8). Called by HolidayController
 * inside the holiday's transaction, after the holiday row is written, so the day counting below already sees it.
 *
 * - Approved leave days on that date are deleted in ONE statement for everybody (the balance is refunded by
 *   derivation); the date's attendance days are flagged with RebuildMarks::forDate (every non-manual row on the date,
 *   closed or not), so they become Holiday. A request left with no day becomes cancelled ("Every day became a
 *   holiday.", keeping its days for display); otherwise it stays approved with `days` reduced. One audit entry
 *   `Leave day refunded (holiday)` per request (date, amount, holiday, and `manual` when that person's attendance row on
 *   the date was corrected by hand: it keeps its leave status, so HR is told).
 * - Open requests covering the date get `days` counted again (LeaveDays::planMany), so My Leaves and the pending figure
 *   stop holding the holiday (audited `Leave days recounted (holiday)`, old / new days, when the count changes). One
 *   whose dates are now all offs or holidays is cancelled by the system like an approved one ("Every day became a
 *   holiday.", `days` kept, `cancelled` in the audit entry).
 * - A date in a locked payroll month (PayrollLock) is left alone entirely.
 * - Removing a holiday adds nothing back (the controller does not call this on delete).
 *
 * Locking (MariaDB REPEATABLE READ): the affected rows are first found with LOCKING reads (the leave days on the date
 * and the open requests covering it; locking reads do not fix the snapshot), then every affected employee row is locked
 * in id order, then the request rows; plain reads come only after. Other leave writers lock the employee first, so a
 * clash can deadlock: the controller runs the transaction with retries.
 *
 * Cost: a fixed number of queries finds the affected set and removes the days (locking reads, employees, requests,
 * one delete, one rebuild flag, one count of what is left); each affected request then costs its own save and its audit
 * entry. People without leave on that date cost nothing. Re-running it changes nothing.
 */
final class HolidayLeaveRefund
{
    public const CANCEL_REASON = 'Every day became a holiday.';

    /**
     * @param  string  $date  "Y-m-d", the holiday's (new) date
     * @return list<array{request: LeaveRequest, outcome: 'refunded'|'cancelled'|'withdrawn', refunded: float, manual: list<string>}>
     *
     * Each request changed for its employee (employee relation set), for the mails and the flash: `refunded` approved
     * leave that keeps other days, `cancelled` approved leave that lost every day, `withdrawn` an open request cancelled
     * because every day became a holiday; `refunded` = the days that went back to the balance; `manual` = [the date] when
     * that person's attendance row on it was corrected by hand (it keeps its leave status).
     *
     * @throws InvalidArgumentException a malformed date
     */
    public function forDate(string $date, string $holidayName, User $by): array
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException("Not a date [{$date}].");
        }

        if (PayrollLock::isLocked(substr($date, 0, 7))) {
            return [];
        }

        // Locking reads only, before any plain read (see the class comment).
        $dayRows = LeaveDay::query()->toBase()->where('date', $date)->lockForUpdate()
            ->get(['id', 'leave_request_id', 'employee_id', 'amount']);
        $openRows = LeaveRequest::query()->toBase()
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->lockForUpdate()
            ->get(['id', 'employee_id']);

        $employeeIds = $dayRows->pluck('employee_id')->merge($openRows->pluck('employee_id'))
            ->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        if ($employeeIds === []) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        // People whose final settlement for this month is approved keep their leave exactly as it was paid (one query,
        // after the employee locks). Holidays stay company-wide; only these people's days are left alone.
        $settled = PayrollLock::settledMonths($employeeIds, [substr($date, 0, 7)]);

        if ($settled !== []) {
            $dayRows = $dayRows->reject(fn (object $row): bool => isset($settled[(int) $row->employee_id]))->values();
            $openRows = $openRows->reject(fn (object $row): bool => isset($settled[(int) $row->employee_id]))->values();
        }

        $approvedIds = $dayRows->pluck('leave_request_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $requests = LeaveRequest::query()
            ->whereIn('id', [...$approvedIds, ...$openRows->pluck('id')->map(fn ($id): int => (int) $id)->all()])
            ->with('type:id,name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($requests as $request) {
            $request->setRelation('employee', $employees->get($request->employee_id));
        }

        $approved = $requests->filter(fn (LeaveRequest $r): bool => $r->status === 'approved')->keyBy('id');

        return [
            ...$this->refundApproved($approved->all(), $dayRows->all(), $date, $holidayName, $by),
            ...$this->recountOpen(array_values($requests->filter(fn (LeaveRequest $r): bool => $r->isOpen())->all()), $date, $holidayName, $by),
        ];
    }

    /**
     * @param  array<int, LeaveRequest>  $approved  approved, locked, by id
     * @param  array<int, \stdClass>  $dayRows  the leave days on $date (id, leave_request_id, amount)
     * @return list<array{request: LeaveRequest, outcome: 'refunded'|'cancelled', refunded: float, manual: list<string>}>
     */
    private function refundApproved(array $approved, array $dayRows, string $date, string $holidayName, User $by): array
    {
        $ids = [];
        $halvesByRequest = [];

        foreach ($dayRows as $row) {
            $requestId = (int) $row->leave_request_id;

            if (! isset($approved[$requestId])) {
                continue; // a leave day of a request that is not approved (none should exist): left alone
            }

            $ids[] = (int) $row->id;
            $halvesByRequest[$requestId] = ($halvesByRequest[$requestId] ?? 0) + LeaveDays::halves($row->amount);
        }

        if ($ids === []) {
            return [];
        }

        // One delete for everybody; the date's attendance rows are flagged in one statement (the controller flags the
        // date too; a second call finds nothing left to flag).
        LeaveDay::query()->whereIn('id', $ids)->delete();
        RebuildMarks::forDate($date);

        // Hand-corrected attendance rows on the date keep their leave status (forDate skips manual rows): one query
        // for everybody touched, so HR is told (audit entry, flash, request page).
        $manualPeople = Attendance::query()->toBase()
            ->where('date', $date)
            ->where('is_manual', true)
            ->whereIn('employee_id', array_values(array_unique(array_map(fn (LeaveRequest $r): int => (int) $r->employee_id, array_intersect_key($approved, $halvesByRequest)))))
            ->pluck('employee_id')
            ->mapWithKeys(fn ($id): array => [(int) $id => true])
            ->all();

        // What each touched request has left, in one query.
        $left = [];

        foreach (LeaveDay::query()->toBase()->whereIn('leave_request_id', array_keys($halvesByRequest))->get(['leave_request_id', 'amount']) as $row) {
            $left[(int) $row->leave_request_id] = ($left[(int) $row->leave_request_id] ?? 0) + LeaveDays::halves($row->amount);
        }

        $changed = [];

        foreach ($halvesByRequest as $id => $halves) {
            $request = $approved[$id];
            $amount = LeaveDays::fromHalves($halves);
            $none = ! isset($left[$id]);

            // A wholly cancelled request keeps the days it was approved for (as HR cancels do); balances read leave_days.
            if (! $none) {
                $request->fill(['days' => LeaveDays::fromHalves($left[$id])]);
            }

            if ($none) {
                $request->fill([
                    'status' => 'cancelled',
                    'cancelled_from' => null,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => self::CANCEL_REASON,
                ]);
            }

            $request->save();

            $manual = isset($manualPeople[(int) $request->employee_id]) ? [$date] : [];

            $this->audit('Leave day refunded (holiday)', $request, $by, [
                'date' => $date,
                'holiday' => $holidayName,
                'refunded' => $amount,
                'days' => $none ? 0 : $request->days,
                'cancelled' => $none ? true : null,
                'manual' => $manual === [] ? null : $manual,
            ]);

            $changed[] = ['request' => $request, 'outcome' => $none ? 'cancelled' : 'refunded', 'refunded' => $amount, 'manual' => $manual];
        }

        return $changed;
    }

    /**
     * @param  list<LeaveRequest>  $requests  open, locked, covering $date
     * @return list<array{request: LeaveRequest, outcome: 'withdrawn', refunded: float, manual: list<string>}> the requests cancelled here
     */
    private function recountOpen(array $requests, string $date, string $holidayName, User $by): array
    {
        if ($requests === []) {
            return [];
        }

        $plans = LeaveDays::planMany(array_map(fn (LeaveRequest $r): array => [
            $r->employee,
            $r->from_date->format('Y-m-d'),
            $r->to_date->format('Y-m-d'),
            $r->is_half_day ? $r->half : null,
        ], $requests));

        $withdrawn = [];

        foreach ($requests as $i => $request) {
            $plan = $plans[$i];
            $old = (float) $request->days;

            if ($plan->isRefused() && $plan->refusal === LeaveDays::REFUSAL_NOTHING_TO_COUNT) {
                // Every day is now an off or a holiday: cancelled by the system, like approved leave.
                $oldStatus = $request->status;
                // It keeps its days (a wholly cancelled request shows what was asked, as HR cancels do).
                $request->fill([
                    'status' => 'cancelled',
                    'cancelled_from' => null,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => self::CANCEL_REASON,
                ])->save();

                $this->audit('Leave days recounted (holiday)', $request, $by, [
                    'date' => $date,
                    'holiday' => $holidayName,
                    'old' => ['days' => $old, 'status' => $oldStatus],
                    'attributes' => ['status' => 'cancelled'],
                    'cancelled' => true,
                ]);

                $withdrawn[] = ['request' => $request, 'outcome' => 'withdrawn', 'refunded' => 0.0, 'manual' => []];

                continue;
            }

            // Refused for another reason: the approval re-check says why; the count stays.
            if ($plan->isRefused() || LeaveDays::halves($plan->total) === LeaveDays::halves($old)) {
                continue;
            }

            $request->fill(['days' => $plan->total])->save();

            $this->audit('Leave days recounted (holiday)', $request, $by, [
                'date' => $date,
                'holiday' => $holidayName,
                'old' => ['days' => $old],
                'attributes' => ['days' => $plan->total],
            ]);
        }

        return $withdrawn;
    }

    /** @param  array<string, mixed>  $properties */
    private function audit(string $description, LeaveRequest $request, User $by, array $properties): void
    {
        Audit::record('leave', $description, $request->employee, array_filter([
            'request' => $request->id,
            'type' => $request->type?->name,
            'from' => $request->from_date->format('Y-m-d'),
            'to' => $request->to_date->format('Y-m-d'),
            'half' => $request->is_half_day ? $request->half : null,
            ...$properties,
        ], fn ($v) => $v !== null), $by);
    }
}
