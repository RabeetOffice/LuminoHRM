<?php

use App\Actions\Leave\LeaveDayRemoval;
use App\Mail\LeaveRequestDecided;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveBalances;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
| "Now" is 2026-11-25 (Wednesday) 14:00 in Karachi. The usual approved leave is Mon 23 – Fri 27 Nov 2026 (one leave day
| per date, written by the approvedLeave() helper).
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function caPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

function caCancel(User $user, LeaveRequest $r, array $payload = [])
{
    return test()->actingAs($user)->from("/leave/requests/{$r->id}")
        ->post("/leave/requests/{$r->id}/cancel-approved", ['note' => 'Came back to work early.', ...$payload]);
}

/** @return list<string> */
function caDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

it('cancels from today by default: later days go back to the balance, earlier days stay, status stays approved', function () {
    [$user, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');

    caCancel($hrUser, $r)->assertRedirect("/leave/requests/{$r->id}")
        ->assertSessionHas('success', "The leave of {$employee->full_name} was cancelled from Wed 25 Nov 2026; 3 days went back to the balance.");

    $r->refresh();
    expect($r->status)->toBe('approved')
        ->and($r->cancelled_from->format('Y-m-d'))->toBe('2026-11-25')
        ->and($r->isPartlyCancelled())->toBeTrue()
        ->and($r->days)->toBe(2.0)
        ->and($r->cancelled_by)->toBe($hrUser->id)
        ->and($r->cancel_reason)->toBe('Came back to work early.')
        ->and(caDates($r))->toBe(['2026-11-23', '2026-11-24']);

    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(2.0);

    // Today's removed day is marked for a rebuild; later days have no attendance yet.
    $today = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-25')->first();
    expect($today)->not->toBeNull()->and($today->needs_rebuild)->toBeTrue();

    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->description)->toBe('Leave partly cancelled')
        ->and($entry->causer_id)->toBe($hrUser->id)
        ->and($entry->properties['removed'])->toBe(['2026-11-25', '2026-11-26', '2026-11-27'])
        ->and($entry->properties['refunded'])->toEqual(3)
        ->and($entry->properties['note'])->toBe('Came back to work early.');

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'cancelled'
        && $m->note === 'Came back to work early.');
});

it('cancels the whole request when cancelling from its first day', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');

    caCancel($hrUser, $r, ['from_date' => '2026-11-23'])->assertSessionHas('success',
        "The leave of {$employee->full_name} was cancelled; 5 days went back to the balance.");

    $r->refresh();
    // The request keeps the days it was approved for (shown on the screens); the balance comes from leave days.
    expect($r->status)->toBe('cancelled')->and($r->cancelled_from)->toBeNull()->and($r->days)->toBe(5.0)
        ->and(caDates($r))->toBe([]);
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->description)->toBe('Leave cancelled');
    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->used)->toBe(0.0);
});

it('cancels a future leave entirely from today', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-12-01', '2026-12-02');

    // Today is before the first day: without a date the server cancels from the first day, so the whole leave goes.
    caCancel($hrUser, $r)->assertSessionHasNoErrors()->assertSessionHas('success',
        "The leave of {$employee->full_name} was cancelled; 2 days went back to the balance.");
    expect($r->fresh()->status)->toBe('cancelled')->and($r->fresh()->days)->toBe(2.0)->and(caDates($r))->toBe([]);
});

it('refuses the default cancel-from date when the leave is already over', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-16', '2026-11-20');

    caCancel($hrUser, $r)->assertSessionHasErrors(['from_date' => 'Choose a day from Mon 16 Nov 2026 to Fri 20 Nov 2026.']);
    expect(caDates($r))->toHaveCount(5);
});

it('lets the only active Admin cancel their own approved leave (audited) and refuses it once a second Admin exists', function () {
    [$admin, $adminEmployee] = caPerson('admin');
    $r = approvedLeave($adminEmployee, '2026-12-07', '2026-12-08');

    $this->actingAs($admin)->get("/leave/requests/{$r->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->where('request.can.cancel_approved', true));

    caCancel($admin, $r, ['from_date' => '2026-12-08'])->assertSessionHas('success');
    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->properties['own_request'])->toBeTrue()->and(caDates($r))->toBe(['2026-12-07']);

    userWithRole('admin');
    caCancel($admin, $r, ['from_date' => '2026-12-07'])->assertForbidden();
    expect(caDates($r))->toBe(['2026-12-07']);
});

