<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\Shift;
use App\Support\Attendance\AttendanceRebuilder;
use App\Support\Attendance\RebuildMarks;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
| Approved leave reaching attendance (Task 7). The default shift is 09:00-18:00, 15 minutes grace, 4 h half day,
| Saturday and Sunday off. "Now" is Tuesday 2026-11-24 14:00 in Karachi; 2026-11-23 is a Monday, 2026-11-21 a
| Saturday. Attendance started on 2026-01-01 unless a test says otherwise. Leave rows are written with approvedLeave()
| (no marks); a test marks the days with RebuildMarks::forLeaveDays as the leave actions do.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

function alRebuilder(): AttendanceRebuilder
{
    return app(AttendanceRebuilder::class);
}

/** @param  array<string, mixed>  $attrs */
function alEmployee(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2026-01-01', ...$attrs]);
}

function alRow(Employee $e, string $date): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->where('date', $date)->first();
}

/** Marks the dates as a leave change does, then runs the minute's rebuild. */
function alApplyMarks(Employee $e, array $dates): void
{
    RebuildMarks::forLeaveDays($e->id, $dates);
    alRebuilder()->rebuildPending();
}

it('turns a closed past day into on leave after a backdated approval, with its rule snapshot', function () {
    $e = alEmployee();
    alRebuilder()->rebuild($e, '2026-11-23'); // closed absent, snapshot of the 09:00-18:00 shift
    $before = alRow($e, '2026-11-23');
    expect($before->status)->toBe('absent')->and($before->closed)->toBeTrue();
    $snapshot = $before->getRawOriginal('shift_rules');

    // The shift is edited afterwards: the closed day keeps its snapshot.
    Shift::default()->forceFill(['start_time' => '10:00', 'end_time' => '19:00'])->save();

    approvedLeave($e, '2026-11-23');
    alApplyMarks($e, ['2026-11-23']);

    $row = alRow($e, '2026-11-23');
    expect($row->status)->toBe('on_leave')
        ->and($row->leave_half)->toBeNull()
        ->and($row->closed)->toBeTrue()
        ->and($row->needs_rebuild)->toBeFalse()
        ->and($row->getRawOriginal('shift_rules'))->toBe($snapshot);
    // Leave is data, never part of the snapshot.
    expect(json_decode($snapshot, true))->not->toHaveKey('leave_half')->not->toHaveKey('on_leave');
});

it('keeps the punches of a full leave day but leaves it on leave, unflagged', function () {
    $e = alEmployee();
    punch($e, '2026-11-23 09:40:00');
    punch($e, '2026-11-23 18:10:00');
    approvedLeave($e, '2026-11-23');

    alRebuilder()->rebuild($e, '2026-11-23');

    $row = alRow($e, '2026-11-23');
    expect($row->status)->toBe('on_leave')
        ->and($row->getRawOriginal('clock_in'))->toBe('2026-11-23 09:40:00')
        ->and($row->getRawOriginal('clock_out'))->toBe('2026-11-23 18:10:00')
        ->and($row->worked_minutes)->toBe(510)
        ->and($row->late_minutes)->toBe(0)
        ->and($row->needs_attention)->toBeFalse();
});

it('never touches a manual day, not even its marker', function () {
    $e = alEmployee();
    $manual = Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'status' => 'present', 'is_manual' => true,
        'clock_in' => '2026-11-23 09:00:00', 'clock_out' => '2026-11-23 17:00:00', 'worked_minutes' => 480,
        'closed' => true, 'needs_rebuild' => false,
    ]);
    $before = (array) DB::table('attendances')->where('id', $manual->id)->first();

    approvedLeave($e, '2026-11-23');
    alApplyMarks($e, ['2026-11-23']);
    alRebuilder()->rebuild($e, '2026-11-23');

    expect((array) DB::table('attendances')->where('id', $manual->id)->first())->toBe($before);
});

it('changes nothing for leave before the attendance start date', function () {
    Settings::set('attendance', ['start_date' => '2026-11-20']);
    $e = alEmployee();
    approvedLeave($e, '2026-11-18');

    alApplyMarks($e, ['2026-11-18']);
    alRebuilder()->rebuild($e, '2026-11-18');

    expect(alRow($e, '2026-11-18'))->toBeNull();

    // A punch day there is decided from its punches, the leave ignored.
    punch($e, '2026-11-18 09:00:00');
    punch($e, '2026-11-18 18:00:00');
    alRebuilder()->rebuild($e, '2026-11-18');

    expect(alRow($e, '2026-11-18')->status)->toBe('present')
        ->and(alRow($e, '2026-11-18')->leave_half)->toBeNull();
});

