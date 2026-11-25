<?php

use App\Mail\LeaveRequestDecided;
use App\Mail\LeaveRequestSubmitted;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
| "Now" is 2026-11-25 (a Wednesday) 14:00 in Karachi: the attendance day is 2026-11-25. Attendance started on
| 2026-01-01. Default shift: Saturday and Sunday off. Annual leave: 14 days a year (seeded), people join 2026-01-01.
| The usual request is Mon 23 Nov – Fri 27 Nov 2026 (5 counted days, three of them up to today).
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function lvPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

/** An employee (role employee) reporting to a manager (role manager). */
function lvTeam(): array
{
    [$managerUser, $manager] = lvPerson('manager');
    [$user, $employee] = lvPerson('employee', ['manager_id' => $manager->id]);

    return [$user, $employee, $managerUser, $manager];
}

/** A pending request of $e counted as it stands now (as applying would store it). */
function lvRequest(Employee $e, string $from = '2026-11-23', ?string $to = null, ?string $half = null, string $type = 'Annual', array $attrs = []): LeaveRequest
{
    $to ??= $from;
    $plan = LeaveDays::plan($e, $from, $to, $half);

    return leaveRequest($e, [
        'leave_type_id' => leaveType($type)->id,
        'from_date' => $from,
        'to_date' => $to,
        'is_half_day' => $half !== null,
        'half' => $half,
        'days' => $plan->total,
        'reason' => 'Family wedding out of town.',
        ...$attrs,
    ]);
}

function lvDecide(User $user, LeaveRequest $r, string $step, string $decision = 'approve', ?string $note = null)
{
    return test()->actingAs($user)->from('/leave/requests')
        ->put("/leave/requests/{$r->id}/{$step}-decide", ['decision' => $decision, 'note' => $note]);
}

/** @return list<string> */
function lvDays(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()
        ->map(fn (LeaveDay $d) => $d->date->format('Y-m-d').':'.$d->half.':'.$d->amount)->all();
}

// ---------------------------------------------------------------- the whole flow

it('runs employee -> manager -> HR and writes one leave day per counted day, marking attendance up to today', function () {
    [$user, $employee, $managerUser] = lvTeam();
    [$hrUser] = lvPerson('hr');
    $admin = userWithRole('admin');
    $r = lvRequest($employee, '2026-11-23', '2026-11-27');

    lvDecide($managerUser, $r, 'manager')->assertRedirect('/leave/requests')
        ->assertSessionHas('success', "Approved. The leave request of {$employee->full_name} now waits for HR.");
    $r->refresh();
    expect($r->status)->toBe('manager_approved')->and($r->manager_decided_by)->toBe($managerUser->id)
        ->and(LeaveDay::count())->toBe(0);

    // Manager approval: the final approvers only, never the requester or the deciding manager.
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email) && $m->afterManager);
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email) && $m->afterManager);
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($user->email) || $m->hasTo($managerUser->email));
    Mail::assertNotQueued(LeaveRequestDecided::class);

    // The manager cannot decide again.
    lvDecide($managerUser, $r, 'manager')->assertSessionHas('error', 'This request was already approved by the manager.');

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');
    $r->refresh();
    expect($r->status)->toBe('approved')->and($r->hr_decided_by)->toBe($hrUser->id)->and($r->days)->toBe(5.0)
        ->and($r->manager_skipped)->toBeFalse()
        ->and($r->balance_before)->toEqual(['2026' => 14]);

    expect(lvDays($r))->toBe([
        '2026-11-23:full:1', '2026-11-24:full:1', '2026-11-25:full:1', '2026-11-26:full:1', '2026-11-27:full:1',
    ]);

    // Attendance: the days up to today are marked for a rebuild; later ones get nothing yet.
    $marked = Attendance::query()->where('employee_id', $employee->id)->orderBy('date')->get()
        ->map(fn (Attendance $a) => $a->date->format('Y-m-d'))->all();
    expect($marked)->toBe(['2026-11-23', '2026-11-24', '2026-11-25']);
    expect(Attendance::query()->where('employee_id', $employee->id)->where('needs_rebuild', true)->count())->toBe(3);

    // The balance: 5 used, nothing pending.
    $line = app(LeaveBalances::class)->line($employee, leaveType(), 2026);
    expect($line->used)->toBe(5.0)->and($line->pending)->toBe(0.0)->and($line->available)->toBe(9.0);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'approved');
    Mail::assertQueued(LeaveRequestDecided::class, 1);

    $entries = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->orderBy('id')->get();
    expect($entries->pluck('description')->all())->toBe(['Leave approved by manager', 'Leave approved']);
    expect($entries[1]->causer_id)->toBe($hrUser->id)
        ->and($entries[1]->subject_id)->toBe($employee->id)
        ->and($entries[1]->properties['days'])->toEqual(5)
        ->and($entries[1]->properties['step'])->toBe('final');
});