it('refuses a cancel-from date outside the leave days and a missing reason', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');

    caCancel($hrUser, $r, ['from_date' => '2026-11-20'])->assertSessionHasErrors('from_date');
    caCancel($hrUser, $r, ['from_date' => '2026-11-28'])->assertSessionHasErrors('from_date');
    caCancel($hrUser, $r, ['from_date' => '25-11-2026'])->assertSessionHasErrors('from_date');
    caCancel($hrUser, $r, ['note' => ''])->assertSessionHasErrors('note');
    caCancel($hrUser, $r, ['note' => 'no'])->assertSessionHasErrors('note');

    expect(caDates($r))->toHaveCount(5)->and($r->fresh()->cancelled_at)->toBeNull();
});

it('re-marks removed past days for a rebuild, closed days included, and leaves a manual day alone', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-23', 'status' => 'on_leave',
        'closed' => true, 'needs_rebuild' => false, 'is_manual' => false]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24', 'status' => 'present',
        'closed' => true, 'needs_rebuild' => false, 'is_manual' => true]);

    caCancel($hrUser, $r, ['from_date' => '2026-11-23', 'note' => 'The person actually worked.'])->assertSessionHas('success');

    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->needs_rebuild)->toBeTrue();
    $manual = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-24')->first();
    expect($manual->needs_rebuild)->toBeFalse()->and($manual->status)->toBe('present');
});

it('lets HR cancel a partly cancelled request again, further back', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');

    caCancel($hrUser, $r)->assertSessionHas('success');
    caCancel($hrUser, $r, ['from_date' => '2026-11-24'])->assertSessionHas('success');

    $r->refresh();
    expect(caDates($r))->toBe(['2026-11-23'])->and($r->days)->toBe(1.0)->and($r->cancelled_from->format('Y-m-d'))->toBe('2026-11-24');
});

it('lets only HR or Admin cancel approved leave, never on their own record or an HR person\'s unless Admin', function () {
    [$managerUser, $manager] = caPerson('manager');
    [$user, $employee] = caPerson('employee', ['manager_id' => $manager->id]);
    [$hrUser, $hrEmployee] = caPerson('hr');
    [$otherHr] = caPerson('hr');
    $r = approvedLeave($employee, '2026-11-23', '2026-11-27');

    caCancel($managerUser, $r)->assertForbidden();
    caCancel($user, $r)->assertForbidden();

    $own = approvedLeave($hrEmployee, '2026-12-07');
    caCancel($hrUser, $own, ['from_date' => '2026-12-07'])->assertForbidden();
    caCancel($otherHr, $own, ['from_date' => '2026-12-07'])->assertForbidden();

    $admin = userWithRole('admin');
    caCancel($admin, $own, ['from_date' => '2026-12-07'])->assertSessionHas('success');

    expect(caDates($r))->toHaveCount(5);
});

it('gives a stale message when the request is not approved', function () {
    [, $employee] = caPerson();
    [$hrUser] = caPerson('hr');
    $r = approvedLeave($employee, '2026-12-01');

    caCancel($hrUser, $r, ['from_date' => '2026-12-01'])->assertSessionHas('success');
    caCancel($hrUser, $r, ['from_date' => '2026-12-01'])->assertSessionHas('error', 'This request was cancelled.');

    $pending = leaveRequest($employee, ['from_date' => '2026-12-03', 'to_date' => '2026-12-03']);
    caCancel($hrUser, $pending, ['from_date' => '2026-12-03'])->assertSessionHas('error');
    expect($pending->fresh()->status)->toBe('pending');
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('removes leave days only through LeaveDayRemoval, reporting what was removed', function () {
    [, $employee] = caPerson();
    $r = approvedLeave($employee, '2026-11-30', '2026-12-02', null);

    $result = DB::transaction(function () use ($r) {
        $locked = LeaveRequest::query()->whereKey($r->id)->lockForUpdate()->first();

        return app(LeaveDayRemoval::class)->remove($locked, ['2026-12-01', '2026-12-02', '2026-12-09'], 'Test', null);
    });

    expect($result)->toBe(['removed' => ['2026-12-01', '2026-12-02'], 'amount' => 2.0, 'kept_locked' => []])
        ->and(caDates($r))->toBe(['2026-11-30']);
});
