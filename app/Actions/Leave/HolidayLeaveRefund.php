<?php

namespace App\Actions\Leave;

use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A holiday was added on a date, or moved onto it (plan open decision 14, design 5.8). Called by HolidayController
 * inside the holiday's transaction, after the holiday row is written, so the day counting below already sees it.
 *
 * - Approved leave days on that date are removed through LeaveDayRemoval (the balance is refunded by derivation; the
 *   attendance day is re-marked so it becomes Holiday). A request left with no day becomes cancelled ("Every day became
 *   a holiday."); otherwise it stays approved with `days` reduced. One audit entry `Leave day refunded (holiday)` per
 *   request (date, amount, holiday).
 * - Open requests covering the date get `days` counted again (LeaveDays::planMany), so My Leaves and the pending figure
 *   stop holding the holiday. A request whose dates are now all offs or holidays keeps its status with `days` = 0: the
 *   approver is refused at approval ("Nothing left to approve", Task 6) and the requester can cancel it. Audited
 *   `Leave days recounted (holiday)` (old / new days) when the count changes.
 * - A date in a locked payroll month (PayrollLock) is left alone entirely.
 * - Removing a holiday adds nothing back (the controller does not call this on delete).
 *
 * Locking (MariaDB REPEATABLE READ): the affected rows are first found with LOCKING reads (the leave days on the date
 * and the open requests covering it; locking reads do not fix the snapshot), then every affected employee row is locked
 * in id order, then the request rows; plain reads come only after. Other leave writers lock the employee first, so a
 * clash can deadlock: the controller runs the transaction with retries. A fixed number of queries finds the affected
 * set; each affected request then costs a small constant number (LeaveDayRemoval, its save and audit entry), and
 * people without leave on that date cost nothing. Re-running it changes nothing (the days are gone, the count holds).
 */
final class HolidayLeaveRefund
{
    public function __construct(private readonly LeaveDayRemoval $removal) {}

    /**
     * @param  string  $date  "Y-m-d", the holiday's (new) date
     * @return list<LeaveRequest> the approved requests that lost a day (employee relation set), for the refund mail
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
        $dayRows = LeaveDay::query()->toBase()->where('date', $date)->lockForUpdate()->get(['leave_request_id', 'employee_id']);
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

        $touched = $this->refundApproved($requests->filter(fn (LeaveRequest $r): bool => $r->status === 'approved'), $date, $holidayName, $by);
        $this->recountOpen(array_values($requests->filter(fn (LeaveRequest $r): bool => $r->isOpen())->all()), $date, $holidayName, $by);

        return $touched;
    }

    /**
     * @param  iterable<LeaveRequest>  $requests  approved, locked, with a leave day on $date
     * @return list<LeaveRequest>
     */
    private function refundApproved(iterable $requests, string $date, string $holidayName, User $by): array
    {
        $removed = [];

        foreach ($requests as $request) {
            $result = $this->removal->remove($request, [$date], "Holiday {$holidayName} added", $by);

            if ($result['removed'] !== []) {
                $removed[$request->id] = [$request, $result['amount']];
            }
        }

        if ($removed === []) {
            return [];
        }

        // What each touched request has left, in one query.
        $left = [];

        foreach (LeaveDay::query()->toBase()->whereIn('leave_request_id', array_keys($removed))->get(['leave_request_id', 'amount']) as $row) {
            $left[(int) $row->leave_request_id] = ($left[(int) $row->leave_request_id] ?? 0) + LeaveDays::halves($row->amount);
        }

        $touched = [];

        foreach ($removed as $id => [$request, $amount]) {
            $none = ! isset($left[$id]);
            $request->fill(['days' => LeaveDays::fromHalves($left[$id] ?? 0)]);

            if ($none) {
                $request->fill([
                    'status' => 'cancelled',
                    'cancelled_from' => null,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'Every day became a holiday.',
                ]);
            }

            $request->save();

            $this->audit('Leave day refunded (holiday)', $request, $by, [
                'date' => $date,
                'holiday' => $holidayName,
                'refunded' => $amount,
                'days' => $request->days,
                'cancelled' => $none ? true : null,
            ]);

            $touched[] = $request;
        }

        return $touched;
    }

    /** @param  list<LeaveRequest>  $requests  open, locked, covering $date */
    private function recountOpen(array $requests, string $date, string $holidayName, User $by): void
    {
        if ($requests === []) {
            return;
        }

        $plans = LeaveDays::planMany(array_map(fn (LeaveRequest $r): array => [
            $r->employee,
            $r->from_date->format('Y-m-d'),
            $r->to_date->format('Y-m-d'),
            $r->is_half_day ? $r->half : null,
        ], $requests));

        foreach ($requests as $i => $request) {
            $plan = $plans[$i];

            $new = match (true) {
                ! $plan->isRefused() => $plan->total,
                $plan->refusal === LeaveDays::REFUSAL_NOTHING_TO_COUNT => 0.0,
                default => null, // refused for another reason: the approval re-check says why; the count stays
            };

            $old = (float) $request->days;

            if ($new === null || LeaveDays::halves($new) === LeaveDays::halves($old)) {
                continue;
            }

            $request->fill(['days' => $new])->save();

            $this->audit('Leave days recounted (holiday)', $request, $by, [
                'date' => $date,
                'holiday' => $holidayName,
                'old' => ['days' => $old],
                'attributes' => ['days' => $new],
            ]);
        }
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
