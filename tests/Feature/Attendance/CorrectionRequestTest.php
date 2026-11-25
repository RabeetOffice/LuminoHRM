<?php

use App\Actions\Attendance\ApplyManualCorrection;
use App\Mail\CorrectionRequestDecided;
use App\Mail\CorrectionRequestSubmitted;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\Employee;
use App\Models\User;
use App\Support\Attendance\CorrectionEligibility;
use App\Support\Attendance\CorrectionRouting;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Spatie\Activitylog\Models\Activity;

/*
| "Now" is 2026-11-24 (a Tuesday) 14:00 in Karachi, so the attendance day is 2026-11-24 and the window for a request
| runs from 2026-10-24 to 2026-11-23. Attendance started on 2026-01-01. The default shift is 09:00-18:00, 15 minutes
| grace, Saturday and Sunday off. 2026-11-20 is a Friday.
*/

beforeEach(function () {
    $this->withoutVite(); // the page is not in the build manifest until the coordinator builds
    $this->travelTo(CarbonImmutable::parse('2026-11-24 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/**
 * A login holding $role with its own employee record.
 *
 * @param  array<string, mixed>  $attrs  employee overrides
 * @return array{0: User, 1: Employee}
 */
function crPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs]);

    return [$user, $employee];
}

/** An employee (role employee) reporting to a manager (role manager). */
function crTeam(): array
{
    [$managerUser, $manager] = crPerson('manager');
    [$user, $employee] = crPerson('employee', ['manager_id' => $manager->id]);

    return [$user, $employee, $managerUser, $manager];
}

/** @param  array<string, mixed>  $overrides */
function crPayload(array $overrides = []): array
{
    return ['date' => '2026-11-20', 'clock_in' => '09:00', 'clock_out' => '18:00', 'reason' => 'The machine missed my punch.', ...$overrides];
}

function crSubmit(User $user, array $overrides = [])
{
    return test()->actingAs($user)->from('/my/attendance')->post('/attendance/corrections', crPayload($overrides));
}

function crLatest(): AttendanceCorrection
{
    return AttendanceCorrection::query()->latest('id')->firstOrFail();
}

function crDecide(User $user, AttendanceCorrection $c, string $step, string $decision = 'approve', ?string $note = null)
{
    return test()->actingAs($user)->from('/attendance/corrections')
        ->put("/attendance/corrections/{$c->id}/{$step}-decide", ['decision' => $decision, 'note' => $note]);
}

function crDay(Employee $e, string $date = '2026-11-20'): ?Attendance
{
    return Attendance::query()->where('employee_id', $e->id)->onDate($date)->first();
}

// ---------------------------------------------------------------- the whole flow

it('runs employee -> manager -> HR and applies the times as a manual correction', function () {
    [$user, $employee, $managerUser] = crTeam();
    [$hrUser] = crPerson('hr');
    $admin = userWithRole('admin');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'clock_in' => '2026-11-20 09:40:00',
        'clock_out' => null, 'status' => 'late', 'late_minutes' => 40, 'worked_minutes' => 0, 'closed' => true]);

    crSubmit($user)->assertRedirect('/my/attendance')->assertSessionHas('success');
    $c = crLatest();
    expect($c->status)->toBe('pending')->and($c->requested_in)->toBe('09:00')->and($c->requested_out)->toBe('18:00');

    // Submit: the manager only.
    Mail::assertQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($managerUser->email));
    Mail::assertQueuedCount(1);

    // HR cannot jump the manager step.
    crDecide($hrUser, $c, 'hr')->assertSessionHas('error', 'This request is still waiting for the reporting manager.');
    expect($c->fresh()->status)->toBe('pending');

    $this->actingAs($managerUser)->get('/attendance/corrections')->assertOk()
        ->assertInertia(fn ($p) => $p->component('attendance/corrections')->where('tab', 'pending')
            ->where('requests.data.0.id', $c->id)->where('requests.data.0.stage', 'manager')
            ->where('requests.data.0.can.decide', 'manager')->where('requests.data.0.can.cancel', false)
            ->where('requests.data.0.current', ['clock_in' => '09:40', 'clock_out' => null, 'is_manual' => false])
            ->where('requests.data.0.requested', ['clock_in' => '09:00', 'clock_out' => '18:00'])
            ->where('counts', ['pending' => 1, 'approved' => 0, 'rejected' => 0, 'cancelled' => 0]));

    crDecide($managerUser, $c, 'manager')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('manager_approved')->and($c->fresh()->manager_id)->toBe($managerUser->id);
    expect(crDay($employee)->is_manual)->toBeFalse();

    // Manager approval: every active Admin / HR login, never the requester.
    Mail::assertQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email) && $m->afterManager);
    Mail::assertQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertNotQueued(CorrectionRequestDecided::class);

    // The manager cannot decide again.
    crDecide($managerUser, $c, 'manager')->assertSessionHas('error', 'This request was already approved by the manager.');

    crDecide($hrUser, $c, 'hr')->assertSessionHas('success');
    $c->refresh();
    expect($c->status)->toBe('approved')->and($c->hr_id)->toBe($hrUser->id);

    $day = crDay($employee);
    expect($day->is_manual)->toBeTrue()
        ->and($day->status)->toBe('present')
        ->and($day->getAttributes()['clock_in'])->toStartWith('2026-11-20 09:00')
        ->and($day->getAttributes()['clock_out'])->toStartWith('2026-11-20 18:00')
        ->and($day->late_minutes)->toBe(0)
        ->and($day->note)->toBe("Correction request #{$c->id}");

    // The applied correction's audit entry carries the note; every step is audited.
    $applied = Activity::where('log_name', 'attendance')->where('description', 'Attendance corrected')->sole();
    expect($applied->causer_id)->toBe($hrUser->id)
        ->and($applied->properties['note'])->toBe("Correction request #{$c->id}")
        ->and($applied->properties['reason'])->toBe('The machine missed my punch.');
    expect(Activity::where('log_name', 'attendance')->pluck('description')->all())->toContain(
        'Correction requested', 'Correction request approved by manager', 'Correction request approved', 'Attendance corrected');

    // Decision: the requester only.
    Mail::assertQueued(CorrectionRequestDecided::class, fn ($m) => $m->hasTo($user->email));
    Mail::assertQueued(CorrectionRequestDecided::class, 1);
});