it('makes the manager approval final when the HR step is off, and writes the days', function () {
    Settings::set('leave', ['hr_step_enabled' => false, 'prorate_new_joiners' => true]);
    [$user, $employee, $managerUser] = lvTeam();
    lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01', '2026-12-02');

    lvDecide($managerUser, $r, 'manager')->assertSessionHas('success');
    $r->refresh();
    expect($r->status)->toBe('approved')->and($r->manager_decided_by)->toBe($managerUser->id)->and($r->hr_decided_by)->toBeNull()
        ->and(lvDays($r))->toHaveCount(2);

    Mail::assertNotQueued(LeaveRequestSubmitted::class);
    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'approved');
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->description)->toBe('Leave approved');
});

it('still sends an HR requester\'s manager-approved request to an Admin when the HR step is off', function () {
    Settings::set('leave', ['hr_step_enabled' => false, 'prorate_new_joiners' => true]);
    [$managerUser, $manager] = lvPerson('manager');
    [$hrUser, $hrEmployee] = lvPerson('hr', ['manager_id' => $manager->id]);
    [$otherHr] = lvPerson('hr');
    $admin = userWithRole('admin');
    $r = lvRequest($hrEmployee, '2026-12-01');

    lvDecide($managerUser, $r, 'manager')->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('manager_approved')->and(LeaveDay::count())->toBe(0);

    // Mailed to the Admin only: another HR user cannot decide an HR person's request.
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($otherHr->email) || $m->hasTo($hrUser->email));

    lvDecide($otherHr, $r, 'final')->assertForbidden();
    lvDecide($admin, $r, 'final')->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('approved');
});

it('lets HR decide directly when there is no manager step', function () {
    [$user, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    [$someManager] = lvPerson('manager');
    $r = lvRequest($employee, '2026-12-01');

    lvDecide($someManager, $r, 'manager')->assertForbidden();
    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');

    $r->refresh();
    expect($r->status)->toBe('approved')->and($r->manager_skipped)->toBeFalse()->and(lvDays($r))->toBe(['2026-12-01:full:1']);
});

it('lets HR decide while the request waits for the manager, audited as manager skipped', function () {
    [, $employee, $managerUser] = lvTeam();
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01');

    lvDecide($hrUser, $r, 'manager')->assertForbidden(); // HR uses the final step, not the manager's
    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');

    $r->refresh();
    expect($r->status)->toBe('approved')->and($r->manager_skipped)->toBeTrue()->and($r->manager_decided_by)->toBeNull();
    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->description)->toBe('Leave approved')->and($entry->properties['manager_skipped'])->toBeTrue();

    // The manager comes too late.
    lvDecide($managerUser, $r, 'manager')->assertSessionHas('error', 'This request was already approved.');
    expect(lvDays($r))->toHaveCount(1);
});

it('never lets the requester decide, in any stage', function () {
    [$user, $employee, $managerUser] = lvTeam();
    lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01');

    lvDecide($user, $r, 'manager')->assertForbidden();
    lvDecide($user, $r, 'final')->assertForbidden();

    lvDecide($managerUser, $r, 'manager')->assertSessionHas('success');
    lvDecide($user, $r, 'manager')->assertForbidden();
    lvDecide($user, $r, 'final')->assertForbidden();

    // An HR person's own request: never by themself.
    [$hrUser, $hrEmployee] = lvPerson('hr');
    $own = lvRequest($hrEmployee, '2026-12-03');
    lvDecide($hrUser, $own, 'final')->assertForbidden();
    lvDecide($hrUser, $own, 'manager')->assertForbidden();
    expect($own->fresh()->status)->toBe('pending');
});

