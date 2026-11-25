<?php

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * The company calendar: GET /calendar?month=YYYY-MM. Who sees whose approved leave (HR/Admin everyone, a Manager own +
 * reports, everyone else own only), holidays and birthdays for all, and never a birth year / date of birth, a reason or
 * an attachment in the props.
 */

beforeEach(function () {
    $this->withoutVite();
    // Midday on 10 December 2026 in the company zone: the attendance day is 2026-12-10.
    $this->travelTo(CarbonImmutable::parse('2026-12-10 12:00:00', 'Asia/Karachi'));
});

function calEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create($attrs);
}

/** @return list<array<string, mixed>> */
function calLeave(User $viewer, string $month = '2026-12'): array
{
    return test()->actingAs($viewer)->get('/calendar?month='.$month)->assertOk()->inertiaProps('leave');
}

/** Employee ids in the leave prop, sorted. */
function calLeaveIds(User $viewer, string $month = '2026-12'): array
{
    return collect(calLeave($viewer, $month))->pluck('employee_id')->unique()->sort()->values()->all();
}

test('a guest is sent to the login page', function () {
    $this->get('/calendar')->assertRedirect('/login');
});

test('every signed-in role may open the calendar', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/calendar')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('calendar/index'));
})->with(['admin', 'hr', 'manager', 'employee']);

test('the month frame: label, Monday-first index of the 1st, day count and today', function () {
    $this->actingAs(userWithRole('employee'))->get('/calendar?month=2026-12')
        ->assertInertia(fn (Assert $page) => $page
            ->where('month', '2026-12')
            ->where('label', 'December 2026')
            ->where('first_weekday', 1) // 1 December 2026 is a Tuesday
            ->where('days_in_month', 31)
            ->where('today', '2026-12-10')
            ->where('previous', '2026-11')
            ->where('next', '2027-01')
            ->where('current', '2026-12'));

    $this->get('/calendar?month=2027-02')
        ->assertInertia(fn (Assert $page) => $page
            ->where('first_weekday', 0) // 1 February 2027 is a Monday
            ->where('days_in_month', 28));

    $this->get('/calendar?month=2026-11')->assertInertia(fn (Assert $page) => $page->where('first_weekday', 6)); // Sunday
});

test('a missing, malformed or out-of-range month falls back to the current month', function (?string $month) {
    $url = $month === null ? '/calendar' : '/calendar?month='.urlencode($month);

    $this->actingAs(userWithRole('employee'))->get($url)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-12')->where('label', 'December 2026'));
})->with([null, '', 'nope', '2026-13', '2026-1', '2026-12-01', '1999-12', '2028-01', '3000-01']);

test('months run from January 2000 to December of next year', function () {
    $this->actingAs(userWithRole('employee'))->get('/calendar?month=2000-01')
        ->assertInertia(fn (Assert $page) => $page->where('month', '2000-01')->where('previous', null)->where('next', '2000-02'));

    $this->get('/calendar?month=2027-12')
        ->assertInertia(fn (Assert $page) => $page->where('month', '2027-12')->where('next', null)->where('previous', '2027-11'));
});

test('holidays of the month are listed by date, others are not', function () {
    Holiday::factory()->create(['date' => '2026-12-25', 'name' => 'Christmas']);
    Holiday::factory()->create(['date' => '2026-12-01', 'name' => 'First']);
    Holiday::factory()->create(['date' => '2026-11-30', 'name' => 'November one']);
    Holiday::factory()->create(['date' => '2027-01-01', 'name' => 'New Year']);

    $this->actingAs(userWithRole('employee'))->get('/calendar?month=2026-12')
        ->assertInertia(fn (Assert $page) => $page->where('holidays', [
            ['date' => '2026-12-01', 'name' => 'First'],
            ['date' => '2026-12-25', 'name' => 'Christmas'],
        ]));
});

