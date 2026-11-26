<?php

use App\Actions\Employees\ChangeEmployeeStatus;
use App\Actions\Exits\CancelExit;
use App\Actions\Exits\ChangeExitLastDay;
use App\Actions\Exits\DecideExit;
use App\Actions\Exits\RecordExit;
use App\Exceptions\AccountRuleViolation;
use App\Models\Attendance;
use App\Models\Clearance;
use App\Models\Employee;
use App\Models\FinalSettlement;
use App\Models\FinalSettlementLine;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Leave\LeaveBalances;
use App\Support\PayrollLock;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| The last working day on the employee record through EmploymentEnd: approval, change, cancel, the payroll lock, the
| profile guard and the system (null) actor of ChangeEmployeeStatus. "Now" is Wednesday 2026-11-25 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @return array{0: User, 1: Employee} */
function xlPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** @return list<string> */
function xlDates(LeaveRequest $r): array
{
    return LeaveDay::query()->where('leave_request_id', $r->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all();
}

/** An exit HR recorded and approved through the actions (so the employee's day went through EmploymentEnd). */
function xlApproved(User $hr, Employee $employee, string $day): Resignation
{
    return app(RecordExit::class)->handle($hr, $employee, 'resignation', $day, 'Reason.', '2026-11-25', true);
}

function xlRefusal(callable $run, string $message, ?string $key = null): void
{
    try {
        $run();
    } catch (ValidationException $e) {
        if ($key !== null) {
            expect($e->errors())->toHaveKey($key);
        }

        expect(collect($e->errors())->flatten()->all())->toContain($message);

        return;
    }

    throw new RuntimeException("Expected a refusal: {$message}");
}

// ---------------------------------------------------------------- final approval

it('sets the day on approval: approved leave after it is removed and refunded, open requests cancelled, attendance marked', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $partly = approvedLeave($employee, '2026-12-03', '2026-12-08');
    $whole = approvedLeave($employee, '2026-12-14', '2026-12-15');
    $open = leaveRequest($employee, ['from_date' => '2026-12-21', 'to_date' => '2026-12-22', 'days' => 2]);
    $after = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-12-10', 'status' => 'present', 'closed' => true]);
    $before = Attendance::factory()->create(['employee_id' => $employee->id, 'date' => '2026-11-20', 'status' => 'present', 'closed' => true]);

    xlApproved($hr, $employee, '2026-12-04');

    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-04')
        ->and($employee->fresh()->status)->toBe('active');
    expect(xlDates($partly->fresh()))->toBe(['2026-12-03', '2026-12-04'])
        ->and($partly->fresh()->status)->toBe('approved')
        ->and($whole->fresh()->status)->toBe('cancelled')
        ->and($open->fresh()->status)->toBe('cancelled');
    // Two days stay used; the four removed from the first request and the two of the second are refunded.
    expect(app(LeaveBalances::class)->line($employee->fresh(), leaveType(), 2026)->used)->toBe(2.0);
    expect($after->fresh()->needs_rebuild)->toBeTruthy()->and($before->fresh()->needs_rebuild)->toBeFalsy();

    $trimmed = Activity::where('log_name', 'leave')->where('description', 'Leave trimmed to employment')->get();
    expect($trimmed)->toHaveCount(3)->and($trimmed->every(fn ($e) => $e->causer_id === $hr->id))->toBeTrue();
});

it('refuses the approval and saves nothing when the day touches a locked payroll month', function () {
    [, $employee] = xlPerson();
    payrollRun('2026-11', 'approved');
    $hr = userWithRole('hr');

    // Recording with approve now: the whole request is refused, the exit is not even written.
    xlRefusal(fn () => xlApproved($hr, $employee, '2026-11-20'), PayrollLock::message('2026-11'), 'last_working_day');
    expect(Resignation::query()->count())->toBe(0)->and($employee->fresh()->last_working_day)->toBeNull();

    // Approving an exit that waits for HR: refused, it stays where it was and no clearance rows exist.
    $exit = app(RecordExit::class)->handle($hr, $employee, 'resignation', '2026-11-20', 'Reason.', '2026-11-25', false);
    xlRefusal(fn () => app(DecideExit::class)->approve($hr, $exit), PayrollLock::message('2026-11'), 'last_working_day');

    expect($exit->fresh()->status)->toBe('manager_approved')
        ->and($employee->fresh()->last_working_day)->toBeNull()
        ->and(Clearance::query()->count())->toBe(0);
});

