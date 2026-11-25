<?php

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveDay;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Support\Leave\LeaveBalances;
use App\Support\Leave\LeaveDays;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/*
| After 1 January, last year's open requests reserve the carry into this year (owner ruling, final fix round C1).
| "Now" is Sunday 2027-01-10 14:00 in Karachi. Annual: 14 a year, carry forward, cap 5. People join 2026-01-01.
| 2026: allocated 14, opening used 9 → 5 left, all 5 carried into 2027.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2027-01-10 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
    Mail::fake();
});

/** @return array{0: User, 1: Employee} */
function ytPerson(): array
{
    $user = userWithRole('employee');
    $employee = employeeFor($user, ['joining_date' => '2026-01-01']);
    $annual = leaveType('Annual');

    LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2026, 'allocated' => 14, 'adjustment' => 0, 'opening_used' => 9]);
    LeaveBalance::query()->create(['employee_id' => $employee->id, 'leave_type_id' => $annual->id, 'year' => 2027, 'allocated' => 14, 'adjustment' => 0, 'opening_used' => 0]);

    return [$user, $employee];
}

/** 19 approved days in 2027: everything 2027 holds (14 allocated + 5 carried). */
function ytBook2027(Employee $employee): void
{
    approvedLeave($employee, '2027-02-01', '2027-02-19');
}

/** A request for Mon 28 – Wed 30 Dec 2026 (3 counted days), in $status. */
function ytDecember(Employee $employee, string $status = 'manager_approved'): LeaveRequest
{
    return leaveRequest($employee, [
        'from_date' => '2026-12-28',
        'to_date' => '2026-12-30',
        'days' => 3,
        'status' => $status,
        'reason' => 'Year-end family trip.',
    ]);
}

it('lowers this year\'s carry while a December request of last year is still open', function () {
    [, $employee] = ytPerson();
    $balances = app(LeaveBalances::class);

    expect($balances->line($employee, leaveType(), 2027)->carried)->toBe(5.0);

    $request = ytDecember($employee);

    $line = $balances->line($employee, leaveType(), 2027);
    expect($line->carried)->toBe(2.0)
        ->and($line->available)->toBe(16.0)
        ->and($balances->line($employee, leaveType(), 2026)->available)->toBe(2.0);

    // Rejected or cancelled: the carry comes back.
    $request->forceFill(['status' => 'rejected'])->save();
    expect($balances->line($employee, leaveType(), 2027)->carried)->toBe(5.0);
});

it('refuses HR approving a December request in January when it would overdraw the new year through the carry', function () {
    [, $employee] = ytPerson();
    ytBook2027($employee);
    $request = ytDecember($employee);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'what carries into 2027'));

    expect($request->fresh()->status)->toBe('manager_approved')
        ->and(LeaveDay::query()->where('leave_request_id', $request->id)->count())->toBe(0);

    // While it waits it holds 3 days of the carry (2027 shows them reserved); rejected, 2027 is whole again.
    expect(app(LeaveBalances::class)->line($employee, leaveType(), 2027)->carried)->toBe(2.0);
    $request->forceFill(['status' => 'rejected'])->save();
    $line = app(LeaveBalances::class)->line($employee, leaveType(), 2027);
    expect($line->remaining)->toBe(0.0)->and($line->overdrawn)->toBeFalse();
});

it('approves the December request in January when the new year can still afford the lower carry', function () {
    [, $employee] = ytPerson();
    approvedLeave($employee, '2027-02-01', '2027-02-16'); // 16 of 19: 3 spare
    $request = ytDecember($employee);

    $this->actingAs(userWithRole('hr'))->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHas('success');

    expect($request->fresh()->status)->toBe('approved');
    $line = app(LeaveBalances::class)->line($employee, leaveType(), 2027);
    expect($line->carried)->toBe(2.0)->and($line->remaining)->toBe(0.0);
});

it('refuses applying backdated into December in January when it would overdraw the new year', function () {
    [$user, $employee] = ytPerson();
    ytBook2027($employee);

    $this->actingAs($user)->from('/my/leaves')->post('/leave/requests', [
        'leave_type_id' => leaveType()->id,
        'from_date' => '2026-12-28',
        'to_date' => '2026-12-30',
        'half' => null,
        'reason' => 'Forgot to apply for these days.',
    ])->assertSessionHasErrors(['days' => 'Not enough Annual leave: these days would be taken from what carries into 2027, and 2027 would end 3 days short. Apply for fewer days or as Unpaid leave.']);

    expect(LeaveRequest::query()->where('employee_id', $employee->id)->where('from_date', '2026-12-28')->count())->toBe(0);
});

it('shows the reduced available for this year in the preview while a December request is open', function () {
    [$user, $employee] = ytPerson();
    ytDecember($employee, 'pending');

    $json = $this->actingAs($user)->getJson('/leave/requests/preview?'.http_build_query([
        'type' => leaveType()->id, 'from' => '2027-03-01', 'to' => '2027-03-02',
    ]))->assertOk()->json();

    expect($json['years'])->toEqual([['year' => 2027, 'asked' => 2, 'available' => 16]])
        ->and($json['refusal'])->toBeNull();

    // And the December request's own preview (backdated) still fits 2026.
    expect(LeaveDays::plan($employee, '2026-12-28', '2026-12-30', null)->total)->toBe(3.0);
});

it('checks a later year with approved leave days even when it has no balance row yet', function () {
    [, $employee] = ytPerson();
    LeaveBalance::query()->where('employee_id', $employee->id)->where('year', 2027)->delete();
    ytBook2027($employee); // 19 approved days in 2027, no 2027 row (written directly)
    $request = ytDecember($employee);

    expect(app(LeaveBalances::class)->lastYearInUse($employee, leaveType()))->toBe(2027);

    $this->actingAs(userWithRole('hr'))->from('/leave/requests')
        ->put("/leave/requests/{$request->id}/final-decide", ['decision' => 'approve', 'note' => null])
        ->assertSessionHas('error', fn (string $m) => str_contains($m, 'what carries into 2027'));
});
