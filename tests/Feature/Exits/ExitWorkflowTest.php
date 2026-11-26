<?php

use App\Actions\Exits\DecideExit;
use App\Actions\Exits\RecordExit;
use App\Actions\Exits\SubmitResignation;
use App\Actions\Exits\WithdrawResignation;
use App\Models\Clearance;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| The exit workflow through its actions: submit, record, manager step, final step, reject, withdraw. "Now" is Wednesday
| 2026-11-25 14:00 in Karachi; the notice period is the default 30 days.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-25 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @return array{0: User, 1: Employee} */
function xwPerson(string $role = 'employee', array $attrs = []): array
{
    $user = userWithRole($role);

    return [$user, employeeFor($user, ['joining_date' => '2026-01-01', ...$attrs])];
}

/** An employee with a manager who can decide the manager step. @return array{0: User, 1: Employee, 2: User, 3: Employee} */
function xwTeam(): array
{
    [$managerUser, $manager] = xwPerson('manager');
    [$user, $employee] = xwPerson('employee', ['manager_id' => $manager->id]);

    return [$user, $employee, $managerUser, $manager];
}

function xwRefusal(callable $run, string $message, ?string $key = null): void
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

function xwEntries(string $description)
{
    return Activity::where('log_name', 'exit')->where('description', $description)->orderBy('id')->get();
}

// ---------------------------------------------------------------- submit

it('submits a resignation to the manager with the notice period copied and audited', function () {
    [$user, $employee] = xwTeam();
    Settings::set('payroll', ['notice_period_days' => 45]);

    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', '  Moving abroad.  ');

    expect($exit->status)->toBe('pending')
        ->and($exit->type)->toBe('resignation')
        ->and($exit->manager_skipped)->toBeFalse()
        ->and($exit->employee_id)->toBe($employee->id)
        ->and($exit->open_employee_id)->toBe($employee->id)
        ->and($exit->submitted_on->format('Y-m-d'))->toBe('2026-11-25')
        ->and($exit->last_working_day->format('Y-m-d'))->toBe('2026-12-20')
        ->and($exit->reason)->toBe('Moving abroad.')
        ->and($exit->notice_period_days)->toBe(45)
        ->and($exit->stageLabel())->toBe('With manager');

    // The employee record is untouched until the final approval.
    expect($employee->fresh()->last_working_day)->toBeNull()->and($employee->fresh()->status)->toBe('active');

    $entry = xwEntries('Resignation submitted')->sole();
    expect($entry->causer_id)->toBe($user->id)
        ->and($entry->subject_id)->toBe($exit->id)
        ->and($entry->properties['notice_given'])->toBe(26)
        ->and($entry->properties['notice_required'])->toBe(45)
        ->and($entry->properties['last_working_day'])->toBe('2026-12-20');
});

it('sends a resignation straight to HR when the person has no manager', function () {
    [$user] = xwPerson();

    $exit = app(SubmitResignation::class)->handle($user, '2026-11-25', 'Leaving today.');

    expect($exit->status)->toBe('manager_approved')
        ->and($exit->manager_skipped)->toBeTrue()
        ->and($exit->stageLabel())->toBe('With HR');
});

it('lets a person on probation resign but not someone who has already left', function () {
    [$probation] = xwPerson('employee', ['status' => 'probation']);
    expect(app(SubmitResignation::class)->handle($probation, '2026-12-01', 'Reason.')->status)->toBe('manager_approved');

    [$left] = xwPerson('employee', ['status' => 'inactive']);
    xwRefusal(fn () => app(SubmitResignation::class)->handle($left, '2026-12-01', 'Reason.'), SubmitResignation::NOT_ACTIVE);
});

it('refuses a resignation without an employee record', function () {
    $user = userWithRole('employee');

    xwRefusal(fn () => app(SubmitResignation::class)->handle($user, '2026-12-01', 'Reason.'), 'Only employees with an employee record can resign.');
    expect(Resignation::query()->count())->toBe(0);
});

it('allows one open resignation at a time and a new one after it ends', function () {
    [$user] = xwPerson();
    $first = app(SubmitResignation::class)->handle($user, '2026-12-01', 'Reason.');

    xwRefusal(fn () => app(SubmitResignation::class)->handle($user, '2026-12-02', 'Again.'), 'You already have an open resignation.');

    app(WithdrawResignation::class)->handle($user, $first);

    expect(app(SubmitResignation::class)->handle($user, '2026-12-02', 'Again.')->status)->toBe('manager_approved')
        ->and(Resignation::query()->count())->toBe(2);
});