test('HR and Admin see everyone\'s approved leave with type, colour and halves', function (string $role) {
    $a = calEmployee(['first_name' => 'Asma', 'last_name' => 'Khan']);
    $b = calEmployee(['first_name' => 'Bilal', 'last_name' => 'Ahmed']);
    approvedLeave($a, '2026-12-14', '2026-12-15');
    approvedLeave($b, '2026-12-16', null, 'pm', 'Sick');

    $leave = collect(calLeave(userWithRole($role)))->keyBy('employee_id');

    expect($leave)->toHaveCount(2)
        ->and($leave[$a->id])->toMatchArray([
            'name' => 'Asma Khan',
            'first_name' => 'Asma',
            'type' => 'Annual',
            'colour' => 'emerald',
            'dates' => [['date' => '2026-12-14', 'half' => 'full'], ['date' => '2026-12-15', 'half' => 'full']],
        ])
        ->and($leave[$b->id])->toMatchArray([
            'type' => 'Sick',
            'colour' => 'rose',
            'dates' => [['date' => '2026-12-16', 'half' => 'pm']],
        ]);
})->with(['admin', 'hr']);

test('leave entries never carry the reason, the attachment or the request', function () {
    $e = calEmployee();
    $request = approvedLeave($e, '2026-12-14');
    $request->forceFill(['reason' => 'Private family matter XYZ', 'attachment_path' => 'leave/secret-file.pdf', 'attachment_name' => 'secret-file.pdf'])->save();

    $response = $this->actingAs(userWithRole('hr'))->get('/calendar?month=2026-12');
    $json = json_encode($response->inertiaProps());

    expect($json)->not->toContain('Private family matter')
        ->not->toContain('secret-file')
        ->not->toContain('"reason"')
        ->not->toContain('attachment');
    expect(array_keys($response->inertiaProps('leave')[0]))->toEqualCanonicalizing(['employee_id', 'name', 'first_name', 'type', 'colour', 'dates']);
});

test('a Manager sees their own and their direct reports\' leave only', function () {
    $user = userWithRole('manager');
    $manager = employeeFor($user);
    $report = calEmployee(['manager_id' => $manager->id]);
    $grandReport = calEmployee(['manager_id' => $report->id]);
    $stranger = calEmployee(['first_name' => 'Stranger', 'last_name' => 'Danger']);

    foreach ([$manager, $report, $grandReport, $stranger] as $e) {
        approvedLeave($e, '2026-12-14');
    }

    expect(calLeaveIds($user))->toBe(collect([$manager->id, $report->id])->sort()->values()->all());

    $leaveJson = json_encode($this->actingAs($user)->get('/calendar?month=2026-12')->inertiaProps('leave'));
    expect($leaveJson)->not->toContain('Stranger');
});

test('an Employee sees only their own leave and no colleague\'s name in it', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    $colleague = calEmployee(['first_name' => 'Colleague', 'last_name' => 'Hidden', 'manager_id' => $me->manager_id]);
    approvedLeave($me, '2026-12-14');
    approvedLeave($colleague, '2026-12-14');

    $response = $this->actingAs($user)->get('/calendar?month=2026-12');

    expect(collect($response->inertiaProps('leave'))->pluck('employee_id')->all())->toBe([$me->id])
        ->and(json_encode($response->inertiaProps()))->not->toContain('Colleague');
});

test('a login without an employee record sees no leave at all', function () {
    approvedLeave(calEmployee(), '2026-12-14');

    $this->actingAs(userWithRole('employee'))->get('/calendar?month=2026-12')
        ->assertInertia(fn (Assert $page) => $page->where('leave', [])->where('pending_own', []));
});

test('rejected, cancelled and open requests are never shown as leave', function (string $status) {
    $e = calEmployee();
    approvedLeave($e, '2026-12-14')->forceFill(['status' => $status])->save();

    expect(calLeave(userWithRole('hr')))->toBe([]);
})->with(['pending', 'manager_approved', 'rejected', 'cancelled']);

test('days removed by a part cancellation are not shown', function () {
    $e = calEmployee();
    $request = approvedLeave($e, '2026-12-14', '2026-12-17');
    // HR cancelled from the 16th: those days leave the ledger, the request stays approved (partly cancelled).
    LeaveDay::query()->where('leave_request_id', $request->id)->where('date', '>=', '2026-12-16')->delete();
    $request->forceFill(['cancelled_from' => '2026-12-16'])->save();

    $dates = collect(calLeave(userWithRole('hr')))->flatMap(fn ($entry) => collect($entry['dates'])->pluck('date'))->all();

    expect($dates)->toBe(['2026-12-14', '2026-12-15']);
});

