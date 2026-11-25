<?php

use App\Actions\Leave\HolidayLeaveRefund;
use App\Mail\LeaveRequestDecided;
use App\Models\ActivityEntry;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| A holiday added on (or moved onto) approved leave (Task 8, plan open decision 14). "Now" is Wednesday 2026-11-25 14:00
| in Karachi; 2026-11-23 is a Monday. The default shift has Saturday and Sunday off. approvedLeave() writes one leave
| day per calendar date.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function hlrPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** @return list<string> */
function hlrDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

it('refunds an approved leave day when a holiday is added on it, re-marks attendance, mails and audits', function () {
    [$user, $employee] = hlrPerson();
    $hr = userWithRole('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-24');
    app(AttendanceRebuilder::class)->rebuild($employee, '2026-11-23');
    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->status)->toBe('on_leave');

    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Snow Day', 'date' => '2026-11-23'])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Holiday added. 1 person · 1 day went back to their balance.');

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and($r->days)->toBe(1.0)
        ->and($r->cancelled_from)->toBeNull()
        ->and(hlrDates($r))->toBe(['2026-11-24'])
        ->and(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(1.0);

    $row = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first();
    expect($row->needs_rebuild)->toBeTrue();
    app(AttendanceRebuilder::class)->rebuildPending();
    expect($row->fresh()->status)->toBe('holiday');

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->description)->toBe('Leave day refunded (holiday)')
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->subject_id)->toBe($employee->id)
        ->and($entry->properties['date'])->toBe('2026-11-23')
        ->and($entry->properties['holiday'])->toBe('Snow Day')
        ->and($entry->properties['refunded'])->toEqual(1)
        ->and($entry->properties['days'])->toEqual(1);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'refunded'
        && $m->note === 'Snow Day on Mon 23 Nov 2026' && $m->request->is($r));
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('cancels a one-day request whose only day becomes a holiday', function () {
    [, $employee] = hlrPerson();
    $r = approvedLeave($employee, '2026-12-01');

    $this->actingAs(userWithRole('admin'))->post('/leave/holidays', ['name' => 'Founders Day', 'date' => '2026-12-01'])->assertSessionHasNoErrors();

    $r->refresh();
    // A wholly cancelled request keeps the days it was approved for (final fix round M3).
    expect($r->status)->toBe('cancelled')
        ->and($r->days)->toBe(1.0)
        ->and($r->cancel_reason)->toBe('Every day became a holiday.')
        ->and($r->cancelled_at)->not->toBeNull()
        ->and(hlrDates($r))->toBe([]);
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->properties['cancelled'])->toBeTrue();
    // A whole request gone: the cancelled mail with the holiday note (the mail leaves out "Days: 0").
    Mail::assertQueued(LeaveRequestDecided::class, 1);
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->outcome === 'cancelled'
        && $m->note === 'Founders Day on Tue 1 Dec 2026. Every day became a holiday.');
});

it('refunds a half day and leaves other people and other dates alone', function () {
    [, $a] = hlrPerson();
    [, $b] = hlrPerson();
    $half = approvedLeave($a, '2026-12-03', null, 'am');
    $other = approvedLeave($b, '2026-12-04');

    $this->actingAs(userWithRole('hr'))->post('/leave/holidays', ['name' => 'Eid', 'date' => '2026-12-03'])->assertSessionHasNoErrors();

    expect($half->fresh()->status)->toBe('cancelled')
        ->and(hlrDates($other->fresh()))->toBe(['2026-12-04'])
        ->and($other->fresh()->days)->toBe(1.0);
    expect(Activity::where('log_name', 'leave')->count())->toBe(1);
});

