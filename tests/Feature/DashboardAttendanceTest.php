<?php

use App\Models\Attendance;
use App\Models\DevicePunch;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\Shift;
use App\Models\User;
use App\Support\Attendance\DashboardAttendance;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/*
| The default shift is 09:00-18:00, Saturday and Sunday off. "Now" is 2026-11-24 (a Tuesday) 14:00 in Karachi: the
| attendance day is 2026-11-24 (open), 2026-11-23 and earlier are closed. Attendance started on 2026-01-01 unless a
| test says otherwise.
*/

const DA_OUTSIDER = 'Quillfeather';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @param  array<string, mixed>  $attrs */
function daEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

/** @param  array<string, mixed>  $attrs */
function daRow(Employee $e, string $date, array $attrs = []): Attendance
{
    return Attendance::factory()->create(['employee_id' => $e->id, 'date' => $date, 'closed' => $date < '2026-11-24', ...$attrs]);
}

function daLate(Employee $e, string $date, string $in, int $minutes): Attendance
{
    return daRow($e, $date, ['status' => 'late', 'clock_in' => "{$date} {$in}:00", 'clock_out' => null, 'late_minutes' => $minutes, 'worked_minutes' => 0]);
}

/** The dashboard's own props (shared props such as auth and app left out). */
function daProps($response): array
{
    return array_diff_key($response->inertiaProps(), array_flip(['auth', 'app', 'flash', 'errors', 'name', 'quote', 'sidebarOpen']));
}

/** The trend counts as [date => [status => count]]. */
function daTrend(array $props): array
{
    $out = [];
    foreach ($props['attendanceTrend']['counts'] as $c) {
        $out[$c['date']][$c['status']] = $c['count'];
    }

    return $out;
}

/**
 * A manager, their two reports, and an outsider (someone else's report) who is late today.
 *
 * @return array{0: User, 1: Employee, 2: Employee, 3: Employee, 4: Employee}
 */
function daTeam(): array
{
    $managerUser = userWithRole('manager');
    $manager = employeeFor($managerUser, ['joining_date' => '2026-01-01']);
    $a = daEmployee(['manager_id' => $manager->id]);
    $b = daEmployee(['manager_id' => $manager->id]);
    $outsider = daEmployee(['first_name' => DA_OUTSIDER]);

    return [$managerUser, $manager, $a, $b, $outsider];
}

// ---------------------------------------------------------------- company figures (Admin, HR)

it('gives Admin and HR today’s company figures: present, late, not in yet, pending', function (string $role) {
    $onTime = daEmployee();
    daRow($onTime, '2026-11-24', ['clock_out' => null]);
    $late = daEmployee();
    daLate($late, '2026-11-24', '09:40', 40);
    $pending = daEmployee();
    daRow($pending, '2026-11-24', ['status' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    $notIn1 = daEmployee();
    $notIn2 = daEmployee();
    // Not expected: exempt, joins later, already left, inactive without a last working day.
    daEmployee(['attendance_exempt' => true]);
    daEmployee(['joining_date' => '2026-12-01']);
    daEmployee(['status' => 'resigned', 'last_working_day' => '2026-11-20']);
    daEmployee(['status' => 'inactive']);
    unset($notIn1, $notIn2);

    $user = userWithRole($role); // no employee record of their own

    $props = daProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect($props['attendanceToday'])->toMatchArray([
        'date' => '2026-11-24', 'open' => true, 'started' => true,
        'present' => 2, 'late' => 1, 'pending' => 1, 'absent' => 0,
        'expected' => 5, 'not_in' => 2, 'holiday' => false,
    ])
        ->and($props['lateToday']['total'])->toBe(1)
        ->and($props['lateToday']['people'][0])->toMatchArray([
            'id' => $late->id, 'name' => $late->full_name, 'clock_in' => '09:40', 'late_minutes' => 40,
        ])
        ->and($props)->not->toHaveKey('myAttendance');
})->with(['admin', 'hr']);

it('does not count a weekly off of the person’s own shift, or anyone on a holiday, as not in yet', function () {
    $tuesdayOff = Shift::factory()->create(['weekly_offs' => ['tuesday'], 'start_time' => '09:00', 'end_time' => '17:00']);
    daEmployee(['shift_id' => $tuesdayOff->id]);
    daEmployee();

    $admin = userWithRole('admin');
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());
    expect($props['attendanceToday']['expected'])->toBe(1)->and($props['attendanceToday']['not_in'])->toBe(1);

    Holiday::factory()->create(['date' => '2026-11-24', 'name' => 'Founders Day']);
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());
    expect($props['attendanceToday'])->toMatchArray(['holiday' => true, 'expected' => 0, 'not_in' => 0]);
});