it('sends a request without a manager straight to HR, mailing every active Admin and HR login', function () {
    [$user, $employee] = crPerson('employee');
    [$hrUser] = crPerson('hr');
    $admin = userWithRole('admin');
    $disabledHr = userWithRole('hr');
    $disabledHr->forceFill(['is_active' => false])->save();

    crSubmit($user)->assertSessionHas('success');
    $c = crLatest();

    Mail::assertQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email) && ! $m->afterManager);
    Mail::assertQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($admin->email));
    Mail::assertNotQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($disabledHr->email) || $m->hasTo($user->email));
    Mail::assertQueuedCount(2);

    $this->actingAs($hrUser)->get('/attendance/corrections')
        ->assertInertia(fn ($p) => $p->where('requests.data.0.stage', 'hr')->where('requests.data.0.can.decide', 'hr'));

    crDecide($admin, $c, 'hr')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('approved')->and(crDay($employee)->is_manual)->toBeTrue();
});

it('skips the manager step when the manager login is disabled or the manager holds HR rights', function () {
    [$managerUser, $manager] = crPerson('manager');
    $managerUser->forceFill(['is_active' => false])->save();
    [$user] = crPerson('employee', ['manager_id' => $manager->id]);
    [$hrUser] = crPerson('hr');

    crSubmit($user);
    expect(app(CorrectionRouting::class)->stage(crLatest()))->toBe('hr');
    Mail::assertNotQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($managerUser->email));

    // An HR user who is the requester's manager decides once, as HR, and that decision is final.
    [$hrManagerUser, $hrManager] = crPerson('hr');
    [$user2, $employee2] = crPerson('employee', ['manager_id' => $hrManager->id]);
    crSubmit($user2);
    $c = crLatest();
    $this->actingAs($hrManagerUser)->get('/attendance/corrections')
        ->assertInertia(fn ($p) => $p->where('requests.data.1.id', $c->id)->where('requests.data.1.stage', 'hr'));
    crDecide($hrManagerUser, $c, 'manager')->assertSessionHas('error', 'This request is waiting for HR, not for the manager.');
    crDecide($hrManagerUser, $c, 'hr')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('approved')->and($c->fresh()->manager_id)->toBeNull();
    crDecide($hrUser, $c, 'hr')->assertSessionHas('error', 'This request was already approved.');
});