// ---------------------------------------------------------------- change the day

it('moves the day: the employee, the leave trim, a draft settlement made stale and following the month, audited', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-12-10');
    $leave = approvedLeave($employee, '2026-12-03', '2026-12-08');
    $draft = settlementRow($exit);
    FinalSettlementLine::factory()->create(['final_settlement_id' => $draft->id]);

    $moved = app(ChangeExitLastDay::class)->handle($hr, $exit, '2026-12-04');

    expect($moved->last_working_day->format('Y-m-d'))->toBe('2026-12-04')
        ->and($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-04')
        ->and(xlDates($leave->fresh()))->toBe(['2026-12-03', '2026-12-04']);
    expect($draft->fresh()->stale)->toBeTrue()->and($draft->fresh()->month)->toBe('2026-12');

    $entry = Activity::where('log_name', 'exit')->where('description', 'Exit last working day changed')->sole();
    expect($entry->properties['old']['last_working_day'])->toBe('2026-12-10')
        ->and($entry->properties['attributes']['last_working_day'])->toBe('2026-12-04')
        ->and($entry->causer_id)->toBe($hr->id);

    // The draft's month follows a day in another month.
    app(ChangeExitLastDay::class)->handle($hr, $moved, '2027-01-05');
    expect($draft->fresh()->month)->toBe('2027-01');
});

it('does nothing and writes no entry when the day is the same', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-12-10');

    app(ChangeExitLastDay::class)->handle($hr, $exit, '2026-12-10');

    expect(Activity::where('description', 'Exit last working day changed')->count())->toBe(0);
});

it('refuses a day change on an exit that is not approved, ended, settled, or by someone who may not manage it', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');

    $waiting = app(RecordExit::class)->handle($hr, $employee, 'resignation', '2026-12-10', 'Reason.', '2026-11-25', false);
    xlRefusal(fn () => app(ChangeExitLastDay::class)->handle($hr, $waiting, '2026-12-12'), ChangeExitLastDay::NOT_APPROVED);

    $approved = app(DecideExit::class)->approve($hr, $waiting);
    settlementRow($approved, ['status' => 'approved']);
    xlRefusal(fn () => app(ChangeExitLastDay::class)->handle($hr, $approved, '2026-12-12'), 'The final settlement is approved, so this exit can no longer change.');

    [, $other] = xlPerson();
    $ended = resignationRow($other, ['status' => 'rejected']);
    xlRefusal(fn () => app(ChangeExitLastDay::class)->handle($hr, $ended, '2026-12-12'), 'This exit has ended and cannot change.');

    $own = userWithRole('hr');
    $ownEmployee = employeeFor($own, ['joining_date' => '2026-01-01']);
    $ownExit = approvedExit($ownEmployee, '2026-12-10');
    expect(fn () => app(ChangeExitLastDay::class)->handle($own, $ownExit, '2026-12-12'))->toThrow(AuthorizationException::class);
    expect(fn () => app(ChangeExitLastDay::class)->handle(userWithRole('manager'), $approved, '2026-12-12'))->toThrow(AuthorizationException::class);
});

it('applies the recording rules to a changed day and the payroll lock to its window', function () {
    [, $employee] = xlPerson('employee', ['joining_date' => '2026-06-01']);
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-12-10');
    $change = fn (string $day) => fn () => app(ChangeExitLastDay::class)->handle($hr, $exit->fresh(), $day);

    xlRefusal($change('2026-05-31'), 'The last working day cannot be before the joining date ('.$employee->joining_date->format((string) settings('company.date_format')).').', 'last_working_day');
    xlRefusal($change('2027-11-26'), 'The last working day can be at most 365 days ahead.', 'last_working_day');

    // A past day is allowed ...
    expect($change('2026-11-20')()->last_working_day->format('Y-m-d'))->toBe('2026-11-20');

    // ... until a payroll month in the window it adds or removes is approved.
    payrollRun('2026-11', 'approved');
    xlRefusal($change('2026-12-10'), PayrollLock::message('2026-11'), 'last_working_day');
    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-11-20');
});