it('counts only clock-ins as present: an exempt day without punches is not "present today"', function () {
    $exempt = daEmployee(['attendance_exempt' => true]);
    daRow($exempt, '2026-11-24', ['status' => 'present', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    $weekendWorker = daEmployee();
    daRow($weekendWorker, '2026-11-24', ['status' => 'weekend']);

    $props = daProps($this->actingAs(userWithRole('hr'))->get('/dashboard')->assertOk());

    expect($props['attendanceToday'])->toMatchArray(['present' => 0, 'late' => 0, 'not_in' => 0]);
});

it('never claims everyone is missing before attendance has started', function () {
    Settings::set('attendance', ['start_date' => null]);
    $in = daEmployee();
    daLate($in, '2026-11-24', '09:30', 30);
    daEmployee();
    daEmployee();

    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect($props['attendanceToday'])->toMatchArray([
        'started' => false, 'start_date' => null, 'present' => 1, 'late' => 1, 'not_in' => null, 'expected' => null,
    ])
        ->and($props['attendanceTrend']['days'])->toHaveCount(30)
        ->and($props['attendanceTrend']['counts'])->toBe([]);

    // A start date still in the future: the same.
    Settings::set('attendance', ['start_date' => '2026-12-01']);
    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());
    expect($props['attendanceToday'])->toMatchArray(['started' => false, 'start_date' => '2026-12-01', 'not_in' => null]);
});

// ---------------------------------------------------------------- scope (Manager, Employee)

it('scopes a manager’s figures to their own record and reports, naming nobody else', function () {
    [$managerUser, $manager, $a, $b, $outsider] = daTeam();
    daLate($outsider, '2026-11-24', '10:15', 75);
    daLate($outsider, '2026-11-23', '10:15', 75);
    daLate($a, '2026-11-24', '09:20', 20);
    daRow($manager, '2026-11-24', ['clock_out' => null]);
    daRow($b, '2026-11-23', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    DevicePunch::factory()->create(['employee_id' => $outsider->id, 'punch_time' => '2026-11-24 13:59:00']);
    DevicePunch::factory()->create(['employee_id' => $a->id, 'punch_time' => '2026-11-24 09:20:11']);

    $props = daProps($this->actingAs($managerUser)->get('/dashboard')->assertOk());

    expect($props['employeeCount']['total'])->toBe(3)
        ->and($props['attendanceToday'])->toMatchArray([
            'present' => 2, 'late' => 1, 'expected' => 3, 'not_in' => 1,
            'last_punch' => ['date' => '2026-11-24', 'time' => '09:20'],
        ])
        ->and(array_column($props['lateToday']['people'], 'id'))->toBe([$a->id])
        ->and(daTrend($props)['2026-11-23'])->toBe(['absent' => 1])
        ->and(json_encode($props))->not->toContain(DA_OUTSIDER)
        ->and(json_encode($props))->not->toContain($outsider->employee_code);
});

it('gives a plain employee their own month instead of the company figures', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2026-01-01']);
    daRow($me, '2026-11-02');
    daRow($me, '2026-11-03', ['status' => 'half_day', 'worked_minutes' => 200]);
    daLate($me, '2026-11-04', '09:45', 45);
    daRow($me, '2026-11-05', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    daRow($me, '2026-11-06', ['status' => null]); // pending: never counted
    daRow($me, '2026-11-24', ['clock_out' => null]);
    $someone = daEmployee(['first_name' => DA_OUTSIDER]);
    daLate($someone, '2026-11-24', '10:00', 60);

    $props = daProps($this->actingAs($user)->get('/dashboard')->assertOk());

    expect($props)->not->toHaveKeys(['attendanceToday', 'lateToday', 'attendanceTrend', 'employeeCount'])
        ->and($props['myAttendance'])->toMatchArray(['month' => '2026-11', 'label' => 'November 2026'])
        ->and($props['myAttendance']['totals'])->toMatchArray([
            'present' => 2, 'late' => 1, 'half_day' => 1, 'absent' => 1, 'equivalent' => 3.5, 'working_days' => 5,
        ])
        ->and($props['myAttendance']['today'])->toBe(['status' => 'present', 'clock_in' => '09:00', 'clock_out' => null])
        ->and(json_encode($props))->not->toContain(DA_OUTSIDER);
});

it('gives a plain employee without an employee record no attendance figures at all', function () {
    $props = daProps($this->actingAs(userWithRole('employee'))->get('/dashboard')->assertOk());

    expect($props)->not->toHaveKeys(['attendanceToday', 'lateToday', 'attendanceTrend', 'myAttendance']);
});

// ---------------------------------------------------------------- around the close

it('keeps "not in yet" for the open day until the cutoff, then charts the closed day', function () {
    $in = daEmployee();
    $inRow = daRow($in, '2026-11-24');
    $missing = daEmployee();
    $admin = userWithRole('admin');

    // 02:00 on the 25th is still the 24th's attendance day (04:00 cutoff): open, nobody absent yet.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 02:00:00', 'Asia/Karachi'));
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());
    expect($props['attendanceToday'])->toMatchArray(['date' => '2026-11-24', 'open' => true, 'not_in' => 1])
        ->and(end($props['attendanceTrend']['days']))->toBe('2026-11-23');

    // After the cutoff the 24th is closed; the close has closed the day and marked the missing person absent.
    $inRow->update(['closed' => true]);
    daRow($missing, '2026-11-24', ['status' => 'absent', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0, 'closed' => true]);
    $this->travelTo(CarbonImmutable::parse('2026-11-25 05:00:00', 'Asia/Karachi'));
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());

    expect($props['attendanceToday'])->toMatchArray(['date' => '2026-11-25', 'present' => 0, 'not_in' => 2])
        ->and(end($props['attendanceTrend']['days']))->toBe('2026-11-24')
        ->and(daTrend($props)['2026-11-24'])->toBe(['absent' => 1, 'present' => 1]);
});