test('leave across a month boundary shows each month its own days', function () {
    $e = calEmployee();
    approvedLeave($e, '2026-11-29', '2026-12-02');
    $hr = userWithRole('hr');

    $dates = fn (string $month) => collect(calLeave($hr, $month))->flatMap(fn ($entry) => collect($entry['dates'])->pluck('date'))->all();

    expect($dates('2026-12'))->toBe(['2026-12-01', '2026-12-02'])
        ->and($dates('2026-11'))->toBe(['2026-11-29', '2026-11-30'])
        ->and($dates('2027-01'))->toBe([]);
});

test('the viewer\'s own open requests are listed as pending; colleagues\' are not', function () {
    $user = userWithRole('hr');
    $me = employeeFor($user);
    $other = calEmployee();

    leaveRequest($me, ['from_date' => '2026-12-30', 'to_date' => '2027-01-02', 'days' => 4, 'status' => 'manager_approved']);
    leaveRequest($me, ['from_date' => '2026-12-21', 'to_date' => '2026-12-21', 'is_half_day' => true, 'half' => 'am', 'days' => 0.5]);
    leaveRequest($me, ['from_date' => '2026-12-22', 'to_date' => '2026-12-22', 'status' => 'rejected']);
    leaveRequest($me, ['from_date' => '2026-11-02', 'to_date' => '2026-11-03']);
    leaveRequest($other, ['from_date' => '2026-12-23', 'to_date' => '2026-12-23']);

    $pending = $this->actingAs($user)->get('/calendar?month=2026-12')->inertiaProps('pending_own');

    expect($pending)->toHaveCount(2)
        ->and($pending[0])->toMatchArray(['type' => 'Annual', 'colour' => 'emerald', 'status' => 'pending', 'dates' => [['date' => '2026-12-21', 'half' => 'am']]])
        ->and($pending[1])->toMatchArray(['status' => 'manager_approved', 'dates' => [['date' => '2026-12-30', 'half' => 'full'], ['date' => '2026-12-31', 'half' => 'full']]])
        ->and(json_encode($pending))->not->toContain('reason');
});

test('pending_own shows only the counted days: a Friday-to-Monday request has no Saturday or Sunday', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user, ['joining_date' => '2024-01-01']);
    shiftFor($me, ['weekly_offs' => ['saturday', 'sunday']]);
    Holiday::factory()->create(['date' => '2026-12-25', 'name' => 'Christmas']);

    leaveRequest($me, ['from_date' => '2026-12-11', 'to_date' => '2026-12-14', 'days' => 2]);          // Fri - Mon
    leaveRequest($me, ['from_date' => '2026-12-24', 'to_date' => '2026-12-28', 'days' => 2]);          // Thu, (Fri hol), Mon
    leaveRequest($me, ['from_date' => '2026-12-31', 'to_date' => '2027-01-04', 'days' => 3, 'status' => 'manager_approved']);

    $pending = $this->actingAs($user)->get('/calendar?month=2026-12')->inertiaProps('pending_own');
    $dates = fn (int $i) => collect($pending[$i]['dates'])->pluck('date')->all();

    expect($pending)->toHaveCount(3)
        ->and($dates(0))->toBe(['2026-12-11', '2026-12-14'])
        ->and($dates(1))->toBe(['2026-12-24', '2026-12-28'])
        ->and($dates(2))->toBe(['2026-12-31']);
});

