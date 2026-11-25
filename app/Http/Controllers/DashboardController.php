<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\DashboardAttendance;
use App\Support\Attendance\MonthGrid;
use App\Support\EmployeeAccess;
use App\Support\EmployeeFiles;
use App\Support\Leave\DashboardLeave;
use App\Support\Leave\LeaveBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The dashboard. Every figure is worked out here, for the viewer, and a figure the viewer may not have is ABSENT from
 * the props (not zero, not empty):
 *
 *   employeeCount  {scope: 'company'|'team', total, probation}  for employees.view-all / employees.view-team only.
 *                  Active + probation employees inside EmployeeAccess::scope (Admin/HR: the company; a Manager:
 *                  their own record + direct reports). A plain Employee gets no key and no tile.
 *   birthdays      [{id, name, designation, photo_url}]  for employees.view-all only (the date of birth is
 *                  restricted). Active + probation people whose birthday is today in the company's display zone;
 *                  29 February birthdays show on 28 February in other years. Never the date or the year.
 *   attendanceToday, lateToday, attendanceTrend
 *                  for attendance.view-all / attendance.view-team only, over the same people as employeeCount
 *                  (App\Support\Attendance\DashboardAttendance: today's counts, today's late people, the last 30 closed
 *                  days). Nobody outside the scope is counted or named.
 *   myAttendance   for everyone else with an employee record: their own month so far (MonthGrid totals) and today.
 *   attendanceDay  {date, calendar_date, differs, cutoff}  with either of the above: the attendance day the "today"
 *                  figures belong to. After midnight and before the day cutoff it is the previous calendar day
 *                  (differs = true), and the page labels the figures with it.
 *   pendingLeaves, onLeaveToday, leaveByStatus
 *                  for leave.approve-all / leave.approve-team only (App\Support\Leave\DashboardLeave): open requests
 *                  the viewer may decide now (the "Waiting for me" count), who is on approved leave on the attendance
 *                  day (names, type, full or half; leavers left out), and this month's requests by status. Everyone for
 *                  leave.approve-all, else own record + direct reports. Never a reason or an attachment.
 *   hasEmployee    bool: the account is linked to an employee record.
 *   myLeave        {year, lines, total_available}  for everyone with an employee record who lacks payroll.view (the
 *                  "Leave Balance" tile replaces "Payroll This Month"): their own balance lines for the current leave
 *                  year; total_available sums the paid lines only.
 */
class DashboardController extends Controller
{
    /** The statuses counted as "employed". */
    public const COUNTED = ['active', 'probation'];

    public function index(Request $request, AttendanceClock $clock, LeaveBalances $balances): Response
    {
        $user = $request->user();
        $access = new EmployeeAccess($user);
        // Whether the account is linked to an employee record (the page words its empty cards by it).
        $props = ['hasEmployee' => $user->employee_id !== null && Employee::query()->whereKey($user->employee_id)->exists()];

        if ($user->canAny(['employees.view-all', 'employees.view-team'])) {
            $props['employeeCount'] = $this->employeeCount($access, $user->can('employees.view-all'));
        }

        if ($user->can('employees.view-all')) {
            $props['birthdays'] = $this->birthdays($access);
        }

        if ($user->canAny(['attendance.view-all', 'attendance.view-team'])) {
            // The same people as the employee count: EmployeeAccess::scope (Admin/HR: everyone; Manager: own + reports).
            // Whoever sees every attendance record also sees every punch (even of an unknown machine user).
            $attendance = new DashboardAttendance($clock, $access->scope(Employee::query()), $user->can('attendance.view-all'));
            $props['attendanceDay'] = DashboardAttendance::day($clock);
            $props['attendanceToday'] = $attendance->today();
            $props['lateToday'] = $attendance->late();
            $props['attendanceTrend'] = $attendance->trend();
        } elseif ($user->employee_id !== null) {
            $props['attendanceDay'] = DashboardAttendance::day($clock);
            $props['myAttendance'] = $this->myAttendance($clock, (int) $user->employee_id);
        }

        if ($user->canAny(['leave.approve-all', 'leave.approve-team'])) {
            $leave = new DashboardLeave($clock, $user);
            $props['pendingLeaves'] = $leave->pending();
            $props['onLeaveToday'] = $leave->onLeaveToday();
            $props['leaveByStatus'] = $leave->byStatus();
        }

        if ($user->employee_id !== null && ! $user->can('payroll.view')) {
            $mine = DashboardLeave::mine($balances, $clock, (int) $user->employee_id);

            if ($mine !== null) {
                $props['myLeave'] = $mine;
            }
        }

        return Inertia::render('dashboard', $props);
    }

