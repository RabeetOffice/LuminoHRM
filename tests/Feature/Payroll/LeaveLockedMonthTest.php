<?php

use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Support\Leave\LeaveDays;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/*
| Phase 4's locked-month paths, now that PayrollLock is real: an approved payroll run of November 2026 refuses the final
| approval of leave in November and the cancelling of approved November leave. "Now" is 2026-12-03 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

it('refuses the final approval of leave in an approved payroll month', function () {
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $employee = employeeFor(userWithRole('employee'), ['joining_date' => '2026-01-01']);

    $plan = LeaveDays::plan($employee, '2026-11-23', '2026-11-24', null);
    $request = leaveRequest($employee, [
        'from_date' => '2026-11-23',
        'to_date' => '2026-11-24',
        'days' => $plan->total,
        'reason' => 'Family wedding out of town.',
    ]);

    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHas('error', 'Payroll for November 2026 is locked; leave in it cannot be approved.');

    expect($request->fresh()->status)->toBe('pending')
        ->and(LeaveDay::query()->where('leave_request_id', $request->id)->exists())->toBeFalse();
});

it('still approves the same leave while the November run is only a draft', function () {
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $employee = employeeFor(userWithRole('employee'), ['joining_date' => '2026-01-01']);

    $request = leaveRequest($employee, [
        'from_date' => '2026-11-23',
        'to_date' => '2026-11-24',
        'days' => LeaveDays::plan($employee, '2026-11-23', '2026-11-24', null)->total,
        'reason' => 'Family wedding out of town.',
    ]);

    payrollRun('2026-11');

    $this->actingAs($hr)->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHasNoErrors();

    expect($request->fresh()->status)->toBe('approved');
});

it('keeps the days of an approved payroll month when approved leave is cancelled', function () {
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $employee = employeeFor(userWithRole('employee'), ['joining_date' => '2026-01-01']);
    $request = approvedLeave($employee, '2026-11-30', '2026-12-04');

    payrollRun('2026-11', 'paid');

    // A day in the locked month cannot be chosen: the first cancellable day is 1 December.
    $this->actingAs($hr)->from("/leave/requests/{$request->id}")
        ->post("/leave/requests/{$request->id}/cancel-approved", ['note' => 'Came back early.', 'from_date' => '2026-11-30'])
        ->assertSessionHasErrors(['from_date' => 'Choose a day from Tue 1 Dec 2026 to Fri 4 Dec 2026.']);

    expect(LeaveDay::query()->where('leave_request_id', $request->id)->count())->toBe(5);

    // Cancelling from today (3 December) removes the later days only.
    $this->actingAs($hr)->from("/leave/requests/{$request->id}")
        ->post("/leave/requests/{$request->id}/cancel-approved", ['note' => 'Came back early.'])
        ->assertSessionHasNoErrors();

    expect(LeaveDay::query()->where('leave_request_id', $request->id)->orderBy('date')->get()->map(fn ($d) => $d->date->format('Y-m-d'))->all())
        ->toBe(['2026-11-30', '2026-12-01', '2026-12-02']);
});

it('cancels nothing of leave lying wholly in an approved payroll month', function () {
    $hr = userWithRole('hr');
    employeeFor($hr, ['joining_date' => '2026-01-01']);
    $employee = employeeFor(userWithRole('employee'), ['joining_date' => '2026-01-01']);
    $request = approvedLeave($employee, '2026-11-23', '2026-11-24');

    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->from("/leave/requests/{$request->id}")
        ->post("/leave/requests/{$request->id}/cancel-approved", ['note' => 'Came back early.'])
        ->assertSessionHas('error', 'No day of this leave can be cancelled any more.');

    expect(LeaveRequest::query()->find($request->id)->status)->toBe('approved')
        ->and(LeaveDay::query()->where('leave_request_id', $request->id)->count())->toBe(2);
});