it('refunds when a holiday is moved onto a leave day, and gives nothing back on the date it left', function () {
    [, $employee] = hlrPerson();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-07']);
    // Approved around the holiday: 7 Dec was never counted.
    $r = approvedLeave($employee, '2026-12-08', '2026-12-09');
    $r->forceFill(['from_date' => '2026-12-07'])->save();
    $hr = userWithRole('hr');

    // Only the name changes: nothing happens to leave.
    $this->actingAs($hr)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-12-07'])
        ->assertSessionHas('success', 'Holiday updated.');
    expect(hlrDates($r->fresh()))->toBe(['2026-12-08', '2026-12-09']);

    $this->actingAs($hr)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-12-08'])
        ->assertSessionHasNoErrors();

    $r->refresh();
    expect(hlrDates($r))->toBe(['2026-12-09'])->and($r->days)->toBe(1.0)->and($r->status)->toBe('approved');
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('adds nothing back when a holiday is deleted', function () {
    [, $employee] = hlrPerson();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    $r = approvedLeave($employee, '2026-12-07');
    $r->forceFill(['to_date' => '2026-12-09'])->save();
    LeaveDay::query()->create(['leave_request_id' => $r->id, 'employee_id' => $employee->id, 'leave_type_id' => $r->leave_type_id,
        'date' => '2026-12-09', 'amount' => 1, 'half' => 'full']);

    $this->actingAs(userWithRole('admin'))->delete("/leave/holidays/{$holiday->id}")->assertSessionHas('success');

    expect(hlrDates($r->fresh()))->toBe(['2026-12-07', '2026-12-09'])
        ->and(Activity::where('log_name', 'leave')->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('recounts open requests covering the new holiday and cancels one whose every day became a holiday', function () {
    [$user, $employee] = hlrPerson();
    $week = leaveRequest($employee, ['from_date' => '2026-12-07', 'to_date' => '2026-12-11', 'days' => 5]);
    $single = leaveRequest($employee, ['from_date' => '2026-12-14', 'to_date' => '2026-12-14', 'days' => 1, 'status' => 'manager_approved']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Day A', 'date' => '2026-12-09'])->assertSessionHasNoErrors();
    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Day B', 'date' => '2026-12-14'])->assertSessionHasNoErrors();

    expect($week->fresh()->days)->toBe(4.0)->and($week->fresh()->status)->toBe('pending')
        ->and($single->fresh()->days)->toBe(1.0)->and($single->fresh()->status)->toBe('cancelled')
        ->and($single->fresh()->cancel_reason)->toBe('Every day became a holiday.')
        ->and($single->fresh()->cancelled_by)->toBe($hr->id);

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $week->id)->sole();
    expect($entry->description)->toBe('Leave days recounted (holiday)')
        ->and($entry->properties['old'])->toEqual(['days' => 5])
        ->and($entry->properties['attributes'])->toEqual(['days' => 4]);
    $cancelled = Activity::where('log_name', 'leave')->where('properties->request', $single->id)->sole();
    expect($cancelled->properties['cancelled'])->toBeTrue()
        ->and($cancelled->properties['old'])->toEqual(['days' => 1, 'status' => 'manager_approved'])
        ->and($cancelled->properties['attributes'])->toEqual(['status' => 'cancelled']);

    // Only the cancelled one is mailed (withdrawn: it was never decided); the recount is not.
    Mail::assertQueued(LeaveRequestDecided::class, 1);
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->request->is($single)
        && $m->outcome === 'withdrawn' && $m->note === 'Day B on Mon 14 Dec 2026. Every day became a holiday.');
    // No balance went back: the flash says nothing about refunds.
    $this->actingAs($hr)->post('/leave/holidays', ['name' => 'Day C', 'date' => '2026-12-10'])->assertSessionHas('success', 'Holiday added.');
});

it('is idempotent and touches nobody without leave on the date', function () {
    [, $employee] = hlrPerson();
    $r = approvedLeave($employee, '2026-12-01', '2026-12-02');
    Holiday::factory()->create(['name' => 'X', 'date' => '2026-12-01']);
    $hr = userWithRole('hr');

    $first = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $second = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $nobody = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-20', 'Y', $hr));

    expect($first)->toHaveCount(1)->and($second)->toBe([])->and($nobody)->toBe([])
        ->and(hlrDates($r->fresh()))->toBe(['2026-12-02'])
        ->and(Activity::where('log_name', 'leave')->count())->toBe(1);
});

it('finds the affected people in a fixed number of queries, whoever else has leave', function () {
    $hr = userWithRole('hr');
    Holiday::factory()->create(['name' => 'X', 'date' => '2026-12-01']);

    foreach (range(1, 6) as $i) {
        approvedLeave(Employee::factory()->create(['joining_date' => '2026-01-01']), '2026-12-02', '2026-12-04');
    }

    DB::enableQueryLog();
    DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate('2026-12-01', 'X', $hr));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(3);
});

it('lists the approved requests covering each holiday in the index props, for the delete confirmation', function () {
    [, $a] = hlrPerson('employee', ['first_name' => 'Zara', 'last_name' => 'Khan']);
    [, $b] = hlrPerson('employee', ['first_name' => 'Ali', 'last_name' => 'Raza']);
    Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    Holiday::factory()->create(['name' => 'Quiet', 'date' => '2026-12-25']);
    $ra = approvedLeave($a, '2026-12-07');
    $ra->forceFill(['to_date' => '2026-12-09'])->save();
    $rb = approvedLeave($b, '2026-12-08');
    leaveRequest($a, ['from_date' => '2026-12-08', 'to_date' => '2026-12-08']); // pending: not listed
    $cancelled = approvedLeave($b, '2026-12-07');
    $cancelled->forceFill(['status' => 'cancelled', 'to_date' => '2026-12-09'])->save();

    $this->actingAs(userWithRole('hr'))->get('/leave/holidays?year=2026')
        ->assertInertia(fn ($p) => $p
            ->where('holidays.0.name', 'Eid')
            ->where('holidays.0.affected_leave', [
                ['request_id' => $rb->id, 'employee' => 'Ali Raza'],
                ['request_id' => $ra->id, 'employee' => 'Zara Khan'],
            ])
            ->where('holidays.1.affected_leave', []));
});

it('keeps the affected list to people the user may see', function () {
    [, $employee] = hlrPerson();
    Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);
    approvedLeave($employee, '2026-12-08');

    // An HR user without employees.view-all (permission removed for this test) sees only their own record.
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $hr->roles->first()->revokePermissionTo('employees.view-all');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($hr->fresh())->get('/leave/holidays?year=2026')
        ->assertInertia(fn ($p) => $p->where('holidays.0.affected_leave', []));
});

it('moves the holiday on a deadlock retry, audits it once and refunds only the new date', function () {
    // Laravel retries only a top-level transaction, and RefreshDatabase wraps every test in one. Run this test on a
    // fresh in-memory database of its own (migrated here), then put the shared connection back for the teardown.
    $connection = DB::connection();
    $shared = $connection->getPdo();
    $connection->setPdo(app('db.factory')->make(config('database.connections.sqlite'), 'sqlite-deadlock')->getPdo());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    try {
        Artisan::call('migrate', ['--force' => true]);
        Settings::set('attendance', ['start_date' => '2026-01-01']);

        [$user, $employee] = hlrPerson();
        $hr = userWithRole('hr');
        $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-07']);
        $r = approvedLeave($employee, '2026-12-08', '2026-12-09');
        $other = approvedLeave($employee, '2026-12-04'); // a leave day on neither date

        // The first leave audit entry (written after the holiday row was updated) fails once as a deadlock.
        $thrown = 0;
        ActivityEntry::creating(function (ActivityEntry $entry) use (&$thrown) {
            if ($entry->log_name === 'leave' && $thrown++ === 0) {
                throw new QueryException('sqlite', 'insert into "activity_log"', [],
                    new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'));
            }
        });

        $this->actingAs($hr)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid', 'date' => '2026-12-08'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        expect($thrown)->toBe(2)
            ->and($holiday->fresh()->date->format('Y-m-d'))->toBe('2026-12-08')
            ->and(Activity::where('log_name', 'attendance')->where('description', 'Holiday updated')->count())->toBe(1)
            ->and(hlrDates($r->fresh()))->toBe(['2026-12-09'])
            ->and(hlrDates($other->fresh()))->toBe(['2026-12-04'])
            ->and(Activity::where('log_name', 'leave')->count())->toBe(1);
        Mail::assertQueued(LeaveRequestDecided::class, 1);
        Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->request->is($r));
    } finally {
        ActivityEntry::flushEventListeners();
        $connection->setPdo($shared);
        (fn () => $this->transactions = 1)->call($connection); // the RefreshDatabase transaction, rolled back after the test
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
});

it('refunds many people with a bounded number of queries per request', function () {
    $hr = userWithRole('hr');
    leaveType();

    $run = function (string $date, int $people) use ($hr): int {
        foreach (range(1, $people) as $i) {
            $e = Employee::factory()->create(['joining_date' => '2026-01-01']);
            approvedLeave($e, $date, (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'));
            leaveRequest($e, ['from_date' => $date, 'to_date' => $date]); // an open request of only that date
        }
        Holiday::factory()->create(['name' => 'X', 'date' => $date]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $changed = DB::transaction(fn () => app(HolidayLeaveRefund::class)->forDate($date, 'X', $hr));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($changed)->toHaveCount($people * 2);

        return $queries;
    };

    $two = $run('2026-12-01', 2);
    $eight = $run('2026-12-15', 8);

    // Each affected request costs its own save and audit entry (2 queries); the rest is a fixed number of statements
    // (14 today: the payroll lock read, locking reads, employees, requests and their types, one delete, one rebuild
    // flag, the hand-corrected rows on the date, what is left, plans).
    expect(($eight - $two) / 12)->toBeLessThanOrEqual(2.0)
        ->and($two)->toBeLessThanOrEqual(14 + 4 * 2);
});

it('says how many people and days went back, halves included', function () {
    [, $a] = hlrPerson();
    [, $b] = hlrPerson();
    approvedLeave($a, '2026-12-03', null, 'am');
    approvedLeave($b, '2026-12-03', '2026-12-04');

    $this->actingAs(userWithRole('hr'))->post('/leave/holidays', ['name' => 'Eid', 'date' => '2026-12-03'])
        ->assertSessionHas('success', 'Holiday added. 2 people · 1.5 days went back to their balance.');
});

it('sends no refund mail to an inactive login', function () {
    [$user, $employee] = hlrPerson();
    $user->forceFill(['is_active' => false])->save();
    $r = approvedLeave($employee, '2026-12-01', '2026-12-02');

    $this->actingAs(userWithRole('hr'))->post('/leave/holidays', ['name' => 'X', 'date' => '2026-12-01'])->assertSessionHasNoErrors();

    expect(hlrDates($r->fresh()))->toBe(['2026-12-02']);
    Mail::assertNothingQueued();
});

it('leaves out of the affected list leave cancelled before the holiday, and lists each person once', function () {
    [, $a] = hlrPerson('employee', ['first_name' => 'Zara', 'last_name' => 'Khan']);
    [, $b] = hlrPerson('employee', ['first_name' => 'Ali', 'last_name' => 'Raza']);
    [, $c] = hlrPerson('employee', ['first_name' => 'Bilal', 'last_name' => 'Shah']);
    Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-12-08']);

    // Zara: cancelled from 7 Dec on, so she is back at work before the holiday: not around it.
    $partly = approvedLeave($a, '2026-12-03', '2026-12-04');
    $partly->forceFill(['to_date' => '2026-12-10', 'cancelled_from' => '2026-12-07', 'cancelled_at' => now()])->save();
    // Ali: two requests whose ranges both cover the holiday: listed once.
    $first = approvedLeave($b, '2026-12-07');
    $first->forceFill(['to_date' => '2026-12-09'])->save();
    $second = approvedLeave($b, '2026-12-09', '2026-12-10');
    $second->forceFill(['from_date' => '2026-12-08'])->save();
    // Bilal: partly cancelled only after the holiday: still around it.
    $later = approvedLeave($c, '2026-12-07');
    $later->forceFill(['to_date' => '2026-12-11', 'cancelled_from' => '2026-12-10', 'cancelled_at' => now()])->save();

    $this->actingAs(userWithRole('hr'))->get('/leave/holidays?year=2026')
        ->assertInertia(fn ($p) => $p->where('holidays.0.affected_leave', [
            ['request_id' => $first->id, 'employee' => 'Ali Raza'],
            ['request_id' => $later->id, 'employee' => 'Bilal Shah'],
        ]));
});
