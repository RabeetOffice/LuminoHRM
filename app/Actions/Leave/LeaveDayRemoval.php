<?php

namespace App\Actions\Leave;

use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\RebuildMarks;
use App\Support\Leave\LeaveDays;
use App\Support\PayrollLock;
use InvalidArgumentException;

/**
 * The one way leave days leave the ledger after approval: HR cancelling approved leave (LeaveDecisions::cancelApproved),
 * a holiday added on a leave day and trimming leave to the employment window (Task 8).
 *
 * Call it inside a transaction with the request row already locked (lockForUpdate). It deletes the request's leave days
 * on the given dates, except a day in a locked payroll month (PayrollLock), which stays and is reported back; it marks
 * the removed dates for an attendance rebuild (RebuildMarks::forLeaveDays: days up to today, manual rows untouched,
 * closed rows re-decided from their punches and snapshot). It changes nothing else: the caller sets the request's
 * status, days and cancel fields and writes the audit entry. The balance needs no write: it is derived from leave days.
 */
final class LeaveDayRemoval
{
    /**
     * @param  LeaveRequest  $locked  the request, locked by the caller's transaction
     * @param  list<string>  $dates  "Y-m-d" dates whose leave days of this request go (dates without one are ignored)
     * @param  string  $why  the reason, for the caller's audit entry (kept in the signature so every caller states one)
     * @param  User|null  $by  who removes them (null: the system), for the caller's audit entry
     * @return array{removed: list<string>, amount: float, kept_locked: list<string>}
     *
     * @throws InvalidArgumentException a malformed date
     */
    public function remove(LeaveRequest $locked, array $dates, string $why, ?User $by): array
    {
        foreach ($dates as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException("Not a date [{$date}].");
            }
        }

        if ($dates === []) {
            return ['removed' => [], 'amount' => 0.0, 'kept_locked' => []];
        }

        $rows = LeaveDay::query()->toBase()
            ->where('leave_request_id', $locked->id)
            ->whereIn('date', array_values(array_unique($dates)))
            ->orderBy('date')
            ->get(['id', 'date', 'amount']);

        $ids = [];
        $removed = [];
        $kept = [];
        $halves = 0;

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);

            if (PayrollLock::isLocked(substr($date, 0, 7))) {
                $kept[$date] = true;

                continue;
            }

            $ids[] = (int) $row->id;
            $removed[$date] = true;
            $halves += LeaveDays::halves($row->amount);
        }

        if ($ids !== []) {
            LeaveDay::query()->whereIn('id', $ids)->delete();
            RebuildMarks::forLeaveDays((int) $locked->employee_id, array_keys($removed));
        }

        return [
            'removed' => array_keys($removed),
            'amount' => LeaveDays::fromHalves($halves),
            'kept_locked' => array_keys($kept),
        ];
    }
}