it('refuses a last working day before today, before joining, or more than a year ahead', function () {
    [$user, $employee] = xwPerson();
    $submit = fn (string $day) => fn () => app(SubmitResignation::class)->handle($user, $day, 'Reason.');

    xwRefusal($submit('2026-11-24'), 'The last working day cannot be before today.', 'last_working_day');
    xwRefusal($submit('2026-11-25'.'x'), 'Enter a valid date.', 'last_working_day');
    xwRefusal($submit('2027-11-26'), 'The last working day can be at most 365 days ahead.', 'last_working_day');

    // Today and exactly 365 days ahead are allowed.
    expect($submit('2027-11-25')())->toBeInstanceOf(Resignation::class);

    // A person who joins after the last working day asked for.
    [$late, $lateEmployee] = xwPerson('employee', ['joining_date' => '2026-12-01']);
    $joined = $lateEmployee->joining_date->format((string) settings('company.date_format'));
    xwRefusal(fn () => app(SubmitResignation::class)->handle($late, '2026-11-30', 'Reason.'), "The last working day cannot be before the joining date ({$joined}).", 'last_working_day');

    expect($employee->fresh()->last_working_day)->toBeNull();
});

it('refuses an empty or over-long reason', function () {
    [$user] = xwPerson();

    xwRefusal(fn () => app(SubmitResignation::class)->handle($user, '2026-12-01', '   '), 'Enter a reason.', 'reason');
    xwRefusal(fn () => app(SubmitResignation::class)->handle($user, '2026-12-01', str_repeat('a', 1001)), SubmitResignation::REASON_TOO_LONG, 'reason');

    expect(app(SubmitResignation::class)->handle($user, '2026-12-01', str_repeat('a', 1000))->reason)->toHaveLength(1000);
});

// ---------------------------------------------------------------- manager step and final step

it('moves a pending exit to HR on the manager approval and audits it', function () {
    [$user, $employee, $managerUser] = xwTeam();
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');

    $exit = app(DecideExit::class)->approve($managerUser, $exit);

    expect($exit->status)->toBe('manager_approved')
        ->and($exit->manager_decided_by)->toBe($managerUser->id)
        ->and($exit->manager_decided_at)->not->toBeNull()
        ->and($exit->manager_skipped)->toBeFalse()
        ->and($exit->open_employee_id)->toBe($employee->id);
    expect($employee->fresh()->last_working_day)->toBeNull();

    $entry = xwEntries('Exit approved by manager')->sole();
    expect($entry->causer_id)->toBe($managerUser->id)->and($entry->properties['step'])->toBe('manager');
});

it('approves finally: day on the employee, clearance rows from the settings snapshot, status unchanged, audited', function () {
    [$user, $employee, $managerUser] = xwTeam();
    Settings::set('exit', ['clearance_items' => ['Return ID card', 'Hand over keys']]);
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');
    app(DecideExit::class)->approve($managerUser, $exit);
    $hr = userWithRole('hr');

    $exit = app(DecideExit::class)->approve($hr, $exit->fresh());

    expect($exit->status)->toBe('approved')
        ->and($exit->final_decided_by)->toBe($hr->id)
        ->and($exit->own_decision)->toBeFalse()
        ->and($exit->manager_skipped)->toBeFalse();
    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-20')
        ->and($employee->fresh()->status)->toBe('active');

    $rows = Clearance::query()->where('resignation_id', $exit->id)->orderBy('sort_order')->get();
    expect($rows->pluck('label')->all())->toBe(['Assets returned', 'System access removed', 'Dues cleared', 'Return ID card', 'Hand over keys'])
        ->and($rows->pluck('kind')->all())->toBe(['assets', 'access', 'dues', 'custom', 'custom'])
        ->and($rows->every(fn (Clearance $c) => $c->cleared_at === null))->toBeTrue();

    // A later settings edit does not touch an exit that already has its list.
    Settings::set('exit', ['clearance_items' => ['Something else']]);
    expect(Clearance::query()->where('resignation_id', $exit->id)->count())->toBe(5);

    $entry = xwEntries('Exit approved')->sole();
    expect($entry->properties['old']['last_working_day'])->toBeNull()
        ->and($entry->properties['attributes']['last_working_day'])->toBe('2026-12-20')
        ->and($entry->properties['own'])->toBeFalse();
});

it('lets HR approve an exit still waiting for the manager and marks the manager step skipped', function () {
    [$user] = xwTeam();
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');

    $exit = app(DecideExit::class)->approve(userWithRole('hr'), $exit);

    expect($exit->status)->toBe('approved')->and($exit->manager_skipped)->toBeTrue();
});

