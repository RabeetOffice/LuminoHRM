<?php

require_once __DIR__.'/Support/screen-helpers.php';

// Who sees what (design 5.9): settlement, clearance and letter keys are ABSENT for a manager; an employee sees only their
// own exit, and the statement only once the settlement is approved. Out of scope is 403, never an empty page.

beforeEach(function () {
    $this->withoutVite();
    xsFreeze();
});

/** An approved exit of the manager's report with a draft settlement worth a figure, and the people. */
function xvScene(string $settlementStatus = 'draft'): array
{
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');
    $settlement = settlementRow($exit, ['status' => $settlementStatus, 'net' => 4984500, 'basic' => 6000000]);

    return [$p, $exit, $settlement];
}

it('never sends a manager the settlement, clearance or letter of a report', function () {
    [$p, $exit] = xvScene();

    $props = $this->actingAs($p['manager'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect($props)->toHaveKeys(['resignation', 'flow', 'can'])
        ->and($props)->not->toHaveKeys(['settlement', 'clearance', 'letter', 'status_line', 'statement_url', 'letter_url'])
        ->and(json_encode($props))->not->toContain('4984500')
        ->and($props['resignation'])->not->toHaveKey('settlement');
});

it('sends HR the settlement figures of someone else', function () {
    [$p, $exit] = xvScene();

    $props = $this->actingAs($p['hr'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect($props['settlement']['figures']['net'])->toBe(4984500)
        ->and($props)->toHaveKeys(['clearance', 'letter']);
});

it('gives a manager 403 on an exit of someone who is not their report', function () {
    $p = xsPeople();
    $exit = resignationRow($p['otherEmp']);

    $this->actingAs($p['manager'])->get("/exit/{$exit->id}")->assertForbidden();
    $this->actingAs($p['manager'])->post("/exit/{$exit->id}/decide", ['decision' => 'approve'])->assertForbidden();
    $this->actingAs($p['manager'])->post("/exit/{$exit->id}/cancel", ['reason' => 'x'])->assertForbidden();
});

it('gives an employee 403 on the exit pages, theirs included, and on every settlement route', function () {
    [$p, $exit, $settlement] = xvScene();

    $this->actingAs($p['employee'])->get("/exit/{$exit->id}")->assertForbidden();
    $this->actingAs($p['other'])->get("/exit/{$exit->id}")->assertForbidden();

    foreach (['manager', 'employee'] as $who) {
        $this->actingAs($p[$who])->post("/exit/{$exit->id}/settlement")->assertForbidden();
        $this->actingAs($p[$who])->post("/exit/settlements/{$settlement->id}/recalculate")->assertForbidden();
        $this->actingAs($p[$who])->post("/exit/settlements/{$settlement->id}/approve")->assertForbidden();
        $this->actingAs($p[$who])->post("/exit/settlements/{$settlement->id}/paid", ['paid_on' => '2026-11-25'])->assertForbidden();
        $this->actingAs($p[$who])->put("/exit/settlements/{$settlement->id}/tax", ['amount' => 1])->assertForbidden();
        $this->actingAs($p[$who])->post("/exit/settlements/{$settlement->id}/lines", [])->assertForbidden();
    }
});

it('gives a manager 403 on a clearance item', function () {
    [$p, $exit] = xvScene();
    $item = $exit->clearances()->where('kind', 'access')->firstOrFail();

    $this->actingAs($p['manager'])->put("/exit/clearance/{$item->id}", ['cleared' => true])->assertForbidden();
    $this->actingAs($p['employee'])->put("/exit/clearance/{$item->id}", ['cleared' => true])->assertForbidden();
});

it('gives the employee their own clearance read-only but never a settlement key', function () {
    [$p] = xvScene();

    $props = $this->actingAs($p['employee'])->get('/my/resignation')->assertOk()->inertiaProps()['current'];

    expect($props)->toHaveKey('clearance')
        ->and($props)->not->toHaveKeys(['settlement', 'letter', 'statement_url', 'letter_url'])
        ->and($props['clearance']['editable'])->toBeFalse()
        ->and(json_encode($props))->not->toContain('4984500');
});

it('gives the employee the statement link only once the settlement is approved', function () {
    [$p, , $settlement] = xvScene('draft');

    $before = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps()['current'];
    $settlement->forceFill(['status' => 'approved', 'approved_at' => now()])->save();
    $after = $this->actingAs($p['employee'])->get('/my/resignation')->inertiaProps()['current'];

    expect($before)->not->toHaveKey('statement_url')
        ->and($after['statement_url'])->toBe('/my/resignation/'.$after['resignation']['id'].'/statement')
        ->and($after)->not->toHaveKey('settlement');
});

it('gives HR no edit actions on their own exit and hides their own draft settlement', function () {
    $p = xsPeople();
    $exit = approvedExit($p['hrEmp'], '2026-12-10');
    $settlement = settlementRow($exit, ['status' => 'draft', 'net' => 123400]);

    $props = $this->actingAs($p['hr'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect($props['own'])->toBeTrue()
        ->and($props['can'])->toMatchArray(['decide' => null, 'change_day' => false, 'cancel' => false])
        ->and($props)->not->toHaveKey('settlement')
        ->and($props['clearance']['editable'])->toBeFalse();

    $this->actingAs($p['hr'])->post("/exit/settlements/{$settlement->id}/recalculate")->assertForbidden();
});

it('shows an Admin the exit of an HR person and lets them act on it', function () {
    $p = xsPeople();
    $exit = approvedExit($p['hrEmp'], '2026-12-10');

    $admin = $this->actingAs($p['admin'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();
    $hr = $this->actingAs($p['hr'])->get("/exit/{$exit->id}")->assertOk()->inertiaProps();

    expect($admin['can']['change_day'])->toBeTrue()
        ->and($hr['can']['change_day'])->toBeFalse();
});

it('sends a manager no settlement status or finer stage in the list', function () {
    [$p] = xvScene();

    $row = $this->actingAs($p['manager'])->get('/exit?tab=all')->inertiaProps()['exits']['data'][0];
    $hrRow = $this->actingAs($p['hr'])->get('/exit?tab=all')->inertiaProps()['exits']['data'][0];

    expect($row)->not->toHaveKey('settlement')
        ->and($row['detail'])->toBeNull()
        ->and($hrRow['settlement'])->toBe('draft')
        ->and($hrRow['detail'])->toBe('Settlement draft');
});
