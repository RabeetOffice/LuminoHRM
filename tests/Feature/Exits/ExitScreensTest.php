<?php

use App\Models\Employee;
use App\Models\Resignation;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/screen-helpers.php';

// The Resignations list (tabs, filters, fixed query count), the record dialog's endpoints and the exit page per role.
// Written for Task 3 of Phase 6c; not run by the author (owner override), so expect to adjust small details.

beforeEach(function () {
    $this->withoutVite();
    xsFreeze();
});

it('lists the exits waiting for the manager, then HR, under Waiting for me', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $manager = $this->actingAs($p['manager'])->get('/exit')->assertOk()->inertiaProps();
    $hr = $this->actingAs($p['hr'])->get('/exit')->assertOk()->inertiaProps();

    expect($manager['filters']['tab'])->toBe('waiting')
        ->and(collect($manager['exits']['data'])->pluck('id')->all())->toBe([$exit->id])
        ->and($manager['exits']['data'][0]['waiting_at'])->toBe('manager')
        ->and($manager['exits']['data'][0]['waiting_for_me'])->toBeTrue()
        // HR could decide a pending exit too (it skips the manager), so it waits for them as well.
        ->and($hr['counts']['waiting'])->toBe(1);
});

it('splits the tabs into open, all and ended with their counts', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp']);
    resignationRow($p['otherEmp'], ['status' => 'rejected', 'decision_note' => 'No.']);

    $props = fn (string $tab) => $this->actingAs($p['hr'])->get("/exit?tab={$tab}")->assertOk()->inertiaProps();

    expect($props('open')['exits']['total'])->toBe(1)
        ->and($props('ended')['exits']['total'])->toBe(1)
        ->and($props('all')['exits']['total'])->toBe(2)
        ->and($props('open')['counts'])->toMatchArray(['open' => 1, 'ended' => 1, 'all' => 2]);
});

it('filters by search, type and department', function () {
    $p = xsPeople();
    $department = $p['employeeEmp']->department_id;
    resignationRow($p['employeeEmp']);
    resignationRow($p['otherEmp'], ['type' => 'termination']);

    $ids = fn (string $query) => collect($this->actingAs($p['hr'])->get("/exit?tab=all&{$query}")->assertOk()->inertiaProps()['exits']['data'])
        ->pluck('employee.name')->all();

    expect($ids('search=Sara'))->toBe(['Sara Tester'])
        ->and($ids('type=termination'))->toBe(['Zain Tester'])
        ->and($ids('type=resignation'))->toBe(['Sara Tester'])
        ->and($ids('department='.($department ?? 0)))->toContain('Sara Tester');
});

it('shows a manager only the exits of their reports and no settlement column', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp']);
    resignationRow($p['otherEmp']);

    $props = $this->actingAs($p['manager'])->get('/exit?tab=all')->assertOk()->inertiaProps();

    expect(collect($props['exits']['data'])->pluck('employee.name')->all())->toBe(['Sara Tester'])
        ->and($props['can'])->toBe(['record' => false, 'settlements' => false])
        ->and($props['exits']['data'][0])->not->toHaveKey('settlement');
});

it('refuses the list to a plain employee', function () {
    $p = xsPeople();

    $this->actingAs($p['employee'])->get('/exit')->assertForbidden();
});

it('runs a fixed number of queries however many exits are listed', function () {
    $p = xsPeople();

    foreach (range(1, 2) as $i) {
        resignationRow(Employee::factory()->create(['joining_date' => '2026-01-01']));
    }

    $count = function () use ($p) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($p['hr'])->get('/exit?tab=all')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // warm up (permission cache)
    $few = $count();

    foreach (range(1, 8) as $i) {
        resignationRow(Employee::factory()->create(['joining_date' => '2026-01-01']));
    }

    expect($count())->toBe($few);
});

