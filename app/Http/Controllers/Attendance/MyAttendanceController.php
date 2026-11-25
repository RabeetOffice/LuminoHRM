<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\CorrectionEligibility;
use App\Support\Attendance\MonthGrid;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * My Attendance: the signed-in person's OWN month, for every role.
 *
 *   GET /my/attendance?month=YYYY-MM
 *
 * The record is the account's own employee_id; nothing in the URL names a person, so nobody can ask for someone else.
 * A user with no employee record gets the page with employee = null (a friendly empty state), never another record.
 * A missing month means the current one (the month of AttendanceClock::attendanceToday()); a malformed one or a month
 * that has not started is refused back to the current month with a message. A month before joining is shown empty.
 *
 * The days come from MonthGrid, the same reader as the monthly grid, so a day and the totals look the same on both:
 * a day outside the employment window (before joining, before attendance.start_date, after the last working day, after
 * today) is blank whatever is stored; pending (null status) is shown as "–" and never counted.
 *
 *   present-equivalent = present + late + 0.5 x half day;  working days = present + late + half day + absent
 *
 * month.previous is null at the month of max(joining date, attendance start date) and before it (and always for a
 * user with no employee record), so paging back never runs past the first month that can hold a counted day.
 *
 * Props: employee {name, code} | null, month {value, label, previous, next, current}, days, cells, window, totals,
 * today ("Y-m-d"), correctable (dates where "Request correction" is offered).
 */
class MyAttendanceController extends Controller
{
    public function __construct(private readonly CorrectionEligibility $eligibility) {}

    public function show(Request $request, AttendanceClock $clock): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $current = MonthGrid::currentMonth($clock);
        $asked = $request->query('month');

        $grid = $asked === null || $asked === '' ? MonthGrid::for($current, $clock) : MonthGrid::for($asked, $clock);

        if ($grid === null) {
            return redirect()->route('my.attendance')->with('error', 'That is not a valid month.');
        }

        if ($grid->isFuture()) {
            return redirect()->route('my.attendance')->with('error', 'That month has not started yet.');
        }

        $employee = $user->employee_id === null
            ? null
            : Employee::query()->find($user->employee_id, ['id', 'employee_code', 'first_name', 'last_name', 'joining_date', 'last_working_day']);

        // Paging back stops at the first month that can hold a counted day: the month of max(joining date, attendance
        // start date). An earlier month typed into the URL is still shown (empty), with no way further back.
        $first = $employee === null ? $current : $this->firstMonth($employee);

        $month = [
            'value' => $grid->month,
            'label' => $grid->label(),
            'previous' => $grid->month > $first ? $grid->shift(-1) : null,
            'next' => $grid->month < $current ? $grid->shift(1) : null,
            'current' => $current,
        ];

        if ($employee === null) {
            return Inertia::render('my/attendance', [
                'employee' => null,
                'month' => $month,
                'days' => [],
                'cells' => [],
                'window' => null,
                'totals' => MonthGrid::emptyTotals(),
                'today' => $clock->attendanceToday(),
                'correctable' => [],
            ]);
        }

        $row = $grid->rows([$employee])[$employee->id];

        return Inertia::render('my/attendance', [
            'employee' => ['name' => $employee->full_name, 'code' => $employee->employee_code],
            'month' => $month,
            'days' => $grid->days(),
            'cells' => $row['cells'],
            'window' => $row['window'],
            'totals' => $row['totals'],
            'today' => $clock->attendanceToday(),
            'correctable' => $this->correctable($employee, $grid, $row),
        ]);
    }

    /** "Y-m" of max(joining date, attendance.start_date when set). */
    private function firstMonth(Employee $employee): string
    {
        $from = $employee->joining_date->format('Y-m-d');
        $start = AttendanceClock::startDate();

        if ($start !== null && $start > $from) {
            $from = $start;
        }

        return substr($from, 0, 7);
    }

    /**
     * The dates of this month on which "Request correction" is offered: the days of the employment window that Task 8's
     * CorrectionEligibility allows (a past day within its window, not corrected by hand, no open request, on or after
     * joining and the attendance start date), read in its two queries. The store route checks the same rule again.
     *
     * @param  array{window: array{0: int, 1: int}|null, cells: list<list<int|string|null>|null>, totals: array<string, int|float>}  $row
     * @return list<string>
     */
    private function correctable(Employee $employee, MonthGrid $grid, array $row): array
    {
        if ($row['window'] === null) {
            return [];
        }

        $from = sprintf('%s-%02d', $grid->month, $row['window'][0]);
        $to = sprintf('%s-%02d', $grid->month, $row['window'][1]);

        $refusals = $this->eligibility->refusals($employee, $from, $to);

        return array_keys(array_filter($refusals, fn (?string $reason) => $reason === null));
    }
}