// ---------------------------------------------------------------- nobody decides their own

it('never lets anyone decide their own request; another HR user must', function () {
    [$hrUser, $hrEmployee] = crPerson('hr');
    [$otherHr] = crPerson('hr');

    crSubmit($hrUser)->assertSessionHas('success');
    $c = crLatest();
    Mail::assertNotQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email));

    $this->actingAs($hrUser)->get('/attendance/corrections')
        ->assertInertia(fn ($p) => $p->where('requests.data.0.can.decide', null)->where('requests.data.0.can.cancel', true)
            ->where('requests.data.0.is_own', true));
    crDecide($hrUser, $c, 'hr')->assertForbidden();
    crDecide($hrUser, $c, 'manager')->assertForbidden();
    expect($c->fresh()->status)->toBe('pending');

    crDecide($otherHr, $c, 'hr')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('approved')->and(crDay($hrEmployee)->is_manual)->toBeTrue();
});

it('never lets the user who approved as manager also decide the HR step', function () {
    [$user, , $managerUser] = crTeam();
    crPerson('hr');
    crSubmit($user);
    $c = crLatest();
    crDecide($managerUser, $c, 'manager');
    // The manager later gains HR rights.
    $managerUser->syncRoles(['hr']);

    crDecide($managerUser, $c->fresh(), 'hr')->assertForbidden();
    expect($c->fresh()->status)->toBe('manager_approved');
});

// ---------------------------------------------------------------- scope

it('shows a manager only their own and their reports\' requests and forbids the rest by id', function () {
    [$user, , $managerUser] = crTeam();
    [, , $otherManagerUser] = crTeam();
    [$outsider] = crPerson('employee');
    crSubmit($user);
    $mine = crLatest();
    crSubmit($outsider);
    $theirs = crLatest();

    $response = $this->actingAs($otherManagerUser)->get('/attendance/corrections')->assertOk()
        ->assertInertia(fn ($p) => $p->where('requests.total', 0)->where('counts.pending', 0));
    expect(json_encode($response->inertiaProps()))->not->toContain($user->name);

    crDecide($otherManagerUser, $mine, 'manager')->assertForbidden();
    crDecide($managerUser, $theirs, 'manager')->assertForbidden();
    crDecide($managerUser, $mine, 'hr')->assertForbidden(); // no attendance.edit
    $this->actingAs($managerUser)->post("/attendance/corrections/{$mine->id}/cancel")->assertForbidden();

    $this->actingAs($managerUser)->get('/attendance/corrections')
        ->assertInertia(fn ($p) => $p->where('requests.total', 1)->where('requests.data.0.id', $mine->id));
    expect($mine->fresh()->status)->toBe('pending')->and($theirs->fresh()->status)->toBe('pending');
});