it('lets the day move only to a date before today once the status has changed', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-11-20');
    $exit->forceFill(['status_applied_at' => now()])->save();
    $employee->forceFill(['status' => 'resigned'])->save();

    xlRefusal(fn () => app(ChangeExitLastDay::class)->handle($hr, $exit->fresh(), '2026-11-25'), ChangeExitLastDay::STATUS_APPLIED, 'last_working_day');
    xlRefusal(fn () => app(ChangeExitLastDay::class)->handle($hr, $exit->fresh(), '2026-12-05'), ChangeExitLastDay::STATUS_APPLIED, 'last_working_day');

    expect(app(ChangeExitLastDay::class)->handle($hr, $exit->fresh(), '2026-11-18')->last_working_day->format('Y-m-d'))->toBe('2026-11-18');
});

// ---------------------------------------------------------------- cancel

it('cancels an approved exit: the day cleared, the draft settlement and its lines deleted, clearance kept, leave not restored', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $leave = approvedLeave($employee, '2026-12-03', '2026-12-08');
    $exit = xlApproved($hr, $employee, '2026-12-04');
    expect(xlDates($leave->fresh()))->toBe(['2026-12-03', '2026-12-04']);

    $draft = settlementRow($exit);
    FinalSettlementLine::factory()->count(2)->create(['final_settlement_id' => $draft->id]);

    $action = app(CancelExit::class);
    $cancelled = $action->handle($hr, $exit, 'Employee stays.');

    expect($cancelled->status)->toBe('cancelled')
        ->and($cancelled->cancelled_by)->toBe($hr->id)
        ->and($cancelled->cancel_reason)->toBe('Employee stays.')
        ->and($cancelled->open_employee_id)->toBeNull();
    expect($employee->fresh()->last_working_day)->toBeNull();
    expect(FinalSettlementLine::query()->count())->toBe(0)
        ->and(FinalSettlement::query()->count())->toBe(0)
        ->and(Clearance::query()->where('resignation_id', $exit->id)->count())->toBe(3);
    // Leave cancelled for the old day stays cancelled; HR is told.
    expect(xlDates($leave->fresh()))->toBe(['2026-12-03', '2026-12-04'])
        ->and($action->notices())->toContain(CancelExit::LEAVE_STAYS);

    $gone = Activity::where('log_name', 'exit')->where('description', 'Settlement draft deleted')->sole();
    expect($gone->properties['lines'])->toBe(2)->and($gone->properties['month'])->toBe('2026-12');
    $entry = Activity::where('log_name', 'exit')->where('description', 'Exit cancelled')->sole();
    expect($entry->properties['reason'])->toBe('Employee stays.')
        ->and($entry->properties['old']['last_working_day'])->toBe('2026-12-04');
});

it('refuses a cancel without a reason, of an exit not approved, settled, or after the person has left', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $waiting = app(RecordExit::class)->handle($hr, $employee, 'resignation', '2026-12-10', 'Reason.', '2026-11-25', false);

    xlRefusal(fn () => app(CancelExit::class)->handle($hr, $waiting, '  '), 'Enter a reason.', 'reason');
    xlRefusal(fn () => app(CancelExit::class)->handle($hr, $waiting, 'No.'), CancelExit::NOT_APPROVED);

    $approved = app(DecideExit::class)->approve($hr, $waiting);
    $settlement = settlementRow($approved, ['status' => 'approved']);
    xlRefusal(fn () => app(CancelExit::class)->handle($hr, $approved, 'No.'), 'The final settlement is approved, so this exit can no longer change.');
    $settlement->forceFill(['status' => 'draft'])->save();

    // The nightly job has changed the status: "{name} has already left (status Resigned)".
    $employee->forceFill(['status' => 'resigned'])->save();
    xlRefusal(fn () => app(CancelExit::class)->handle($hr, $approved, 'No.'), "{$employee->full_name} has already left (status Resigned), so the exit cannot be cancelled.");
});