it('treats a first half and a second half on one date as a full leave day', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23', half: 'am');
    approvedLeave($e, '2026-11-23', half: 'pm');

    alApplyMarks($e, ['2026-11-23']);

    expect(alRow($e, '2026-11-23')->status)->toBe('on_leave')
        ->and(alRow($e, '2026-11-23')->leave_half)->toBeNull();
});

it('decides a half-leave day from the worked half', function (array $punches, string $half, string $status, bool $attention) {
    $e = alEmployee();
    foreach ($punches as $at) {
        punch($e, '2026-11-23 '.$at);
    }
    approvedLeave($e, '2026-11-23', half: $half);

    alRebuilder()->rebuild($e, '2026-11-23');

    $row = alRow($e, '2026-11-23');
    expect($row->status)->toBe($status)
        ->and($row->leave_half)->toBe($half)
        ->and($row->late_minutes)->toBe(0)
        ->and($row->early_minutes)->toBe(0)
        ->and($row->needs_attention)->toBe($attention);
})->with([
    'second half worked, 4 h, late clock-in' => [['13:45:00', '17:45:00'], 'am', 'present', false],
    'first half worked, short' => [['09:00:00', '12:00:00'], 'pm', 'absent', false],
    'one punch' => [['14:00:00'], 'am', 'absent', true],
    'no punches' => [[], 'pm', 'absent', false],
]);

it('shows the half on the open day once punched, and waits without punches', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-24', half: 'am');

    alApplyMarks($e, ['2026-11-24']);
    expect(alRow($e, '2026-11-24'))->toBeNull(); // the bare row is removed: nothing to record yet

    punch($e, '2026-11-24 13:50:00');
    alRebuilder()->rebuild($e, '2026-11-24');

    $row = alRow($e, '2026-11-24');
    expect($row->status)->toBe('present')->and($row->leave_half)->toBe('am')->and($row->late_minutes)->toBe(0);
});

it('ignores leave on a weekly off or a holiday', function () {
    $e = alEmployee();
    Holiday::factory()->create(['date' => '2026-11-23', 'name' => 'Test Day']);
    approvedLeave($e, '2026-11-21', '2026-11-23'); // Saturday to Monday (a holiday)
    approvedLeave($e, '2026-11-20', half: 'pm');
    Holiday::factory()->create(['date' => '2026-11-20', 'name' => 'Another']);

    alApplyMarks($e, ['2026-11-20', '2026-11-21', '2026-11-22', '2026-11-23']);

    expect(alRow($e, '2026-11-21')->status)->toBe('weekend')
        ->and(alRow($e, '2026-11-22')->status)->toBe('weekend')
        ->and(alRow($e, '2026-11-23')->status)->toBe('holiday')
        ->and(alRow($e, '2026-11-20')->status)->toBe('holiday')
        ->and(alRow($e, '2026-11-20')->leave_half)->toBeNull();
});

it('decides a closed day again from its punches when its leave is cancelled', function () {
    $e = alEmployee();
    punch($e, '2026-11-20 09:00:00');
    punch($e, '2026-11-20 18:00:00');
    approvedLeave($e, '2026-11-19', '2026-11-20');
    alApplyMarks($e, ['2026-11-19', '2026-11-20']);
    alRebuilder()->closeDay('2026-11-19');
    alRebuilder()->closeDay('2026-11-20');
    expect(alRow($e, '2026-11-19')->status)->toBe('on_leave')
        ->and(alRow($e, '2026-11-20')->status)->toBe('on_leave');

    // Cancelled: the leave days are deleted and the days marked.
    LeaveDay::query()->where('employee_id', $e->id)->delete();
    alApplyMarks($e, ['2026-11-19', '2026-11-20']);

    expect(alRow($e, '2026-11-19')->status)->toBe('absent')
        ->and(alRow($e, '2026-11-20')->status)->toBe('present')
        ->and(alRow($e, '2026-11-20')->closed)->toBeTrue();
});

it('clears the half when a half-leave day is cancelled', function () {
    $e = alEmployee();
    punch($e, '2026-11-23 14:30:00');
    punch($e, '2026-11-23 18:30:00');
    approvedLeave($e, '2026-11-23', half: 'am');
    alRebuilder()->rebuild($e, '2026-11-23');
    expect(alRow($e, '2026-11-23')->status)->toBe('present')->and(alRow($e, '2026-11-23')->leave_half)->toBe('am');

    LeaveDay::query()->where('employee_id', $e->id)->delete();
    alApplyMarks($e, ['2026-11-23']);

    // A full day's rules again: the 14:30 clock-in is late now.
    expect(alRow($e, '2026-11-23')->status)->toBe('late')
        ->and(alRow($e, '2026-11-23')->late_minutes)->toBe(330)
        ->and(alRow($e, '2026-11-23')->leave_half)->toBeNull();
});