it('labels the figures with the attendance day when it is not the calendar date (after midnight, before the cutoff)', function () {
    $admin = userWithRole('admin');

    // 14:00: the attendance day is the calendar date.
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());
    expect($props['attendanceDay'])->toBe([
        'date' => '2026-11-24', 'calendar_date' => '2026-11-24', 'differs' => false, 'cutoff' => '04:00',
    ]);

    // 02:00 on the 25th: still the 24th's attendance day.
    $this->travelTo(CarbonImmutable::parse('2026-11-25 02:00:00', 'Asia/Karachi'));
    $props = daProps($this->actingAs($admin)->get('/dashboard')->assertOk());
    expect($props['attendanceDay'])->toBe([
        'date' => '2026-11-24', 'calendar_date' => '2026-11-25', 'differs' => true, 'cutoff' => '04:00',
    ])
        ->and($props['attendanceToday']['date'])->toBe('2026-11-24');

    // A plain employee's "today" is the same attendance day, so they get the same label.
    $user = userWithRole('employee');
    employeeFor($user, ['joining_date' => '2026-01-01']);
    Settings::set('attendance', ['start_date' => '2026-01-01', 'day_cutoff_time' => '05:30']);
    $props = daProps($this->actingAs($user)->get('/dashboard')->assertOk());
    expect($props['attendanceDay'])->toBe([
        'date' => '2026-11-24', 'calendar_date' => '2026-11-25', 'differs' => true, 'cutoff' => '05:30',
    ]);
});