it('rejects with a required note at the manager step and at the final step, and frees the person', function () {
    [$user, , $managerUser] = xwTeam();
    $hr = userWithRole('hr');

    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');
    xwRefusal(fn () => app(DecideExit::class)->reject($managerUser, $exit, '  '), 'Enter a note to reject this exit.', 'note');
    expect($exit->fresh()->status)->toBe('pending');

    $exit = app(DecideExit::class)->reject($managerUser, $exit, 'We need you through year end.');
    expect($exit->status)->toBe('rejected')
        ->and($exit->decision_note)->toBe('We need you through year end.')
        ->and($exit->manager_decided_by)->toBe($managerUser->id)
        ->and($exit->open_employee_id)->toBeNull();
    expect(xwEntries('Exit rejected')->first()->properties['step'])->toBe('manager');

    // The person may resign again; HR rejects at the final step.
    $second = app(SubmitResignation::class)->handle($user, '2026-12-22', 'Reason again.');
    app(DecideExit::class)->approve($managerUser, $second);
    $second = app(DecideExit::class)->reject($hr, $second->fresh(), 'Not accepted.');

    expect($second->status)->toBe('rejected')->and($second->final_decided_by)->toBe($hr->id);
    expect(xwEntries('Exit rejected')->last()->properties['step'])->toBe('final');
});

it('refuses a decision on an exit that has ended or is not waiting for the user', function () {
    [$user, , $managerUser] = xwTeam();
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');
    $withdrawn = app(WithdrawResignation::class)->handle($user, $exit);

    xwRefusal(fn () => app(DecideExit::class)->approve($managerUser, $withdrawn), 'This exit has ended and cannot change.');

    // A manager who is not this person's manager, and an exit already approved by the manager.
    [$other] = xwPerson('manager');
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');
    xwRefusal(fn () => app(DecideExit::class)->approve($other, $exit), 'This exit is not waiting for your decision.');

    app(DecideExit::class)->approve($managerUser, $exit);
    xwRefusal(fn () => app(DecideExit::class)->approve($managerUser, $exit->fresh()), 'This exit is not waiting for your decision.');

    // The employee cannot decide their own exit; HR cannot either.
    xwRefusal(fn () => app(DecideExit::class)->approve($user, $exit->fresh()), 'This exit is not waiting for your decision.');
});

it('refuses HR deciding their own exit and an HR person\'s exit by another HR person', function () {
    [$hrUser] = xwPerson('hr');
    $exit = app(SubmitResignation::class)->handle($hrUser, '2026-12-20', 'Reason.');
    [$otherHr] = xwPerson('hr');

    xwRefusal(fn () => app(DecideExit::class)->approve($hrUser, $exit), 'You cannot decide your own exit.');
    xwRefusal(fn () => app(DecideExit::class)->approve($otherHr, $exit), 'Only an Admin can decide the exit of an Admin or HR person.');

    expect(app(DecideExit::class)->approve(userWithRole('admin'), $exit)->status)->toBe('approved');
});

// ---------------------------------------------------------------- withdraw

it('lets the employee withdraw before the final approval, audited', function () {
    [$user, $employee] = xwTeam();
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');

    $exit = app(WithdrawResignation::class)->handle($user, $exit);

    expect($exit->status)->toBe('withdrawn')
        ->and($exit->withdrawn_at)->not->toBeNull()
        ->and($exit->open_employee_id)->toBeNull();
    expect(xwEntries('Resignation withdrawn')->sole()->causer_id)->toBe($user->id);
    expect($employee->fresh()->openResignation)->toBeNull();
});

it('refuses a withdrawal after the final approval, by anyone else, and of an ended exit', function () {
    [$user, , $managerUser] = xwTeam();
    $exit = app(SubmitResignation::class)->handle($user, '2026-12-20', 'Reason.');

    // HR may not withdraw on the employee's behalf; neither may the manager.
    expect(fn () => app(WithdrawResignation::class)->handle(userWithRole('hr'), $exit))->toThrow(AuthorizationException::class);
    expect(fn () => app(WithdrawResignation::class)->handle($managerUser, $exit))->toThrow(AuthorizationException::class);

    app(DecideExit::class)->approve(userWithRole('hr'), $exit);
    xwRefusal(fn () => app(WithdrawResignation::class)->handle($user, $exit->fresh()), 'This resignation can no longer be withdrawn; ask HR.');

    [$other] = xwPerson();
    $ended = app(SubmitResignation::class)->handle($other, '2026-12-20', 'Reason.');
    app(WithdrawResignation::class)->handle($other, $ended);
    xwRefusal(fn () => app(WithdrawResignation::class)->handle($other, $ended->fresh()), 'This exit has ended and cannot change.');
});