it('refuses a cancel whose cleared day would touch a locked payroll month, and from someone who may not manage the exit', function () {
    [, $employee] = xlPerson();
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-12-10');
    payrollRun('2026-12', 'approved');

    xlRefusal(fn () => app(CancelExit::class)->handle($hr, $exit, 'No.'), PayrollLock::message('2026-12'), 'last_working_day');
    expect($exit->fresh()->status)->toBe('approved')->and($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-10');

    expect(fn () => app(CancelExit::class)->handle(userWithRole('manager'), $exit, 'No.'))->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------- profile guard and the system actor

it('refuses to set, move or clear the day on the profile while an exit is open, and allows a status change that keeps it', function () {
    [, $employee] = xlPerson();
    $admin = userWithRole('admin');
    xlApproved(userWithRole('hr'), $employee, '2026-12-10');
    $message = 'This person has an open exit. Change the last working day on the exit page.';

    $this->actingAs($admin)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-12-15'])
        ->assertSessionHasErrors(['last_working_day' => $message]);
    $this->actingAs($admin)->patch("/employees/{$employee->id}/status", ['status' => 'active'])
        ->assertSessionHasErrors(['last_working_day' => $message]);
    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-10')->and($employee->fresh()->status)->toBe('active');

    // The same day: allowed (the status moves, the day stays, the exit stays open).
    $this->actingAs($admin)->patch("/employees/{$employee->id}/status", ['status' => 'inactive', 'last_working_day' => '2026-12-10'])
        ->assertSessionHasNoErrors();
    expect($employee->fresh()->status)->toBe('inactive')->and($employee->fresh()->openResignation)->not->toBeNull();
});

it('lets the profile change the day again once the exit has ended', function () {
    [, $employee] = xlPerson();
    $admin = userWithRole('admin');
    $hr = userWithRole('hr');
    $exit = xlApproved($hr, $employee, '2026-12-10');
    app(CancelExit::class)->handle($hr, $exit, 'Employee stays.');

    $this->actingAs($admin)->patch("/employees/{$employee->id}/status", ['status' => 'resigned', 'last_working_day' => '2026-12-15'])
        ->assertSessionHasNoErrors();

    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-15');
});

it('changes a status as the system with a null actor: login disabled, no causer, the Admin / HR rule skipped', function () {
    [$hrUser, $hrEmployee] = xlPerson('hr');
    $approvedLeave = approvedLeave($hrEmployee, '2026-12-03', '2026-12-08');

    $change = app(ChangeEmployeeStatus::class);
    $change->handle($hrEmployee, 'resigned', '2026-12-04', null);

    expect($hrEmployee->fresh()->status)->toBe('resigned')
        ->and($hrEmployee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-04')
        ->and($hrUser->fresh()->is_active)->toBeFalse()
        ->and($change->changed())->toBeTrue()
        ->and(xlDates($approvedLeave->fresh()))->toBe(['2026-12-03', '2026-12-04']);

    $entry = Activity::where('log_name', 'employees')->where('description', 'Employee status changed')->sole();
    expect($entry->causer_id)->toBeNull()
        ->and($entry->properties['attributes'])->toBe(['status' => 'resigned', 'last_working_day' => '2026-12-04']);

    // The same call again is a no-op.
    $change->handle($hrEmployee->fresh(), 'resigned', '2026-12-04', null);
    expect($change->changed())->toBeFalse();
});

it('keeps the Admin / HR rule for a signed-in actor and the payroll lock for the profile screen', function () {
    [, $hrEmployee] = xlPerson('hr');
    [, $worker] = xlPerson();
    $hr = userWithRole('hr');

    expect(fn () => app(ChangeEmployeeStatus::class)->handle($hrEmployee, 'inactive', null, $hr))
        ->toThrow(AccountRuleViolation::class);

    payrollRun('2026-11', 'approved');
    xlRefusal(fn () => app(ChangeEmployeeStatus::class)->handle($worker, 'resigned', '2026-11-20', userWithRole('admin')), PayrollLock::message('2026-11'), 'last_working_day');
    expect($worker->fresh()->status)->toBe('active');
});