it('sends no attendance day label to someone who gets no attendance figures', function () {
    $props = daProps($this->actingAs(userWithRole('employee'))->get('/dashboard')->assertOk());

    expect($props)->not->toHaveKey('attendanceDay');
});

it('shows the latest punch of anyone, even an unknown machine user, to a viewer with attendance.view-all only', function () {
    $role = Role::findOrCreate('attendance-desk', 'web');
    $role->givePermissionTo('attendance.view-all');
    $viewer = User::factory()->create();
    $viewer->assignRole($role);
    DevicePunch::factory()->create(['employee_id' => null, 'punch_time' => '2026-11-24 13:05:59']);

    $props = daProps($this->actingAs($viewer)->get('/dashboard')->assertOk());

    expect($props['attendanceToday']['last_punch'])->toBe(['date' => '2026-11-24', 'time' => '13:05']);
});

it('lists at most the limit of late people, earliest first, with the full total', function () {
    $limit = DashboardAttendance::LATE_LIST_LIMIT;
    $people = [];
    // Created latest first, so the list order cannot come from the ids.
    for ($i = $limit + 2; $i >= 1; $i--) {
        $e = daEmployee();
        daLate($e, '2026-11-24', sprintf('%02d:%02d', 9 + intdiv($i, 60), $i % 60), $i);
        $people[$i] = $e->id;
    }

    $late = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk())['lateToday'];
    $clockIns = array_column($late['people'], 'clock_in');
    $sorted = $clockIns;
    sort($sorted);

    expect($late['total'])->toBe($limit + 2)
        ->and($late['people'])->toHaveCount($limit)
        ->and($clockIns)->toBe($sorted)
        ->and($late['people'][0]['id'])->toBe($people[1])
        ->and(array_column($late['people'], 'id'))->not->toContain($people[$limit + 1])
        ->and(array_column($late['people'], 'id'))->not->toContain($people[$limit + 2]);
});

// ---------------------------------------------------------------- the 30-day series

it('leaves a past day that has not been closed out of the series', function () {
    $a = daEmployee();
    $b = daEmployee();
    daRow($a, '2026-11-23');
    daRow($b, '2026-11-23', ['closed' => false]); // decided but never closed (the close has not run)
    daRow($a, '2026-11-20', ['closed' => false]);

    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect(daTrend($props))->toBe(['2026-11-23' => ['present' => 1]]);
});

it('charts the last 30 closed days only, from decided statuses, never today or pending', function () {
    $a = daEmployee();
    $b = daEmployee();
    daRow($a, '2026-11-24');                                   // today: open, not charted
    daRow($a, '2026-11-23');
    daLate($b, '2026-11-23', '09:30', 30);
    daRow($a, '2026-11-20', ['status' => 'half_day', 'worked_minutes' => 200]);
    daRow($b, '2026-11-20', ['status' => null]);               // pending: never
    daRow($a, '2026-11-21', ['status' => 'weekend']);          // not charted
    daRow($a, '2026-10-25');                                   // the oldest charted day
    daRow($b, '2026-10-24');                                   // 31 days back: outside

    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());
    $days = $props['attendanceTrend']['days'];

    expect($days)->toHaveCount(30)
        ->and($days[0])->toBe('2026-10-25')
        ->and($days[29])->toBe('2026-11-23')
        ->and(daTrend($props))->toBe([
            '2026-10-25' => ['present' => 1],
            '2026-11-20' => ['half_day' => 1],
            '2026-11-23' => ['late' => 1, 'present' => 1],
        ]);
});