it('never lets the user who approved as manager also decide the final step', function () {
    [, $employee, $managerUser] = lvTeam();
    $r = lvRequest($employee, '2026-12-01');
    lvDecide($managerUser, $r, 'manager')->assertSessionHas('success');

    // Even after being given HR rights.
    $managerUser->assignRole('hr');
    lvDecide($managerUser, $r, 'final')->assertForbidden();
    expect($r->fresh()->status)->toBe('manager_approved')->and(LeaveDay::count())->toBe(0);
});

it('lets only an Admin decide an HR person\'s request', function () {
    [, $hrEmployee] = lvPerson('hr');
    [$otherHr] = lvPerson('hr');
    $admin = userWithRole('admin');
    $r = lvRequest($hrEmployee, '2026-12-01');

    lvDecide($otherHr, $r, 'final')->assertForbidden();
    lvDecide($admin, $r, 'final')->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('approved');
});

it('lets the only active Admin approve their own request (audited) and refuses it once a second Admin exists', function () {
    [$admin, $adminEmployee] = lvPerson('admin');
    $r = lvRequest($adminEmployee, '2026-12-01');

    lvDecide($admin, $r, 'final')->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('approved');
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->properties['own_request'])->toBeTrue();

    userWithRole('admin');
    $second = lvRequest($adminEmployee, '2026-12-03');
    lvDecide($admin, $second, 'final')->assertForbidden();
    expect($second->fresh()->status)->toBe('pending');
});

it('lets HR who applied on behalf give the final approval, audited as such', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01', null, null, 'Annual', ['applied_by' => $hrUser->id]);

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->properties['decided_by_applicant'])->toBeTrue();
});

// ---------------------------------------------------------------- races (sequential simulations under the locks)

it('gives the second of two final approvers the stale message and writes the days once', function () {
    [, $employee] = lvPerson('employee');
    [$hrA] = lvPerson('hr');
    [$hrB] = lvPerson('hr');
    $r = lvRequest($employee, '2026-11-30', '2026-12-02');

    lvDecide($hrA, $r, 'final')->assertSessionHas('success');
    lvDecide($hrB, $r, 'final')->assertSessionHas('error', 'This request was already approved.');

    expect(lvDays($r))->toHaveCount(3)->and(LeaveDay::count())->toBe(3);
    Mail::assertQueued(LeaveRequestDecided::class, 1);
});

it('refuses an approval after the employee cancelled', function () {
    [$user, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01');
    $r->forceFill(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now()])->save();

    lvDecide($hrUser, $r, 'final')->assertSessionHas('error', 'This request was cancelled.');
    expect(LeaveDay::count())->toBe(0)->and($r->fresh()->status)->toBe('cancelled');
    Mail::assertNothingQueued();
});

it('refuses the approval when another approval used up the balance since applying', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-11-23', '2026-11-27'); // 5 days

    // Meanwhile 12 Annual days were approved (2 left).
    approvedLeave($employee, '2026-01-05', '2026-01-16');

    lvDecide($hrUser, $r, 'final')->assertSessionHas('error',
        'Not enough Annual leave for 2026: 2 left, 5 needed. Reject it, or ask for the extra days as Unpaid leave.');
    expect($r->fresh()->status)->toBe('pending')->and(lvDays($r))->toBe([]);
});