it('records an exit for HR and approves it in the same step', function () {
    $p = xsPeople();

    $this->actingAs($p['hr'])->post('/exit', [
        'employee_id' => $p['employeeEmp']->id,
        'type' => 'termination',
        'last_working_day' => '2026-12-05',
        'reason' => 'Contract ended.',
        'approve_now' => true,
    ])->assertRedirect();

    $exit = Resignation::query()->where('employee_id', $p['employeeEmp']->id)->firstOrFail();

    expect($exit->status)->toBe('approved')
        ->and($exit->type)->toBe('termination')
        ->and($p['employeeEmp']->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-05');
});

it('shows a refusal of the record dialog as an error and creates nothing', function () {
    $p = xsPeople();

    $this->actingAs($p['hr'])->post('/exit', [
        'employee_id' => $p['hrEmp']->id,
        'type' => 'resignation',
        'last_working_day' => '2026-12-05',
        'reason' => 'Self.',
    ])->assertSessionHasErrors('exit');

    $this->actingAs($p['hr'])->post('/exit', [
        'employee_id' => $p['employeeEmp']->id,
        'type' => 'resignation',
        'last_working_day' => 'not a date',
        'reason' => '',
    ])->assertSessionHasErrors(['last_working_day', 'reason']);

    expect(Resignation::query()->count())->toBe(0);
});

it('refuses recording to a manager and to an employee', function () {
    $p = xsPeople();
    $body = ['employee_id' => $p['employeeEmp']->id, 'type' => 'resignation', 'last_working_day' => '2026-12-05', 'reason' => 'x'];

    $this->actingAs($p['manager'])->post('/exit', $body)->assertForbidden();
    $this->actingAs($p['employee'])->post('/exit', $body)->assertForbidden();
});

it('offers the record dialog only people who can be recorded', function () {
    $p = xsPeople();
    resignationRow($p['otherEmp']); // has an open exit

    $names = collect($this->actingAs($p['hr'])->getJson('/exit/employee-options')->assertOk()->json('employees'))->pluck('name')->all();

    expect($names)->toContain('Sara Tester')
        ->and($names)->not->toContain('Zain Tester')   // open exit
        ->and($names)->not->toContain('Hina Tester')   // oneself
        ->and($names)->not->toContain('Adnan Tester'); // an Admin, and HR may not record an Admin

    $this->actingAs($p['manager'])->getJson('/exit/employee-options')->assertForbidden();
});

it('lets the manager approve, then HR, and shows the flow', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $this->actingAs($p['manager'])->post("/exit/{$exit->id}/decide", ['decision' => 'approve'])->assertRedirect();
    expect($exit->fresh()->status)->toBe('manager_approved');

    $this->actingAs($p['hr'])->post("/exit/{$exit->id}/decide", ['decision' => 'approve'])->assertRedirect();
    expect($exit->fresh()->status)->toBe('approved');

    $props = $this->actingAs($p['hr'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect(collect($props['flow'])->pluck('state')->all())->toBe(['done', 'done', 'done']);
});

it('refuses a rejection without a note, with the message under the note', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $this->actingAs($p['manager'])->post("/exit/{$exit->id}/decide", ['decision' => 'reject', 'note' => ''])
        ->assertSessionHasErrors('note');

    expect($exit->fresh()->status)->toBe('pending');

    $this->actingAs($p['manager'])->post("/exit/{$exit->id}/decide", ['decision' => 'reject', 'note' => 'Not now.'])->assertRedirect();

    expect($exit->fresh()->status)->toBe('rejected');
});

it('changes the last working day and cancels an approved exit, showing refusals', function () {
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');

    $this->actingAs($p['hr'])->put("/exit/{$exit->id}/last-day", ['last_working_day' => 'x'])->assertSessionHasErrors('last_working_day');
    $this->actingAs($p['hr'])->put("/exit/{$exit->id}/last-day", ['last_working_day' => '2026-12-20'])->assertRedirect();

    expect($exit->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-20');

    $this->actingAs($p['hr'])->post("/exit/{$exit->id}/cancel", ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($p['hr'])->post("/exit/{$exit->id}/cancel", ['reason' => 'Stays.'])->assertRedirect();

    expect($exit->fresh()->status)->toBe('cancelled');
});

it('gives the exit page its panels to HR and says why an action is unavailable', function () {
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');
    settlementRow($exit, ['status' => 'approved', 'approved_at' => now()]);

    $props = $this->actingAs($p['hr'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect($props)->toHaveKeys(['resignation', 'flow', 'can', 'reasons', 'clearance', 'settlement', 'letter'])
        ->and($props['can']['change_day'])->toBeFalse()
        ->and($props['reasons']['cancel'])->toBe('The final settlement is approved, so this exit can no longer change.')
        ->and($props['settlement']['status'])->toBe('approved');
});