it('forbids the list to a plain employee and sends guests to sign in', function () {
    [$user] = crPerson('employee');

    $this->actingAs($user)->get('/attendance/corrections')->assertForbidden();
    auth()->logout();
    $this->get('/attendance/corrections')->assertRedirect('/login');
    $this->post('/attendance/corrections', crPayload())->assertRedirect('/login');
});

it('counts every tab within scope and lists each tab\'s requests', function () {
    [$user, $employee] = crPerson('employee');
    $hr = userWithRole('hr');
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-18', 'status' => 'approved']);
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-17', 'status' => 'rejected', 'decision_note' => 'No.']);
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-16', 'status' => 'cancelled']);
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-19', 'status' => 'manager_approved']);
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'status' => 'pending']);

    $this->actingAs($hr)->get('/attendance/corrections')
        ->assertInertia(fn ($p) => $p->where('counts', ['pending' => 2, 'approved' => 1, 'rejected' => 1, 'cancelled' => 1])
            ->where('requests.total', 2)->where('requests.data.0.date', '2026-11-19'));
    $this->actingAs($hr)->get('/attendance/corrections?tab=rejected')
        ->assertInertia(fn ($p) => $p->where('tab', 'rejected')->where('requests.data.0.decision_note', 'No.')
            ->where('requests.data.0.can.decide', null));
    $this->actingAs($hr)->get('/attendance/corrections?tab=nonsense')->assertInertia(fn ($p) => $p->where('tab', 'pending'));
});

// ---------------------------------------------------------------- what can be requested

it('refuses a duplicate open request, today, the future, older than 31 days, before joining or start, and a manual day', function () {
    [$user, $employee] = crPerson('employee', ['joining_date' => '2026-11-02']);
    crPerson('hr');

    crSubmit($user)->assertSessionHas('success');
    crSubmit($user)->assertSessionHasErrors(['date' => 'There is already an open correction request for this day.']);
    crSubmit($user, ['date' => '2026-11-24'])->assertSessionHasErrors('date');
    crSubmit($user, ['date' => '2026-11-26'])->assertSessionHasErrors('date');
    crSubmit($user, ['date' => '2026-11-01'])->assertSessionHasErrors(['date' => 'This day is before your joining date.']);
    crSubmit($user, ['date' => '2026-02-30'])->assertSessionHasErrors('date');

    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-19', 'is_manual' => true, 'closed' => true]);
    crSubmit($user, ['date' => '2026-11-19'])->assertSessionHasErrors(['date' => 'This day has already been corrected by HR. Ask HR if it is still wrong.']);

    expect(AttendanceCorrection::count())->toBe(1);
});

it('applies the 31-day window and the attendance start date', function () {
    [$user, $employee] = crPerson('employee');
    $rule = app(CorrectionEligibility::class);

    expect($rule->reasonRefused($employee, '2026-10-24'))->toBeNull()
        ->and($rule->reasonRefused($employee, '2026-10-23'))->toContain('last 31 days')
        ->and($rule->reasonRefused($employee, '2026-11-23'))->toBeNull()
        ->and($rule->reasonRefused($employee, '2026-11-24'))->toContain('past day');

    Settings::set('attendance', ['start_date' => '2026-11-10']);
    expect($rule->reasonRefused($employee, '2026-11-09'))->toBe('Attendance was not yet recorded on this day.');

    $map = $rule->refusals($employee, '2026-11-08', '2026-11-12');
    expect(array_keys($map))->toBe(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'])
        ->and($map['2026-11-10'])->toBeNull()->and($map['2026-11-09'])->not->toBeNull();
});

it('allows a cancelled or rejected day to be requested again', function () {
    [$user, $employee] = crPerson('employee');
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'status' => 'cancelled']);
    AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'status' => 'rejected']);

    crSubmit($user)->assertSessionHas('success');
});