it('creates today\'s on leave row before the nightly close, once', function () {
    $onLeave = alEmployee();
    $halfDay = alEmployee();
    $punched = alEmployee();
    $future = alEmployee();
    approvedLeave($onLeave, '2026-11-24');
    approvedLeave($halfDay, '2026-11-24', half: 'pm');
    approvedLeave($future, '2026-11-25');
    punch($punched, '2026-11-24 09:00:00');
    alRebuilder()->rebuild($punched, '2026-11-24');
    approvedLeave($punched, '2026-11-24'); // has a row already: kept up to date by the marks, not here

    expect(alRebuilder()->rebuildLeaveToday())->toBe(1);

    $row = alRow($onLeave, '2026-11-24');
    expect($row->status)->toBe('on_leave')->and($row->closed)->toBeFalse()
        ->and(alRow($halfDay, '2026-11-24'))->toBeNull() // a half-leave day without punches waits
        ->and(alRow($future, '2026-11-25'))->toBeNull()
        ->and(alRow($punched, '2026-11-24')->status)->toBe('present');

    $updated = $row->updated_at;
    $this->travel(1)->minutes();
    expect(alRebuilder()->rebuildLeaveToday())->toBe(0)
        ->and(alRow($onLeave, '2026-11-24')->updated_at->equalTo($updated))->toBeTrue();
});

it('runs today\'s leave from the minute command', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-24');

    $this->artisan('hrm:attendance-rebuild')
        ->expectsOutput('attendance_rebuild pending=0')
        ->expectsOutput('attendance_rebuild leave_today=1')
        ->assertSuccessful();

    expect(alRow($e, '2026-11-24')->status)->toBe('on_leave');
});

it('needs one query for today\'s leave when there is nothing to do', function () {
    alEmployee();
    alRebuilder()->rebuildLeaveToday(); // warm-up (settings)

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    alRebuilder()->rebuildLeaveToday();

    expect($count)->toBe(1);
});

it('reads leave days with one query per batch, whatever the number of days', function () {
    $people = Employee::factory()->count(4)->create(['joining_date' => '2026-01-01']);
    $rebuilder = alRebuilder();
    $rebuilder->rebuild($people[0], '2026-11-02'); // warm-up

    $queriesFor = function (array $dates) use ($people): int {
        foreach ($people as $p) {
            foreach ($dates as $d) {
                Attendance::query()->updateOrCreate(['employee_id' => $p->id, 'date' => $d], ['status' => null, 'needs_rebuild' => true]);
            }
        }

        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        alRebuilder()->rebuildPending();
        DB::flushQueryLog();

        return $count;
    };

    $one = $queriesFor(['2026-11-03']);

    foreach ($people as $p) {
        approvedLeave($p, '2026-11-09', '2026-11-13');
        approvedLeave($p, '2026-11-16', half: 'am');
    }
    $many = $queriesFor(['2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13', '2026-11-16']);

    expect($many)->toBe($one)
        ->and(Attendance::query()->where('status', 'on_leave')->count())->toBe(4 * 5)
        ->and(Attendance::query()->where('leave_half', 'am')->where('status', 'absent')->count())->toBe(4);
});

it('sends the half of a half-day leave day on the Daily screen, for a manual row too', function () {
    $this->withoutVite();
    $half = alEmployee();
    $full = alEmployee();
    $manual = alEmployee();
    Attendance::factory()->create(['employee_id' => $half->id, 'date' => '2026-11-23', 'status' => 'present', 'leave_half' => 'am', 'closed' => true]);
    Attendance::factory()->create(['employee_id' => $full->id, 'date' => '2026-11-23', 'status' => 'on_leave', 'closed' => true,
        'clock_in' => null, 'clock_out' => null, 'worked_minutes' => 0]);
    Attendance::factory()->create(['employee_id' => $manual->id, 'date' => '2026-11-23', 'status' => 'present', 'leave_half' => 'pm',
        'is_manual' => true, 'closed' => true]);

    $response = $this->actingAs(userWithRole('hr'))->get('/attendance/daily?date=2026-11-23')->assertOk();
    $rows = collect($response->inertiaProps('rows'))->keyBy('employee.id');

    expect($rows[$half->id]['leave_half'])->toBe('am')
        ->and($rows[$half->id]['status'])->toBe('present')
        ->and($rows[$full->id]['leave_half'])->toBeNull()
        ->and($rows[$manual->id]['leave_half'])->toBe('pm')
        ->and($response->inertiaProps('summary')['on_leave'])->toBe(1);
});