it('does not chart days before the attendance start date', function () {
    Settings::set('attendance', ['start_date' => '2026-11-20']);
    $a = daEmployee();
    daRow($a, '2026-11-19');
    daRow($a, '2026-11-20');

    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect(daTrend($props))->toBe(['2026-11-20' => ['present' => 1]]);
});

// ---------------------------------------------------------------- last punch

it('shows the latest stored punch time, and nothing when there are no punches', function () {
    $admin = userWithRole('admin');

    expect(daProps($this->actingAs($admin)->get('/dashboard'))['attendanceToday']['last_punch'])->toBeNull();

    DevicePunch::factory()->create(['punch_time' => '2026-11-24 08:58:30']);
    DevicePunch::factory()->create(['punch_time' => '2026-11-24 13:05:59']); // unknown machine user: still a punch
    DevicePunch::factory()->create(['punch_time' => '2026-11-23 18:01:00']);

    expect(daProps($this->actingAs($admin)->get('/dashboard'))['attendanceToday']['last_punch'])
        ->toBe(['date' => '2026-11-24', 'time' => '13:05']);
});

// ---------------------------------------------------------------- query count

it('reads a fixed number of queries whatever the number of people and days', function () {
    $admin = userWithRole('admin');
    $count = function () use ($admin): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->get('/dashboard')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $seed = function (int $people): void {
        for ($i = 0; $i < $people; $i++) {
            $e = daEmployee();
            daLate($e, '2026-11-24', '09:30', 30);
            foreach (['2026-11-23', '2026-11-20', '2026-11-19', '2026-11-18'] as $d) {
                daRow($e, $d);
            }
            DevicePunch::factory()->create(['employee_id' => $e->id]);
        }
    };

    $seed(2);
    $count(); // warm the per-process caches (permissions, settings) so both measured requests start alike
    $small = $count();
    $seed(12);
    $large = $count();

    // Measured: 13 queries for the whole request (10 for attendance and the rest, 3 for the Phase 4 leave figures:
    // open requests, people on leave today, requests by status); the bound leaves a margin of 2.
    expect($large)->toBe($small)->and($large)->toBeLessThanOrEqual(15);
});

// ---------------------------------------------------------------- leave (Phase 4)

it('charts on leave days and counts today\'s full leave', function () {
    $a = daEmployee();
    $b = daEmployee();
    $c = daEmployee();
    daRow($a, '2026-11-23', ['status' => 'on_leave', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    daRow($b, '2026-11-23', ['status' => 'present', 'leave_half' => 'am']); // half-day leave: counted by its worked half
    daRow($a, '2026-11-24', ['status' => 'on_leave', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    daRow($b, '2026-11-24', ['status' => 'present', 'leave_half' => 'pm']);
    daRow($c, '2026-11-24', ['status' => 'on_leave', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);

    $props = daProps($this->actingAs(userWithRole('admin'))->get('/dashboard')->assertOk());

    expect(daTrend($props))->toBe(['2026-11-23' => ['on_leave' => 1, 'present' => 1]])
        ->and($props['attendanceToday']['on_leave'])->toBe(2)
        // People on leave have a row: never "not in yet".
        ->and($props['attendanceToday']['not_in'])->toBe(0)
        ->and(DashboardAttendance::TREND_STATUSES)->toContain('on_leave');
});

it('counts only the manager\'s team on leave today', function () {
    [$managerUser, , $a, , $outsider] = daTeam();
    daRow($a, '2026-11-24', ['status' => 'on_leave', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    daRow($outsider, '2026-11-24', ['status' => 'on_leave', 'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);

    $props = daProps($this->actingAs($managerUser)->get('/dashboard')->assertOk());

    expect($props['attendanceToday']['on_leave'])->toBe(1);
});