// ---------------------------------------------------------------- HR records an exit

it('records a termination with approve now: approved at once, manager step skipped, day set', function () {
    [, $employee] = xwTeam();
    $hr = userWithRole('hr');

    $exit = app(RecordExit::class)->handle($hr, $employee, 'termination', '2026-12-01', 'Policy breach.', '2026-11-25', true);

    expect($exit->status)->toBe('approved')
        ->and($exit->type)->toBe('termination')
        ->and($exit->manager_skipped)->toBeTrue()
        ->and($exit->recorded_by)->toBe($hr->id)
        ->and($exit->final_decided_by)->toBe($hr->id);
    expect($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-01')
        ->and(Clearance::query()->where('resignation_id', $exit->id)->count())->toBe(3);

    $recorded = xwEntries('Exit recorded')->sole();
    expect($recorded->properties['type'])->toBe('termination')->and($recorded->properties['approve_now'])->toBeTrue();
    expect(xwEntries('Exit approved'))->toHaveCount(1);
});

it('records an exit at the HR step when approve now is off, with a back-dated submission', function () {
    [, $employee] = xwPerson();

    $exit = app(RecordExit::class)->handle(userWithRole('hr'), $employee, 'resignation', '2026-12-10', 'Verbal resignation.', '2026-11-01', false);

    expect($exit->status)->toBe('manager_approved')
        ->and($exit->submitted_on->format('Y-m-d'))->toBe('2026-11-01')
        ->and($exit->manager_skipped)->toBeTrue();
    expect($employee->fresh()->last_working_day)->toBeNull();
    expect(xwEntries('Exit recorded')->sole()->properties['notice_given'])->toBe(40);
});

it('allows a past last working day when HR records an exit', function () {
    [, $employee] = xwPerson();

    $exit = app(RecordExit::class)->handle(userWithRole('hr'), $employee, 'termination', '2026-11-20', 'Absconded.', '2026-11-25', true);

    expect($exit->status)->toBe('approved')
        ->and($employee->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-11-20');
});

it('refuses recording for oneself, over an open exit, and an Admin or HR person\'s exit by HR', function () {
    [$hrUser, $hrEmployee] = xwPerson('hr');
    [, $employee] = xwPerson();
    $record = fn (User $by, Employee $for, array $o = []) => fn () => app(RecordExit::class)->handle($by, $for, $o['type'] ?? 'resignation', $o['day'] ?? '2026-12-10', 'Reason.', '2026-11-25', false);

    xwRefusal($record($hrUser, $hrEmployee), 'You cannot record an exit for yourself.');
    xwRefusal($record(userWithRole('hr'), $hrEmployee), RecordExit::PRIVILEGED);
    expect($record(userWithRole('admin'), $hrEmployee)()->status)->toBe('manager_approved');

    $record(userWithRole('hr'), $employee)();
    xwRefusal($record(userWithRole('hr'), $employee), "{$employee->full_name} already has an open exit.");

    expect(fn () => app(RecordExit::class)->handle(userWithRole('manager'), $employee, 'resignation', '2026-12-10', 'Reason.', '2026-11-25', false))
        ->toThrow(AuthorizationException::class);
});

it('validates what HR records: type, reason, dates', function () {
    [, $employee] = xwPerson('employee', ['joining_date' => '2026-06-01']);
    $hr = userWithRole('hr');
    $record = fn (string $type = 'resignation', string $day = '2026-12-10', string $reason = 'Reason.', string $submitted = '2026-11-25') => fn () => app(RecordExit::class)->handle($hr, $employee, $type, $day, $reason, $submitted, false);

    xwRefusal($record(type: 'sacked'), 'Choose resignation or termination.', 'type');
    xwRefusal($record(reason: ''), 'Enter a reason.', 'reason');
    xwRefusal($record(day: '2026-05-31'), 'The last working day cannot be before the joining date ('.$employee->joining_date->format((string) settings('company.date_format')).').', 'last_working_day');
    xwRefusal($record(day: '2027-11-26'), 'The last working day can be at most 365 days ahead.', 'last_working_day');
    xwRefusal($record(submitted: '2026-11-26'), 'The submitted date cannot be in the future.', 'submitted_on');
    expect(Resignation::query()->count())->toBe(0);
});
