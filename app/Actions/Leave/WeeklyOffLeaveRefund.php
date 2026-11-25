<?php

namespace App\Actions\Leave;

use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Shift;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Leave\LeaveDays;
use DateTimeImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Weekly offs changed after leave was approved (owner ruling, phase 4 final fix round: refund automatically, like
 * holidays). Called inside the transaction of a shift save whose weekly offs changed (ShiftController::update), of a new
 * default shift (ShiftController::makeDefault, for people without a shift of their own) and of an employee's shift change
 * (UpdateEmployee).
 *
 * For each person affected, approved leave days are counted again (LeaveDays::planMany) from the first attendance day
 * that is not closed yet: the day after their last closed attendance day, at most AttendanceClock::attendanceToday()
 * (today when they have no closed day). Closed days keep their snapshot and are never touched. A leave day that no
 * longer counts (now a weekly off) goes through LeaveDayRemoval (refund by derivation, attendance re-marked, a day in a
 * locked payroll month kept, manual days reported). A request left with no day becomes cancelled ("Every day became a
 * weekly off.", keeping its days for display); otherwise it stays approved with `days` reduced. One audit entry `Leave
 * day refunded (weekly off)` per request (`removed` dates, `refunded`, `days`, `cancelled`, `manual`, `kept_locked`). A
 * request whose recount is refused for another reason (dates now outside the employment window) is left to the
 * employment trim.
 *
 * Locking (MariaDB REPEATABLE READ): the affected employee rows first (id order, locking read), then their approved
 * requests with leave days from the cutoff (id order); plain reads only after. That is the order every other leave writer
 * uses (employee, then request), so the caller's transaction should retry on a deadlock.
 *
 * Mail after the commit (mail()): `refunded` or `cancelled` to the employee's active login, with the dates as the note.
 * Re-running it changes nothing.
 */
final class WeeklyOffLeaveRefund
{
    public const CANCEL_REASON = 'Every day became a weekly off.';

    public function __construct(
        private readonly LeaveDayRemoval $removal,
        private readonly AttendanceClock $clock,
    ) {}

    /**
     * Everyone who works $shift (its own people; for the default shift also everyone without a shift of their own).
     *
     * @return list<array{request: LeaveRequest, outcome: 'refunded'|'cancelled', refunded: float, removed: list<string>, manual: list<string>}>
     */
    public function forShift(Shift $shift, User $by): array
    {
        $ids = Employee::query()
            ->where(fn ($q) => $shift->is_default
                ? $q->where('shift_id', $shift->id)->orWhereNull('shift_id')
                : $q->where('shift_id', $shift->id))
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return $this->forEmployees(array_values($ids), $by);
    }

    /**
     * People without a shift of their own (the default shift moved).
     *
     * @return list<array{request: LeaveRequest, outcome: 'refunded'|'cancelled', refunded: float, removed: list<string>, manual: list<string>}>
     */
    public function forEmployeesWithoutShift(User $by): array
    {
        $ids = Employee::query()->whereNull('shift_id')->orderBy('id')->lockForUpdate()
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $this->forEmployees(array_values($ids), $by);
    }

    /**
     * @param  list<int>  $employeeIds
     * @return list<array{request: LeaveRequest, outcome: 'refunded'|'cancelled', refunded: float, removed: list<string>, manual: list<string>}>
     */
    public function forEmployees(array $employeeIds, User $by): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        if ($employees->isEmpty()) {
            return [];
        }

        $today = $this->clock->attendanceToday();
        $cutoffs = $this->cutoffs(array_values(array_map(fn ($id): int => (int) $id, $employees->keys()->all())), $today);

        if ($cutoffs === []) {
            return [];
        }

        $earliest = min($cutoffs);

        // Approved requests with a leave day on or after the earliest cutoff (narrowed per person below).
        $requestIds = LeaveDay::query()->toBase()
            ->whereIn('employee_id', array_keys($cutoffs))
            ->where('date', '>=', $earliest)
            ->distinct()
            ->pluck('leave_request_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($requestIds === []) {
            return [];
        }

        $requests = LeaveRequest::query()
            ->whereIn('id', $requestIds)
            ->where('status', 'approved')
            ->with('type:id,name')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($requests->isEmpty()) {
            return [];
        }

        // Each request's leave days from its person's cutoff.
        $daysByRequest = [];
        foreach (LeaveDay::query()->toBase()
            ->whereIn('leave_request_id', $requests->modelKeys())
            ->where('date', '>=', $earliest)
            ->orderBy('date')
            ->get(['leave_request_id', 'employee_id', 'date']) as $row) {
            $date = substr((string) $row->date, 0, 10);

            if ($date >= $cutoffs[(int) $row->employee_id]) {
                $daysByRequest[(int) $row->leave_request_id][$date] = true;
            }
        }

        $asks = [];
        $order = [];

        foreach ($requests as $request) {
            if (! isset($daysByRequest[$request->id])) {
                continue;
            }

            $employee = $employees->get($request->employee_id);
            $request->setRelation('employee', $employee);
            $from = max($request->from_date->format('Y-m-d'), $cutoffs[(int) $request->employee_id]);
            $asks[] = [$employee, $from, $request->to_date->format('Y-m-d'), $request->is_half_day ? $request->half : null];
            $order[] = $request;
        }

        $plans = LeaveDays::planMany($asks);
        $changed = [];

        foreach ($order as $i => $request) {
            $plan = $plans[$i];
            $held = array_keys($daysByRequest[$request->id]);

            if ($plan->isRefused()) {
                // Only "now all weekly offs (or holidays)" refunds everything; any other refusal (outside the
                // employment window) is the employment trim's business.
                if ($plan->refusal !== LeaveDays::REFUSAL_NOTHING_TO_COUNT) {
                    continue;
                }

                $gone = $held;
            } else {
                $gone = array_values(array_diff($held, $plan->dates()));
            }

            if ($gone === []) {
                continue;
            }

            $result = $this->removal->remove($request, $gone);

            if ($result['removed'] === []) {
                continue; // every such day lies in a locked payroll month
            }

            $changed[] = $this->settle($request, $result, $by);
        }

        return $changed;
    }

    /**
     * After the commit: `refunded` (some days went back) or `cancelled` (every day did) to each employee's active login;
     * the note names the dates. A mail that cannot be queued is reported, never undoes the change.
     *
     * @param  list<array{request: LeaveRequest, outcome: string, refunded: float, removed: list<string>, manual: list<string>}>  $changed
     */
    public function mail(array $changed): void
    {
        if ($changed === []) {
            return;
        }

        $users = User::query()
            ->whereIn('employee_id', array_map(fn (array $c): int => (int) $c['request']->employee_id, $changed))
            ->where('is_active', true)
            ->get()
            ->keyBy('employee_id');

        foreach ($changed as $c) {
            $user = $users->get($c['request']->employee_id);

            if ($user === null) {
                continue;
            }

            try {
                Mail::to($user)->queue(new LeaveRequestDecided($c['request'], $user->name, $c['outcome'], self::mailNote($c)));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * The flash sentence for the person who saved, or "" when nothing changed: " Leave refunded for weekly offs: Bilal
     * Ahmed, Sara Khan · 3 days went back to their balance." plus the hand-corrected days to check.
     *
     * @param  list<array{request: LeaveRequest, outcome: string, refunded: float, removed: list<string>, manual: list<string>}>  $changed
     */
    public static function note(array $changed): string
    {
        if ($changed === []) {
            return '';
        }

        $people = [];
        $halves = 0;
        $manual = '';

        foreach ($changed as $c) {
            $name = (string) $c['request']->employee?->full_name;
            $people[(int) $c['request']->employee_id] = $name;
            $halves += LeaveDays::halves($c['refunded']);
            $manual .= LeaveDayRemoval::manualNote($c['manual'], $name);
        }

        $names = array_values($people);
        sort($names);
        $days = rtrim(rtrim(number_format(LeaveDays::fromHalves($halves), 1, '.', ''), '0'), '.');

        return sprintf(' Leave refunded for the new weekly offs: %s · %s %s went back to their balance.',
            implode(', ', $names), $days, $halves === 2 ? 'day' : 'days').$manual;
    }

    /**
     * Per person: the first attendance day not closed yet. The day after their last closed day up to today, at most
     * today; today when they have none. One grouped query.
     *
     * @param  list<int>  $employeeIds
     * @return array<int, string> employee id => "Y-m-d"
     */
    private function cutoffs(array $employeeIds, string $today): array
    {
        $lastClosed = Attendance::query()->toBase()
            ->whereIn('employee_id', $employeeIds)
            ->where('closed', true)
            ->where('date', '<=', $today)
            ->groupBy('employee_id')
            ->selectRaw('employee_id, max(date) as last_day')
            ->pluck('last_day', 'employee_id');

        $cutoffs = [];

        foreach ($employeeIds as $id) {
            $last = $lastClosed[$id] ?? null;
            $cutoffs[$id] = $last === null
                ? $today
                : min($today, (new DateTimeImmutable(substr((string) $last, 0, 10)))->modify('+1 day')->format('Y-m-d'));
        }

        return $cutoffs;
    }

    /**
     * Sets the request's days or cancels it, audits, and describes the change.
     *
     * @param  array{removed: list<string>, amount: float, kept_locked: list<string>, manual: list<string>}  $result
     * @return array{request: LeaveRequest, outcome: 'refunded'|'cancelled', refunded: float, removed: list<string>, manual: list<string>}
     */
    private function settle(LeaveRequest $request, array $result, User $by): array
    {
        $left = 0;
        foreach (LeaveDay::query()->toBase()->where('leave_request_id', $request->id)->pluck('amount') as $amount) {
            $left += LeaveDays::halves($amount);
        }

        $none = $left === 0; // every leave day is worth 0.5 or 1

        if ($none) {
            // A wholly cancelled request keeps the days it was approved for (as HR cancels do).
            $request->fill([
                'status' => 'cancelled',
                'cancelled_from' => null,
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => self::CANCEL_REASON,
            ]);
        } else {
            $request->fill(['days' => LeaveDays::fromHalves($left)]);
        }

        $request->save();

        Audit::record('leave', 'Leave day refunded (weekly off)', $request->employee, array_filter([
            'request' => $request->id,
            'type' => $request->type?->name,
            'from' => $request->from_date->format('Y-m-d'),
            'to' => $request->to_date->format('Y-m-d'),
            'half' => $request->is_half_day ? $request->half : null,
            'removed' => $result['removed'],
            'refunded' => $result['amount'],
            'days' => $none ? 0 : $request->days,
            'cancelled' => $none ? true : null,
            'kept_locked' => $result['kept_locked'] === [] ? null : $result['kept_locked'],
            'manual' => $result['manual'] === [] ? null : $result['manual'],
            'automatic' => true,
        ], fn ($v) => $v !== null), $by);

        return [
            'request' => $request,
            'outcome' => $none ? 'cancelled' : 'refunded',
            'refunded' => $result['amount'],
            'removed' => $result['removed'],
            'manual' => $result['manual'],
        ];
    }

    /** @param  array{outcome: string, removed: list<string>}  $change */
    private static function mailNote(array $change): string
    {
        $labels = array_map(fn (string $d): string => (new DateTimeImmutable($d))->format('D j M Y'), $change['removed']);
        $list = count($labels) === 1 ? $labels[0] : implode(', ', array_slice($labels, 0, -1)).' and '.end($labels);
        $note = 'Your weekly offs changed: '.$list.(count($labels) === 1 ? ' is now a weekly off.' : ' are now weekly offs.');

        return $change['outcome'] === 'cancelled' ? "{$note} ".self::CANCEL_REASON : $note;
    }
}