it('checks the times like a manual correction and requires a reason', function () {
    [$user] = crPerson('employee');
    crPerson('hr');

    crSubmit($user, ['clock_in' => '10:00', 'clock_out' => '09:00'])->assertSessionHasErrors('clock_out');
    crSubmit($user, ['clock_in' => '03:00'])->assertSessionHasErrors('clock_in');
    crSubmit($user, ['clock_in' => '9am'])->assertSessionHasErrors('clock_in');
    crSubmit($user, ['clock_in' => null, 'clock_out' => null])->assertSessionHasErrors('clock_in');
    crSubmit($user, ['reason' => ''])->assertSessionHasErrors('reason');
    crSubmit($user, ['reason' => 'abc'])->assertSessionHasErrors('reason');
    expect(AttendanceCorrection::count())->toBe(0);

    // After midnight, before the 04:00 cutoff: the next morning.
    crSubmit($user, ['clock_in' => '18:00', 'clock_out' => '02:00'])->assertSessionHas('success');
    expect(crLatest()->requested_out)->toBe('02:00');
});

it('keeps the recorded time for a requested time left empty', function () {
    [$user, $employee] = crPerson('employee');
    crPerson('hr');
    Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'clock_in' => '2026-11-20 09:05:00',
        'clock_out' => null, 'closed' => true]);

    crSubmit($user, ['clock_in' => null, 'clock_out' => '18:30'])->assertSessionHas('success');
    expect(crLatest()->requested_in)->toBe('09:05')->and(crLatest()->requested_out)->toBe('18:30');
});

it('refuses a login with no employee record', function () {
    $user = userWithRole('employee');

    $this->actingAs($user)->post('/attendance/corrections', crPayload())->assertForbidden();
});

// ---------------------------------------------------------------- approval = manual correction

it('writes exactly what a manual correction of the same times writes', function () {
    [$user, $employee] = crPerson('employee');
    [$hrUser] = crPerson('hr');
    $twin = Employee::factory()->create(['joining_date' => '2026-01-01']);

    crSubmit($user, ['clock_in' => '09:40', 'clock_out' => '17:00']);
    crDecide($hrUser, crLatest(), 'hr')->assertSessionHas('success');
    app(ApplyManualCorrection::class)->handle($twin, '2026-11-20', '09:40', '17:00', null, 'Same', $hrUser);

    $columns = ['clock_in', 'clock_out', 'status', 'worked_minutes', 'late_minutes', 'early_minutes', 'overtime_minutes', 'is_manual', 'closed', 'shift_id', 'needs_rebuild', 'needs_attention'];
    $a = collect(crDay($employee)->getAttributes())->only($columns)->all();
    $b = collect(crDay($twin)->getAttributes())->only($columns)->all();
    expect($a)->toEqual($b)->and($a['status'])->toBe('late');
});

it('refuses to overwrite a day corrected by hand after the request, and leaves the request open', function () {
    [$user, $employee] = crPerson('employee');
    [$hrUser] = crPerson('hr');
    crSubmit($user);
    $c = crLatest();

    app(ApplyManualCorrection::class)->handle($employee, '2026-11-20', '08:00', '16:00', null, 'HR fixed it', $hrUser);

    crDecide($hrUser, $c, 'hr')->assertSessionHas('error', fn ($m) => str_contains($m, 'corrected by hand after the request'));
    expect($c->fresh()->status)->toBe('pending')
        ->and(crDay($employee)->getAttributes()['clock_in'])->toStartWith('2026-11-20 08:00')
        ->and(Activity::where('description', 'Attendance corrected')->count())->toBe(1);

    // It can still be rejected.
    crDecide($hrUser, $c, 'hr', 'reject', 'Already corrected.')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('rejected');
});

// ---------------------------------------------------------------- rejection, cancellation, stale decisions