test('pending_own falls back to every calendar date when the recount is refused', function () {
    $user = userWithRole('employee');
    // Joins after the request's dates: the recount is refused (outside employment), so the stored range shows.
    $me = employeeFor($user, ['joining_date' => '2026-12-20']);
    shiftFor($me, ['weekly_offs' => ['saturday', 'sunday']]);
    leaveRequest($me, ['from_date' => '2026-12-11', 'to_date' => '2026-12-14', 'days' => 2]);

    $pending = $this->actingAs($user)->get('/calendar?month=2026-12')->inertiaProps('pending_own');

    expect(collect($pending[0]['dates'])->pluck('date')->all())->toBe(['2026-12-11', '2026-12-12', '2026-12-13', '2026-12-14']);
});

test('weekly_offs are the default shift\'s weekly offs as Monday-first indexes', function () {
    Shift::default()->update(['weekly_offs' => ['friday', 'sunday']]);

    $this->actingAs(userWithRole('employee'))->get('/calendar')
        ->assertInertia(fn (Assert $page) => $page->where('weekly_offs', [4, 6]));
});

test('a former report\'s leave disappears after a manager change', function () {
    $user = userWithRole('manager');
    $manager = employeeFor($user);
    $report = calEmployee(['manager_id' => $manager->id]);
    approvedLeave($report, '2026-12-14');

    expect(calLeaveIds($user))->toContain($report->id);

    $report->forceFill(['manager_id' => calEmployee()->id])->save();

    expect(calLeaveIds($user))->not->toContain($report->id);
});

test('birthdays exclude staff who have not started yet and active staff past their last working day', function () {
    calEmployee(['first_name' => 'Starts', 'last_name' => 'Later', 'dob' => '1990-12-05', 'status' => 'active', 'joining_date' => '2027-01-04']);
    calEmployee(['first_name' => 'Started', 'last_name' => 'Today', 'dob' => '1990-12-06', 'status' => 'probation', 'joining_date' => '2026-12-10']);
    calEmployee(['first_name' => 'Past', 'last_name' => 'Last', 'dob' => '1990-12-07', 'status' => 'active', 'last_working_day' => '2026-12-01']);

    $this->actingAs(userWithRole('employee'))->get('/calendar?month=2026-12')
        ->assertInertia(fn (Assert $page) => $page->where('birthdays', [['day' => 6, 'name' => 'Started Today']]));
});

test('birthdays: day and name of working staff only, never the year or the date of birth', function () {
    calEmployee(['first_name' => 'Active', 'last_name' => 'One', 'dob' => '1987-12-15', 'status' => 'active']);
    calEmployee(['first_name' => 'Probation', 'last_name' => 'Two', 'dob' => '2001-12-03', 'status' => 'probation']);
    calEmployee(['first_name' => 'Resigned', 'last_name' => 'Gone', 'dob' => '1979-12-15', 'status' => 'resigned', 'last_working_day' => '2027-01-31']);
    calEmployee(['first_name' => 'Inactive', 'last_name' => 'Gone', 'dob' => '1979-12-16', 'status' => 'inactive']);
    calEmployee(['first_name' => 'Terminated', 'last_name' => 'Gone', 'dob' => '1979-12-17', 'status' => 'terminated']);
    calEmployee(['first_name' => 'January', 'last_name' => 'Born', 'dob' => '1990-01-15']);
    calEmployee(['first_name' => 'No', 'last_name' => 'Date', 'dob' => null]);

    // Every role sees the same birthdays (an Employee included).
    foreach (['employee', 'manager', 'hr'] as $role) {
        $response = $this->actingAs(userWithRole($role))->get('/calendar?month=2026-12');

        expect($response->inertiaProps('birthdays'))->toBe([
            ['day' => 3, 'name' => 'Probation Two'],
            ['day' => 15, 'name' => 'Active One'],
        ]);

        $json = json_encode($response->inertiaProps());
        expect($json)->not->toContain('dob')
            ->not->toContain('1987')
            ->not->toContain('2001-12')
            ->not->toContain('-12-15')
            ->not->toContain('Resigned')
            ->not->toContain('Inactive')
            ->not->toContain('Terminated');
    }
});