it('refuses approving a request across 31 December whose 2026 share would also be carried into 2027', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    // 14 days in December 2026 and 16 in January 2027 (Annual 14, carry cap 5).
    $r = lvRequest($employee, '2026-12-14', '2027-01-22');

    lvDecide($hrUser, $r, 'final')->assertSessionHas('error',
        'Not enough Annual leave for 2027: 14 left, 16 needed. Reject it, or ask for the extra days as Unpaid leave.');
    expect($r->fresh()->status)->toBe('pending')->and(lvDays($r))->toBe([]);

    // Ending on 20 Jan (14 + 14) it fits exactly.
    $fits = lvRequest($employee, '2026-12-14', '2027-01-20');
    $r->forceFill(['status' => 'cancelled'])->save();
    lvDecide($hrUser, $fits, 'final')->assertSessionHas('success');
    expect($fits->fresh()->status)->toBe('approved')->and($fits->fresh()->balance_before)->toBe(['2026' => 14, '2027' => 14])
        ->and(app(LeaveBalances::class)->line($employee, leaveType('Annual'), 2027)->available)->toBe(0.0);
});

it('refuses approving a 2026 request that would take days a pending 2027 request holds as carry', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    lvRequest($employee, '2027-02-01', '2027-02-25'); // 19 days: 14 of 2027 plus the 5 carried
    $r = lvRequest($employee, '2026-12-07', '2026-12-18'); // 10 days: only 4 would carry

    lvDecide($hrUser, $r, 'final')->assertSessionHas('error',
        'Not enough Annual leave: these days would be taken from what carries into 2027, and 2027 would end 1 short. Reject it, or ask for the extra days as Unpaid leave.');
    expect($r->fresh()->status)->toBe('pending')->and(lvDays($r))->toBe([]);
});

it('approves a request across 31 December of a type without carry-forward on each year alone', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-12-22', '2027-01-12', null, 'Sick'); // 8 + 8, Sick 8 a year

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');
    expect($r->fresh()->status)->toBe('approved')->and(lvDays($r))->toHaveCount(16);
});

it('never limits unpaid leave', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    approvedLeave($employee, '2026-01-05', '2026-01-18');
    $r = lvRequest($employee, '2026-11-23', '2026-11-27', null, 'Unpaid');

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');
    expect(lvDays($r))->toHaveCount(5)->and($r->fresh()->balance_before)->toBeNull();
});

it('refuses the approval when a holiday made every date a day off', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-12-01');
    Holiday::create(['name' => 'Founders Day', 'date' => '2026-12-01']);

    lvDecide($hrUser, $r, 'final')->assertSessionHas('error', 'Nothing left to approve: these dates are now holidays or weekly offs.');
    expect($r->fresh()->status)->toBe('pending')->and(LeaveDay::count())->toBe(0);
});

it('recounts the days at approval and says so when a holiday was added since applying', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-11-30', '2026-12-04'); // 5 days
    Holiday::create(['name' => 'Founders Day', 'date' => '2026-12-02']);

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success',
        "Approved. {$employee->full_name}'s leave of 4 days is recorded. The days were counted again because holidays or shifts changed since applying: 4 days instead of 5 days.");

    $r->refresh();
    expect($r->days)->toBe(4.0)->and(lvDays($r))->not->toContain('2026-12-02:full:1')->and(lvDays($r))->toHaveCount(4);
    $entry = Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole();
    expect($entry->properties['recounted'])->toEqual(['old' => 5, 'new' => 4]);
});

it('refuses a full day over an approved half day on the same date, but lets the other half through', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    approvedLeave($employee, '2026-12-02', null, 'am');

    $full = lvRequest($employee, '2026-12-01', '2026-12-03');
    lvDecide($hrUser, $full, 'final')->assertSessionHas('error', "{$employee->full_name} already has approved leave on Wed 2 Dec 2026.");
    expect(lvDays($full))->toBe([]);

    $sameHalf = lvRequest($employee, '2026-12-02', null, 'am');
    lvDecide($hrUser, $sameHalf, 'final')->assertSessionHas('error');

    $otherHalf = lvRequest($employee, '2026-12-02', null, 'pm');
    lvDecide($hrUser, $otherHalf, 'final')->assertSessionHas('success');
    expect(lvDays($otherHalf))->toBe(['2026-12-02:pm:0.5']);
});

