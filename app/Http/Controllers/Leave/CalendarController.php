<?php

namespace App\Http\Controllers\Leave;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Attendance\AttendanceClock;
use App\Support\Attendance\Leavers;
use App\Support\Leave\LeaveRouting;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company calendar (Overview > Calendar), for every signed-in user.
 *
 *   GET /calendar?month=YYYY-MM
 *
 * Months run from 2000-01 to December of next year (by AttendanceClock::attendanceToday()); a missing, malformed or
 * out-of-range month shows the current month. Every date is a "Y-m-d" string, worked out here; the page never turns one
 * into a moment in time.
 *
 * Who sees what (plan open decision 15, default, owner may change):
 *   holidays    everyone.
 *   leave       APPROVED requests' leave_days in the month (removed days are gone from the ledger, so a partly
 *               cancelled request shows only what is left). Scope is LeaveRouting::scope: leave.approve-all -> everyone;
 *               leave.approve-team -> EmployeeAccess::scope (own + direct reports); anyone else -> own only. Each entry
 *               is the person's name, the type's name and colour and each date's half; never the reason, the
 *               attachment or the request itself.
 *   pending_own the viewer's OWN open requests (pending / manager_approved) touching the month, every calendar date
 *               from..to inside the month (days are only counted at approval), shown dashed. Never anybody else's.
 *   birthdays   everyone: active and probation staff who are still working (not a leaver status, no last working day
 *               before today), name and day number only. The date of birth is never selected: the query filters on the
 *               month and selects only the day number, so the year cannot reach the props. Born on 29 February: shown
 *               on the 28th in a year without a 29th.
 *
 * Props: month, label, first_weekday (0 = Monday), days_in_month, today, previous, next, current, leave_scope
 * (company | team | own), holidays [{date, name}], leave [{employee_id, name, first_name, type, colour,
 * dates: [{date, half}]}], pending_own [{type, colour, status, dates: [{date, half}]}], birthdays [{day, name}],
 * types [{name, colour}] (the legend: active types plus any inactive type shown this month).
 *
 * Queries per page: holidays, leave days, their people, types, own open requests, birthdays — a fixed number, whatever
 * the number of people on leave.
 */
class CalendarController extends Controller
{
    private const FIRST_MONTH = '2000-01';

    /** The statuses of someone currently working. */
    private const WORKING = ['active', 'probation'];

