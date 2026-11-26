<?php

namespace App\Actions\Leave;

use App\Models\Attendance;
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
 *
 * Manual attendance days (is_manual) among the removed dates are not re-marked (manual attendance wins), so they keep
 * the leave status HR gave them. They come back as `manual` (one query) so the caller records them in its audit entry
 * and tells HR (manualNote() for the flash; the request page lists them from the audit entry).
 */
final class LeaveDayRemoval
{
    /**
     * @param  LeaveRequest  $locked  the request, locked by the caller's transaction
     * @param  list<string>  $dates  "Y-m-d" dates whose leave days of this request go (dates without one are ignored)
     * @param  string|null  $why  unused (kept optional for older callers; the caller's audit entry holds the reason)
     * @param  User|null  $by  unused (kept optional for older callers; the caller's audit entry names who)
     * @return array{removed: list<string>, amount: float, kept_locked: list<string>, manual: list<string>} manual = the
     *                                                                                                      removed dates on
     *                                                                                                      a manual row
     *
     * @throws InvalidArgumentException a malformed date
     */
    public function remove(LeaveRequest $locked, array $dates, ?string $why = null, ?User $by = null): array
    {
        foreach ($dates as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException("Not a date [{$date}].");
            }
        }

        if ($dates === []) {
            return ['removed' => [], 'amount' => 0.0, 'kept_locked' => [], 'manual' => []];
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
        // One read for every month these days touch.
        // Locked months, and this person's months frozen by an approved final settlement.
        $lockedMonths = array_flip(PayrollLock::lockedMonthsFor(
            (int) $locked->employee_id,
            $rows->map(fn ($row) => substr((string) $row->date, 0, 7))->unique()->values()->all(),
        ));

        foreach ($rows as $row) {
            $date = substr((string) $row->date, 0, 10);

            if (isset($lockedMonths[substr($date, 0, 7)])) {
                $kept[$date] = true;

                continue;
            }

            $ids[] = (int) $row->id;
            $removed[$date] = true;
            $halves += LeaveDays::halves($row->amount);
        }

        $manual = [];

        if ($ids !== []) {
            LeaveDay::query()->whereIn('id', $ids)->delete();
            RebuildMarks::forLeaveDays((int) $locked->employee_id, array_keys($removed));
            $manual = self::manualDates((int) $locked->employee_id, array_keys($removed));
        }

        return [
            'removed' => array_keys($removed),
            'amount' => LeaveDays::fromHalves($halves),
            'kept_locked' => array_keys($kept),
            'manual' => $manual,
        ];
    }

    /**
     * The dates among $dates whose attendance row of this employee is a manual (hand-corrected) one, ascending. One
     * query.
     *
     * @param  list<string>  $dates  "Y-m-d"
     * @return list<string>
     */
    public static function manualDates(int $employeeId, array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $found = Attendance::query()->toBase()
            ->where('employee_id', $employeeId)
            ->whereIn('date', array_values(array_unique($dates)))
            ->where('is_manual', true)
            ->pluck('date')
            ->map(fn ($d): string => substr((string) $d, 0, 10))
            ->unique()->sort()->values()->all();

        return array_values($found);
    }

    /**
     * The flash sentence for manual days whose leave was removed ("" when there are none): " 3 Dec was corrected by hand
     * — check it.", " 3 Dec and 4 Dec were corrected by hand — check them." With $name: " Bilal Ahmed's 3 Dec was …".
     *
     * @param  list<string>  $dates  "Y-m-d"
     */
    public static function manualNote(array $dates, ?string $name = null): string
    {
        if ($dates === []) {
            return '';
        }

        $labels = array_map(fn (string $d): string => (new \DateTimeImmutable($d))->format('j M'), $dates);
        $list = count($labels) === 1 ? $labels[0] : implode(', ', array_slice($labels, 0, -1)).' and '.end($labels);
        $many = count($labels) > 1;

        return ' '.($name !== null ? "{$name}'s " : '').$list.($many ? ' were' : ' was')
            .' corrected by hand on the Daily screen and still shows leave — check '.($many ? 'them.' : 'it.');
    }
}