/*
| Fix round (C1, D1, M3, M1). A manual correction on a leave day keeps the leave: a half-leave day is judged on the
| worked half only (never late, never early) and keeps leave_half; a full leave day corrected without a status override
| stays on_leave with the entered times recorded (no late / early / overtime, never flagged); a status override wins and
| clears leave_half. leave_half is always written from the day's approved leave, so it is never stale.
*/

function alCorrect(Employee $e, string $date, ?string $in, ?string $out, ?string $status = null): Attendance
{
    return app(ApplyManualCorrection::class)->handle($e, $date, $in, $out, $status, 'Fixing the day', userWithRole('hr'));
}

it('clocks an am-leave person in when their lone punch comes before the worked half midpoint', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-24', half: 'am');
    punch($e, '2026-11-24 13:45:00');

    alRebuilder()->rebuild($e, '2026-11-24');

    $row = alRow($e, '2026-11-24');
    expect($row->getRawOriginal('clock_in'))->toBe('2026-11-24 13:45:00')
        ->and($row->clock_out)->toBeNull()
        ->and($row->status)->toBe('present')
        ->and($row->leave_half)->toBe('am');
});

it('judges only the worked half when a forgotten clock-out is fixed on an am-leave day', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23', half: 'am');
    punch($e, '2026-11-23 13:45:00');
    alRebuilder()->rebuild($e, '2026-11-23');
    expect(alRow($e, '2026-11-23')->status)->toBe('absent')->and(alRow($e, '2026-11-23')->needs_attention)->toBeTrue();

    $row = alCorrect($e, '2026-11-23', '13:45', '18:00');

    expect($row->fresh())
        ->status->toBe('present')
        ->leave_half->toBe('am')
        ->late_minutes->toBe(0)
        ->early_minutes->toBe(0)
        ->worked_minutes->toBe(255)
        ->needs_attention->toBeFalse()
        ->is_manual->toBeTrue();
});

it('judges the worked first half of a pm-leave day without early minutes', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23', half: 'pm');

    $row = alCorrect($e, '2026-11-23', '09:30', '13:30');

    expect($row->fresh())->status->toBe('present')->leave_half->toBe('pm')->late_minutes->toBe(0)->early_minutes->toBe(0);

    $short = alCorrect($e, '2026-11-23', '09:30', '12:00');
    expect($short->fresh())->status->toBe('absent')->leave_half->toBe('pm');
});

it('keeps a full leave day on leave when it is corrected without an override, with the entered times', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23');
    alApplyMarks($e, ['2026-11-23']);
    expect(alRow($e, '2026-11-23')->status)->toBe('on_leave');

    $row = alCorrect($e, '2026-11-23', '09:40', '18:10')->fresh();

    expect($row)
        ->status->toBe('on_leave')
        ->leave_half->toBeNull()
        ->is_manual->toBeTrue()
        ->status_overridden->toBeFalse()
        ->worked_minutes->toBe(510)
        ->late_minutes->toBe(0)
        ->early_minutes->toBe(0)
        ->overtime_minutes->toBe(0)
        ->needs_attention->toBeFalse()
        ->and($row->getRawOriginal('clock_in'))->toBe('2026-11-23 09:40:00')
        ->and($row->getRawOriginal('clock_out'))->toBe('2026-11-23 18:10:00');
});

it('treats a first half and a second half on one date as full leave for a correction', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23', half: 'am');
    approvedLeave($e, '2026-11-23', half: 'pm');

    $row = alCorrect($e, '2026-11-23', '10:00', null)->fresh();

    expect($row)->status->toBe('on_leave')->leave_half->toBeNull()->needs_attention->toBeFalse();
});

it('lets a status override win over leave and clears the half', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-23', half: 'am');
    punch($e, '2026-11-23 13:45:00');
    punch($e, '2026-11-23 18:00:00');
    alRebuilder()->rebuild($e, '2026-11-23');
    expect(alRow($e, '2026-11-23')->leave_half)->toBe('am');

    $row = alCorrect($e, '2026-11-23', '13:45', '18:00', 'late')->fresh();
    expect($row)->status->toBe('late')->leave_half->toBeNull()->status_overridden->toBeTrue()->late_minutes->toBe(285);

    $full = alEmployee();
    approvedLeave($full, '2026-11-23');
    $overridden = alCorrect($full, '2026-11-23', '09:00', '18:00', 'present')->fresh();
    expect($overridden)->status->toBe('present')->leave_half->toBeNull();
});