test('a birthday on 29 February shows on the 28th in a year without one', function () {
    calEmployee(['first_name' => 'Leap', 'last_name' => 'Ling', 'dob' => '1996-02-29']);
    calEmployee(['first_name' => 'Late', 'last_name' => 'Feb', 'dob' => '1990-02-28']);
    $viewer = userWithRole('employee');

    $this->actingAs($viewer)->get('/calendar?month=2027-02')
        ->assertInertia(fn (Assert $page) => $page->where('birthdays', [
            ['day' => 28, 'name' => 'Late Feb'],
            ['day' => 28, 'name' => 'Leap Ling'],
        ]));

    // 2000 was a leap year: the 29th is there.
    $this->get('/calendar?month=2000-02')
        ->assertInertia(fn (Assert $page) => $page->where('birthdays', [
            ['day' => 28, 'name' => 'Late Feb'],
            ['day' => 29, 'name' => 'Leap Ling'],
        ]));
});

test('the legend lists the active leave types in order', function () {
    leaveType('Casual', ['is_active' => false]);

    $types = $this->actingAs(userWithRole('employee'))->get('/calendar')->inertiaProps('types');

    expect(collect($types)->pluck('name')->all())->toBe(['Annual', 'Sick', 'Unpaid'])
        ->and($types[0])->toBe(['name' => 'Annual', 'colour' => 'emerald']);
});

test('an inactive type still in use this month stays in the legend', function () {
    $e = calEmployee();
    approvedLeave($e, '2026-12-14', null, null, 'Casual');
    leaveType('Casual', ['is_active' => false]);

    $types = $this->actingAs(userWithRole('hr'))->get('/calendar')->inertiaProps('types');

    expect(collect($types)->pluck('name')->all())->toBe(['Annual', 'Sick', 'Casual', 'Unpaid']);
});

test('the leave scope is named for the page', function (string $role, string $scope) {
    $user = userWithRole($role);
    employeeFor($user);

    $this->actingAs($user)->get('/calendar')->assertInertia(fn (Assert $page) => $page->where('leave_scope', $scope));
})->with([['admin', 'company'], ['hr', 'company'], ['manager', 'team'], ['employee', 'own']]);

test('the number of queries does not grow with the people on leave', function () {
    $hr = userWithRole('hr');
    $count = function () use ($hr): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get('/calendar?month=2026-12')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    approvedLeave(calEmployee(['dob' => '1990-12-01']), '2026-12-14');
    $count(); // warm the permission cache
    $few = $count();

    foreach (range(1, 6) as $i) {
        approvedLeave(calEmployee(['dob' => '1990-12-0'.$i]), '2026-12-1'.$i, null, null, $i % 2 ? 'Sick' : 'Annual');
        leaveRequest(calEmployee(), ['from_date' => '2026-12-2'.$i, 'to_date' => '2026-12-2'.$i]);
    }

    expect($count())->toBe($few);
});

test('a Manager\'s query count stays fixed with several own open requests and more reports on leave', function () {
    $user = userWithRole('manager');
    $manager = employeeFor($user, ['joining_date' => '2024-01-01']);
    shiftFor($manager, ['weekly_offs' => ['saturday', 'sunday']]);

    // Several own open requests (counted with LeaveDays in one fixed set of queries).
    leaveRequest($manager, ['from_date' => '2026-12-11', 'to_date' => '2026-12-14', 'days' => 2]);
    leaveRequest($manager, ['from_date' => '2026-12-17', 'to_date' => '2026-12-17']);
    leaveRequest($manager, ['from_date' => '2026-12-21', 'to_date' => '2026-12-21', 'is_half_day' => true, 'half' => 'pm']);

    $count = function () use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/calendar?month=2026-12')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    approvedLeave(calEmployee(['manager_id' => $manager->id]), '2026-12-14');
    $count(); // warm the permission cache
    $few = $count();

    foreach (range(1, 5) as $i) {
        approvedLeave(calEmployee(['manager_id' => $manager->id, 'dob' => '1990-12-0'.$i]), '2026-12-1'.$i);
        leaveRequest($manager, ['from_date' => '2026-12-0'.$i, 'to_date' => '2026-12-0'.$i]);
    }

    expect($count())->toBe($few)
        ->and($this->actingAs($user)->get('/calendar?month=2026-12')->inertiaProps('pending_own'))->toHaveCount(8);
});