it('marks a closed past attendance day for a rebuild but never touches a manual day', function () {
    [, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-23', 'status' => 'absent',
        'closed' => true, 'needs_rebuild' => false, 'is_manual' => false]);
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-24', 'status' => 'present',
        'closed' => true, 'needs_rebuild' => false, 'is_manual' => true]);
    $r = lvRequest($employee, '2026-11-23', '2026-11-24');

    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');

    expect(Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-23')->first()->needs_rebuild)->toBeTrue();
    $manual = Attendance::query()->where('employee_id', $employee->id)->onDate('2026-11-24')->first();
    expect($manual->needs_rebuild)->toBeFalse()->and($manual->status)->toBe('present');
});

// ---------------------------------------------------------------- rejection

it('requires a reason to reject, makes the rejection final, frees the pending days and tells the employee', function () {
    [$user, $employee, $managerUser] = lvTeam();
    $r = lvRequest($employee, '2026-12-01', '2026-12-02');

    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->pending)->toBe(2.0);

    lvDecide($managerUser, $r, 'manager', 'reject')->assertSessionHasErrors('note');
    lvDecide($managerUser, $r, 'manager', 'reject', 'no')->assertSessionHasErrors('note');
    expect($r->fresh()->status)->toBe('pending');

    lvDecide($managerUser, $r, 'manager', 'reject', 'Too many people away that week.')->assertSessionHas('success');
    $r->refresh();
    expect($r->status)->toBe('rejected')->and($r->decision_note)->toBe('Too many people away that week.');
    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2026)->pending)->toBe(0.0);

    Mail::assertQueued(LeaveRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->outcome === 'rejected');
    expect(Activity::where('log_name', 'leave')->where('properties->request', $r->id)->sole()->properties['note'])
        ->toBe('Too many people away that week.');

    lvDecide($managerUser, $r, 'manager')->assertSessionHas('error', 'This request was already rejected.');
});

// ---------------------------------------------------------------- access and mail

it('answers 403 for a request id that does not exist and for one out of scope', function () {
    [$hrUser] = lvPerson('hr');
    [$managerUser] = lvPerson('manager');
    [, $stranger] = lvPerson('employee');
    $r = lvRequest($stranger, '2026-12-01');

    lvDecide($hrUser, (new LeaveRequest)->forceFill(['id' => 999999]), 'final')->assertForbidden();
    lvDecide($managerUser, $r, 'manager')->assertForbidden();
    $this->actingAs($hrUser)->post('/leave/requests/999999/cancel-approved', ['note' => 'Worked after all.'])->assertForbidden();
});

it('mails nobody without an active login', function () {
    [$user, $employee, $managerUser] = lvTeam();
    [$hrUser] = lvPerson('hr');
    [$disabledHr] = lvPerson('hr');
    $disabledHr->forceFill(['is_active' => false])->save();
    $r = lvRequest($employee, '2026-12-01');

    lvDecide($managerUser, $r, 'manager')->assertSessionHas('success');
    Mail::assertQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email));
    Mail::assertNotQueued(LeaveRequestSubmitted::class, fn ($m) => $m->hasTo($disabledHr->email));

    $user->forceFill(['is_active' => false])->save();
    lvDecide($hrUser, $r, 'final')->assertSessionHas('success');
    Mail::assertNotQueued(LeaveRequestDecided::class);
});

it('renders the decision mail with the requester\'s own request only', function () {
    [$user, $employee] = lvPerson('employee');
    [$hrUser] = lvPerson('hr');
    $r = lvRequest($employee, '2026-11-30', '2026-12-01');
    lvDecide($hrUser, $r, 'final', 'approve', 'Enjoy the wedding.');

    $html = (new LeaveRequestDecided($r->fresh(), $user->name, 'approved', 'Enjoy the wedding.'))->render();
    expect($html)->toContain('Your leave request was approved')
        ->toContain('Mon 30 Nov 2026 – Tue 1 Dec 2026')
        ->toContain('2 days')
        ->toContain('Enjoy the wedding.')
        ->toContain("/leave/requests/{$r->id}")
        ->not->toContain($hrUser->email);
});
