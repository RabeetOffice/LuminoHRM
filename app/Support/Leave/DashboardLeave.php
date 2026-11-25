<?php

namespace App\Support\Leave;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard's leave figures for one viewer, from a fixed number of queries whatever the number of people:
 *
 *   pending()       the count of the "Waiting for me" tab of Leave → Requests (WaitingForMe::count): HR / Admin see
 *                   final-stage requests they may decide; a Manager their reports' requests waiting at their step.
 *   onLeaveToday()  people with an approved leave day on AttendanceClock::attendanceToday() (full or half), leavers
 *                   excluded: the people (1) and their leave days (LeaveLedger::daysOn, 1, only when someone is off).
 *   byStatus()      this month's requests (from_date in the month of attendanceToday()) by status: one grouped query.
 *   mine()          the viewer's own balance lines for the current leave year (LeaveBalances::lines, fixed queries).
 *
 * The people are those LeaveRouting::scope lets the viewer see: everyone with leave.approve-all, else EmployeeAccess
 * (own record and direct reports). The controller sends pending / onLeaveToday / byStatus only to leave.approve-all or
 * leave.approve-team. Nothing here reads a request's reason or attachment.
 */
final class DashboardLeave
{
    /** The statuses counted on the "Leave requests this month" chart, in display order. */
    public const STATUSES = ['pending', 'manager_approved', 'approved', 'rejected', 'cancelled'];

    private readonly string $today;

    public function __construct(private readonly AttendanceClock $clock, private readonly User $viewer)
    {
        $this->today = $clock->attendanceToday();
    }

    /** @return array{count: int} */
    public function pending(): array
    {
        // The same rule as the Leave -> Requests "Waiting for me" tab (HR / Admin: final-stage requests only).
        return ['count' => WaitingForMe::count($this->viewer)];
    }

    /**
     * Who in the viewer's scope is on approved leave today, by name: a leave day on the attendance day (an "am" and a
     * "pm" half together are a full day). Leavers without a last working day, and people past their last working day,
     * are left out.
     *
     * @return list<array{id: int, name: string, type: string, colour: string, half: string, photo_url: string|null}>
     */
    public function onLeaveToday(): array
    {
        $today = $this->today;

        $people = $this->people()
            ->where(fn (Builder $w) => $w->whereNull('last_working_day')->orWhere('last_working_day', '>=', $today))
            ->tap(fn (Builder $q) => Leavers::exceptWithoutLastDay($q))
            ->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('leave_days')
                ->whereColumn('leave_days.employee_id', 'employees.id')
                ->where('leave_days.date', $today))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get(['id', 'first_name', 'last_name', 'photo_path', 'updated_at']);

        if ($people->isEmpty()) {
            return [];
        }

        $days = LeaveLedger::daysOn(array_values($people->map(fn (Employee $e): int => (int) $e->id)->all()), $today);
        $list = [];

        foreach ($people as $person) {
            $day = $days[(int) $person->id] ?? null;

            if ($day === null) {
                continue;
            }

            $list[] = [
                'id' => (int) $person->id,
                'name' => $person->full_name,
                'type' => $day['type'],
                'colour' => $day['colour'],
                'half' => $day['half'],
                'photo_url' => EmployeeFiles::photoUrl($person),
            ];
        }

        return $list;
    }

    /**
     * This month's requests in the viewer's scope by status (a request belongs to the month its first date is in).
     *
     * @return array{month: string, label: string, counts: array<string, int>}
     */
    public function byStatus(): array
    {
        $month = substr($this->today, 0, 7);
        $first = new DateTimeImmutable($month.'-01 00:00:00', new DateTimeZone('UTC'));
        $next = $first->modify('+1 month')->format('Y-m-d');

        $grouped = (new LeaveRouting)->scope(LeaveRequest::query(), $this->viewer)
            ->where('from_date', '>=', $first->format('Y-m-d'))
            // "<" the next month's first day: SQLite may hold the date as "Y-m-d 00:00:00".
            ->where('from_date', '<', $next)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (self::STATUSES as $status) {
            $counts[$status] = (int) ($grouped[$status] ?? 0);
        }

        return ['month' => $month, 'label' => $first->format('F Y'), 'counts' => $counts];
    }

    /**
     * The viewer's own balance for the current leave year: every line as BalanceLine::toArray(), and the total
     * available paid days (unpaid lines are never limited and are left out of the total). Null when the account's
     * employee record is gone.
     *
     * @return array{year: int, lines: list<array<string, mixed>>, total_available: float, pending_total: float}|null
     */
    public static function mine(LeaveBalances $balances, AttendanceClock $clock, int $employeeId): ?array
    {
        // The whole record: the balance service reads the joining date, last working day and shift.
        $employee = Employee::query()->find($employeeId);

        if ($employee === null) {
            return null;
        }

        $year = (int) substr($clock->attendanceToday(), 0, 4);
        $lines = $balances->lines($employee, $year);

        return [
            'year' => $year,
            'lines' => array_map(fn (BalanceLine $line) => $line->toArray(), $lines),
            'total_available' => self::totalAvailable($lines),
            'pending_total' => self::pendingTotal($employeeId),
        ];
    }

    /**
     * Days still waiting for approval across ALL years (a request dated next January counts), every leave type
     * including unpaid: one query over the employee's open requests.
     */
    private static function pendingTotal(int $employeeId): float
    {
        $halves = 0;

        foreach (LeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->toBase()
            ->pluck('days') as $days) {
            $halves += LeaveDays::halves($days);
        }

        return LeaveDays::fromHalves($halves);
    }

    /**
     * The sum of available days over the paid lines, each line floored at zero: an overdrawn type is never netted
     * against the others (exact to the half day). Unpaid lines hold INF and never count.
     *
     * @param  list<BalanceLine>  $lines
     */
    public static function totalAvailable(array $lines): float
    {
        $halves = 0;

        foreach ($lines as $line) {
            if ($line->isPaid) {
                $halves += max(0, LeaveDays::halves($line->available));
            }
        }

        return LeaveDays::fromHalves($halves);
    }

    /** @return Builder<Employee> */
    private function people(): Builder
    {
        $query = Employee::query();

        return $this->viewer->can('leave.approve-all') ? $query : (new EmployeeAccess($this->viewer))->scope($query);
    }
}