    public function index(Request $request, AttendanceClock $clock, LeaveRouting $routing): Response
    {
        /** @var User $user */
        $user = $request->user();
        $today = $clock->attendanceToday();
        $current = substr($today, 0, 7);
        $last = ((int) substr($today, 0, 4) + 1).'-12';
        $month = $this->month($request->query('month'), $current, $last);

        $first = new DateTimeImmutable($month.'-01');
        $from = $first->format('Y-m-d');
        $to = $first->modify('last day of this month')->format('Y-m-d');

        $types = LeaveType::query()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'colour', 'is_active', 'sort_order'])->keyBy('id');
        $leave = $this->leave($user, $routing, $from, $to, $types->all());
        $pending = $this->pendingOwn($user, $from, $to, $types->all());

        $shown = array_merge(array_column($leave, 'type_id'), array_column($pending, 'type_id'));
        $legend = $types->filter(fn (LeaveType $t) => $t->is_active || in_array($t->id, $shown, true))
            ->map(fn (LeaveType $t) => ['name' => $t->name, 'colour' => $t->colour])
            ->values()->all();

        return Inertia::render('calendar/index', [
            'month' => $month,
            'label' => $first->format('F Y'),
            'first_weekday' => (int) $first->format('N') - 1,
            'days_in_month' => (int) $first->format('t'),
            'today' => $today,
            'previous' => $month > self::FIRST_MONTH ? $first->modify('-1 month')->format('Y-m') : null,
            'next' => $month < $last ? $first->modify('+1 month')->format('Y-m') : null,
            'current' => $current,
            'leave_scope' => $user->can('leave.approve-all') ? 'company' : ($user->can('leave.approve-team') ? 'team' : 'own'),
            'holidays' => $this->holidays($from, $to),
            'leave' => array_map(fn (array $e) => array_diff_key($e, ['type_id' => true]), $leave),
            'pending_own' => array_map(fn (array $e) => array_diff_key($e, ['type_id' => true]), $pending),
            'birthdays' => $this->birthdays($first, $today),
            'types' => $legend,
        ]);
    }

    /** The asked "YYYY-MM" when it is a real month in range, else the current month. */
    private function month(mixed $asked, string $current, string $last): string
    {
        if (! is_string($asked) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $asked) !== 1) {
            return $current;
        }

        return $asked < self::FIRST_MONTH || $asked > $last ? $current : $asked;
    }

    /** @return list<array{date: string, name: string}> */
    private function holidays(string $from, string $to): array
    {
        return array_values(Holiday::query()->betweenDates($from, $to)->orderBy('date')->get(['date', 'name'])
            ->map(fn (Holiday $h) => ['date' => $h->date->format('Y-m-d'), 'name' => $h->name])
            ->all());
    }

    /**
     * Approved leave days in the month the viewer may see, one entry per request (so two requests of one person stay
     * apart), ordered by first date, then name.
     *
     * @param  array<int, LeaveType>  $types
     * @return list<array{employee_id: int, name: string, first_name: string, type: string, colour: string, type_id: int, dates: list<array{date: string, half: string}>}>
     */
    private function leave(User $user, LeaveRouting $routing, string $from, string $to, array $types): array
    {
        // The days of approved requests the viewer may see (LeaveRouting::scope is the one visibility rule).
        $visible = $routing->scope(LeaveRequest::query()->where('status', 'approved'), $user)->select('id');

        $days = LeaveDay::query()
            ->betweenDates($from, $to)
            ->whereIn('leave_request_id', $visible)
            ->with('employee:id,first_name,last_name')
            ->orderBy('date')
            ->orderBy('id')
            ->get(['id', 'leave_request_id', 'employee_id', 'leave_type_id', 'date', 'half']);

        $entries = [];

        foreach ($days as $day) {
            $type = $types[$day->leave_type_id] ?? null;
            $employee = $day->employee;

            if ($type === null || $employee === null) {
                continue;
            }

            $key = $day->leave_request_id;
            $entries[$key] ??= [
                'employee_id' => (int) $employee->id,
                'name' => $employee->full_name,
                'first_name' => (string) $employee->first_name,
                'type' => $type->name,
                'colour' => $type->colour,
                'type_id' => (int) $type->id,
                'dates' => [],
            ];
            $entries[$key]['dates'][] = ['date' => $day->date->format('Y-m-d'), 'half' => $day->half];
        }

        $entries = array_values($entries);
        usort($entries, fn (array $a, array $b) => [$a['dates'][0]['date'], $a['name'], $a['employee_id']] <=> [$b['dates'][0]['date'], $b['name'], $b['employee_id']]);

        return $entries;
    }

    /**
     * The viewer's own open requests touching the month, each date from..to inside it.
     *
     * @param  array<int, LeaveType>  $types
     * @return list<array{type: string, colour: string, status: string, type_id: int, dates: list<array{date: string, half: string}>}>
     */
    private function pendingOwn(User $user, string $from, string $to, array $types): array
    {
        if ($user->employee_id === null) {
            return [];
        }

        $requests = LeaveRequest::query()
            ->where('employee_id', $user->employee_id)
            ->whereIn('status', (array) config('hrm.leave.open_statuses'))
            ->where('from_date', '<=', $to)
            ->where('to_date', '>=', $from)
            ->orderBy('from_date')
            ->orderBy('id')
            ->get(['id', 'leave_type_id', 'from_date', 'to_date', 'is_half_day', 'half', 'status']);

        $entries = [];

        foreach ($requests as $open) {
            $type = $types[$open->leave_type_id] ?? null;

            if ($type === null) {
                continue;
            }

            $start = max($open->from_date->format('Y-m-d'), $from);
            $end = min($open->to_date->format('Y-m-d'), $to);
            $half = $open->is_half_day && $open->half !== null ? $open->half : 'full';
            $dates = [];

            for ($d = new DateTimeImmutable($start); $d->format('Y-m-d') <= $end; $d = $d->modify('+1 day')) {
                $dates[] = ['date' => $d->format('Y-m-d'), 'half' => $half];
            }

            $entries[] = [
                'type' => $type->name,
                'colour' => $type->colour,
                'status' => $open->status,
                'type_id' => (int) $type->id,
                'dates' => $dates,
            ];
        }

        return $entries;
    }

    /**
     * Birthdays in the month: day number and name only, of staff who are still working.
     *
     * @return list<array{day: int, name: string}>
     */
    private function birthdays(DateTimeImmutable $first, string $today): array
    {
        $month = (int) $first->format('n');
        $leapless = $month === 2 && $first->format('L') === '0';

        $query = Employee::query()
            ->whereIn('status', self::WORKING)
            ->whereNotIn('status', Leavers::STATUSES)
            ->where(fn (Builder $q) => $q->whereNull('last_working_day')->orWhere('last_working_day', '>=', $today))
            ->whereNotNull('dob')
            ->whereMonth('dob', $month);

        // Only the day number is selected (SQLite in tests, MariaDB in production); dob itself never leaves the database.
        $base = $query->toBase()->select(['first_name', 'last_name', 'id']);
        $rows = (DB::connection()->getDriverName() === 'sqlite'
            ? $base->selectRaw("CAST(strftime('%d', dob) AS INTEGER) as birth_day")
            : $base->selectRaw('DAY(dob) as birth_day'))
            ->get();

        $birthdays = [];

        foreach ($rows as $row) {
            $day = (int) $row->birth_day;

            if ($leapless && $day === 29) {
                $day = 28;
            }

            $birthdays[] = [
                'day' => $day,
                'name' => trim($row->first_name.' '.$row->last_name),
                'id' => (int) $row->id,
            ];
        }

        usort($birthdays, fn (array $a, array $b) => [$a['day'], $a['name'], $a['id']] <=> [$b['day'], $b['name'], $b['id']]);

        return array_map(fn (array $b) => ['day' => $b['day'], 'name' => $b['name']], $birthdays);
    }
}