    /**
     * The viewer's own month so far (MonthGrid totals: never pending, nothing outside the employment window or after
     * today), and today's status. Null when the account's employee record is gone.
     *
     * @return array{month: string, label: string, totals: array<string, int|float>, today: array{status: string|null, clock_in: string|null, clock_out: string|null}|null}|null
     */
    private function myAttendance(AttendanceClock $clock, int $employeeId): ?array
    {
        $employee = Employee::query()->find($employeeId, ['id', 'joining_date', 'last_working_day']);

        if ($employee === null) {
            return null;
        }

        $grid = MonthGrid::for(MonthGrid::currentMonth($clock), $clock);

        if ($grid === null) {
            return null;
        }

        $row = $grid->rows([$employee])[$employee->id];
        $todayIndex = (int) substr($clock->attendanceToday(), 8, 2) - 1;
        $cell = $row['cells'][$todayIndex] ?? null;

        return [
            'month' => $grid->month,
            'label' => $grid->label(),
            'totals' => $row['totals'],
            'today' => $cell === null ? null : [
                'status' => array_search($cell[0], MonthGrid::CODES, true) ?: null,
                'clock_in' => is_string($cell[2]) ? $cell[2] : null,
                'clock_out' => is_string($cell[3]) ? $cell[3] : null,
            ],
        ];
    }

    /** @return array{scope: string, total: int, probation: int} */
    private function employeeCount(EmployeeAccess $access, bool $company): array
    {
        $byStatus = $access->scope(Employee::query())
            ->whereIn('status', self::COUNTED)
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return [
            'scope' => $company ? 'company' : 'team',
            'total' => (int) $byStatus->sum(),
            'probation' => (int) ($byStatus['probation'] ?? 0),
        ];
    }

    /** @return list<array{id: int, name: string, designation: string|null, photo_url: string|null}> */
    private function birthdays(EmployeeAccess $access): array
    {
        $today = Date::now()->setTimezone($this->displayZone());
        $month = (int) $today->format('n');
        $day = (int) $today->format('j');
        // Born on 29 February: celebrated on 28 February when this year has no 29th.
        $leaplings = $month === 2 && $day === 28 && ! $today->isLeapYear();

        $people = $access->scope(Employee::query())
            ->whereIn('status', self::COUNTED)
            ->whereNotNull('dob')
            ->where(function (Builder $q) use ($month, $day, $leaplings) {
                $q->where(fn (Builder $w) => $w->whereMonth('dob', $month)->whereDay('dob', $day));

                if ($leaplings) {
                    $q->orWhere(fn (Builder $w) => $w->whereMonth('dob', 2)->whereDay('dob', 29));
                }
            })
            // dob is only filtered on; it is never selected, so it cannot slip into the props.
            ->select(['id', 'first_name', 'last_name', 'designation_id', 'photo_path', 'updated_at'])
            ->with('designation:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->orderBy('id')
            ->get();

        return array_values($people->map(fn (Employee $e) => [
            'id' => $e->id,
            'name' => $e->full_name,
            'designation' => $e->designation?->name,
            'photo_url' => EmployeeFiles::photoUrl($e),
        ])->all());
    }

    /** The company display timezone, or the app timezone when the setting is unusable. */
    private function displayZone(): string
    {
        $zone = (string) settings('company.timezone', config('app.timezone'));

        try {
            new \DateTimeZone($zone);
        } catch (\Throwable) {
            return (string) config('app.timezone');
        }

        return $zone;
    }
}
