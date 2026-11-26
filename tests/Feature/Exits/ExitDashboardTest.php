<?php

use App\Models\Employee;

require_once __DIR__.'/Support/screen-helpers.php';

// The dashboard's exit cards: "Exits waiting for you" (counts per role) and "Upcoming leavers" (exit.clearance).

beforeEach(function () {
    $this->withoutVite();
    xsFreeze();
});

it('gives a plain employee neither card', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp']);

    $props = $this->actingAs($p['employee'])->get('/dashboard')->assertOk()->inertiaProps();

    expect($props)->not->toHaveKeys(['exitsWaiting', 'upcomingLeavers']);
});

it('counts a report\'s pending exit for the manager, with a link and no reason', function () {
    $p = xsPeople();
    $exit = resignationRow($p['employeeEmp']);

    $props = $this->actingAs($p['manager'])->get('/dashboard')->assertOk()->inertiaProps();

    expect($props['exitsWaiting']['count'])->toBe(1)
        ->and($props['exitsWaiting']['items'][0])->toBe(['id' => $exit->id, 'label' => 'Sara Tester · With manager', 'url' => "/exit/{$exit->id}"])
        ->and($props)->not->toHaveKey('upcomingLeavers')
        ->and(json_encode($props['exitsWaiting']))->not->toContain('Moving to another city');
});

it('counts the final step and unfinished clearance for HR, and nothing once it is done', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp'], ['status' => 'manager_approved', 'manager_skipped' => true]);
    approvedExit($p['otherEmp'], '2026-12-10');

    $count = fn () => $this->actingAs($p['hr'])->get('/dashboard')->inertiaProps()['exitsWaiting']['count'];

    expect($count())->toBe(2);

    // Tick every hand item of the approved exit and it stops waiting (nothing is held).
    $exit = $p['otherEmp']->openResignation;
    $exit->clearances()->update(['cleared_at' => now(), 'cleared_by' => $p['hr']->id]);

    expect($count())->toBe(1);
});

it('counts a settlement ready to approve for an Admin only', function () {
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-11-20'); // the day has passed
    $exit->clearances()->update(['cleared_at' => now(), 'cleared_by' => $p['hr']->id]);
    settlementRow($exit, ['status' => 'draft', 'stale' => false]);

    $admin = $this->actingAs($p['admin'])->get('/dashboard')->inertiaProps()['exitsWaiting']['count'];
    $hr = $this->actingAs($p['hr'])->get('/dashboard')->inertiaProps()['exitsWaiting']['count'];

    expect($admin)->toBe(1)
        ->and($hr)->toBe(0);
});

it('lists upcoming leavers for HR: names only, the next 30 days, approved and not yet left', function () {
    $p = xsPeople();
    approvedExit($p['employeeEmp'], '2026-12-10');                 // in 15 days
    approvedExit($p['otherEmp'], '2027-02-10');                    // too far
    resignationRow($p['managerEmp'], ['status' => 'manager_approved', 'last_working_day' => '2026-12-01']); // not approved

    $leavers = $this->actingAs($p['hr'])->get('/dashboard')->assertOk()->inertiaProps()['upcomingLeavers'];

    expect($leavers)->toBe(['count' => 1, 'names' => ['Sara Tester'], 'more' => 0]);
    expect($this->actingAs($p['manager'])->get('/dashboard')->inertiaProps())->not->toHaveKey('upcomingLeavers');
});

it('lists at most five names and says how many more', function () {
    $p = xsPeople();

    foreach (range(1, 7) as $i) {
        approvedExit(Employee::factory()->create(['joining_date' => '2026-01-01']), '2026-12-0'.$i);
    }

    $leavers = $this->actingAs($p['hr'])->get('/dashboard')->inertiaProps()['upcomingLeavers'];

    expect($leavers['count'])->toBe(7)
        ->and($leavers['names'])->toHaveCount(5)
        ->and($leavers['more'])->toBe(2);
});