it('never leaves a stale half on a manual correction', function () {
    $e = alEmployee();
    // A row decided while an am half was approved; the leave has since been cancelled (no leave_days left).
    Attendance::factory()->create([
        'employee_id' => $e->id, 'date' => '2026-11-23', 'status' => 'present', 'leave_half' => 'am', 'closed' => true,
        'clock_in' => '2026-11-23 13:45:00', 'clock_out' => '2026-11-23 18:00:00', 'worked_minutes' => 255,
    ]);

    expect(alCorrect($e, '2026-11-23', '13:45', '18:00')->fresh())->leave_half->toBeNull()->status->toBe('late');

    // And the other way round: a pm half approved after a manual row without one.
    approvedLeave($e, '2026-11-23', half: 'pm');
    expect(alCorrect($e, '2026-11-23', '09:00', '13:00')->fresh()->leave_half)->toBe('pm');
});

it('keeps the leave when the nightly close finalises a manual row corrected on the open day', function () {
    $half = alEmployee();
    $full = alEmployee();
    $none = alEmployee();
    approvedLeave($half, '2026-11-24', half: 'am');
    approvedLeave($full, '2026-11-24');

    $open = alCorrect($half, '2026-11-24', '13:45', null)->fresh();
    expect($open)->status->toBe('present')->leave_half->toBe('am')->closed->toBeFalse();
    expect(alCorrect($full, '2026-11-24', '09:10', null)->fresh()->status)->toBe('on_leave');
    alCorrect($none, '2026-11-24', '13:45', null);
    // A stale half on a manual row of someone with no leave is cleared by the close too.
    Attendance::query()->where('employee_id', $none->id)->update(['leave_half' => 'pm']);

    $this->travelTo(CarbonImmutable::parse('2026-11-25 10:00:00', 'Asia/Karachi'));
    alRebuilder()->closeDay('2026-11-24');

    expect(alRow($half, '2026-11-24'))
        ->status->toBe('absent') // a single time on a closed half-leave day
        ->leave_half->toBe('am')
        ->late_minutes->toBe(0)
        ->needs_attention->toBeTrue()
        ->closed->toBeTrue()
        ->is_manual->toBeTrue();
    expect(alRow($full, '2026-11-24'))
        ->status->toBe('on_leave')->leave_half->toBeNull()->needs_attention->toBeFalse()->closed->toBeTrue();
    expect(alRow($none, '2026-11-24'))
        ->status->toBe('late')->leave_half->toBeNull()->needs_attention->toBeTrue();
});

it('creates today\'s on leave row for a first half and a second half on the same date', function () {
    $e = alEmployee();
    approvedLeave($e, '2026-11-24', half: 'am');
    approvedLeave($e, '2026-11-24', half: 'pm');

    expect(alRebuilder()->rebuildLeaveToday())->toBe(1)
        ->and(alRow($e, '2026-11-24')->status)->toBe('on_leave');
});

it('leaves out of today\'s leave run the people it could never write', function () {
    $count = function (): int {
        $n = 0;
        DB::listen(function () use (&$n) {
            $n++;
        });
        expect(alRebuilder()->rebuildLeaveToday())->toBe(0);

        return $n;
    };

    alEmployee();
    alRebuilder()->rebuildLeaveToday(); // warm-up (settings)
    $idle = $count();

    // A half-leave day without punches (it waits), a leaver without a last working day, someone who has left already,
    // someone joining later.
    approvedLeave(alEmployee(), '2026-11-24', half: 'pm');
    approvedLeave(alEmployee(['status' => 'resigned', 'last_working_day' => null]), '2026-11-24');
    approvedLeave(alEmployee(['last_working_day' => '2026-11-20']), '2026-11-24');
    approvedLeave(alEmployee(['joining_date' => '2026-11-30']), '2026-11-24');

    expect($count())->toBe($idle)
        ->and(Attendance::query()->count())->toBe(0);
});

it('does nothing for today\'s leave before attendance has started', function (?string $start) {
    Settings::set('attendance', ['start_date' => $start]);
    $e = alEmployee();
    approvedLeave($e, '2026-11-24');

    expect(alRebuilder()->rebuildLeaveToday())->toBe(0)
        ->and(alRow($e, '2026-11-24'))->toBeNull();
})->with([
    'no start date' => [null],
    'start date after today' => ['2026-11-25'],
]);