it('requires a note to reject, makes the rejection final and tells the requester', function () {
    [$user, , $managerUser] = crTeam();
    [$hrUser] = crPerson('hr');
    crSubmit($user);
    $c = crLatest();

    crDecide($managerUser, $c, 'manager', 'reject')->assertSessionHasErrors('note');
    expect($c->fresh()->status)->toBe('pending');

    crDecide($managerUser, $c, 'manager', 'reject', 'You were on leave that day.')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('rejected')->and($c->fresh()->decision_note)->toBe('You were on leave that day.');
    Mail::assertQueued(CorrectionRequestDecided::class, fn ($m) => $m->hasTo($user->email) && $m->correction->status === 'rejected');
    Mail::assertNotQueued(CorrectionRequestSubmitted::class, fn ($m) => $m->hasTo($hrUser->email));

    crDecide($hrUser, $c, 'hr')->assertSessionHas('error', 'This request was already rejected.');
    expect(Activity::where('description', 'Correction request rejected')->sole()->properties['note'])->toBe('You were on leave that day.');
});

it('lets the requester cancel only while nobody has decided', function () {
    [$user, , $managerUser] = crTeam();
    crSubmit($user);
    $c = crLatest();

    $this->actingAs($user)->from('/my/attendance')->post("/attendance/corrections/{$c->id}/cancel")
        ->assertRedirect('/my/attendance')->assertSessionHas('success');
    expect($c->fresh()->status)->toBe('cancelled');
    expect(Activity::where('description', 'Correction request cancelled')->sole()->causer_id)->toBe($user->id);

    // A decision after the cancellation is stale, not a 500.
    crDecide($managerUser, $c, 'manager')->assertSessionHas('error', 'This request was cancelled by the employee.');

    crSubmit($user);
    $second = crLatest();
    crDecide($managerUser, $second, 'manager');
    $this->actingAs($user)->post("/attendance/corrections/{$second->id}/cancel")
        ->assertSessionHas('error', 'This request was already approved by the manager. It can no longer be cancelled.');
    expect($second->fresh()->status)->toBe('manager_approved');
});

it('gives the second of two approvers a clear message and applies once', function () {
    [$user, $employee] = crPerson('employee');
    [$hrA] = crPerson('hr');
    [$hrB] = crPerson('hr');
    crSubmit($user);
    $c = crLatest();

    crDecide($hrA, $c, 'hr')->assertSessionHas('success');
    crDecide($hrB, $c, 'hr')->assertSessionHas('error', 'This request was already approved.');
    crDecide($hrB, $c, 'hr', 'reject', 'Late click')->assertSessionHas('error', 'This request was already approved.');

    expect($c->fresh()->hr_id)->toBe($hrA->id)
        ->and(Activity::where('description', 'Attendance corrected')->count())->toBe(1)
        ->and(Activity::where('description', 'Correction request approved')->count())->toBe(1);
    Mail::assertQueued(CorrectionRequestDecided::class, 1);
});

// ---------------------------------------------------------------- mail

it('keeps the request when the mail cannot be queued', function () {
    [$user] = crPerson('employee');
    crPerson('hr');
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Mail is not configured'));

    crSubmit($user)->assertSessionHas('success');
    expect(AttendanceCorrection::count())->toBe(1);
});

it('renders both mails with the requester\'s request only', function () {
    [$user, $employee] = crPerson('employee', ['first_name' => 'Ayesha', 'last_name' => 'Khan']);
    $c = AttendanceCorrection::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'requested_in' => '09:00', 'requested_out' => '18:00']);

    $submitted = (new CorrectionRequestSubmitted($c, 'Sara', false))->render();
    expect($submitted)->toContain('Ayesha Khan')->toContain('20 Nov 2026')->toContain('9:00 AM')->toContain('6:00 PM')
        ->toContain('/attendance/corrections');

    $c->forceFill(['status' => 'rejected', 'decision_note' => 'Not a working day.'])->save();
    $decided = new CorrectionRequestDecided($c, 'Ayesha');
    expect($decided->render())->toContain('rejected')->toContain('Not a working day.');
    expect($decided->envelope()->subject)->toBe('Your attendance correction request was rejected');
});
