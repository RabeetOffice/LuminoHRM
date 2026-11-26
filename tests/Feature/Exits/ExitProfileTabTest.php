<?php

require_once __DIR__.'/Support/screen-helpers.php';

// The Exit tab of the profile: who gets it, what it carries, and that settlement figures reach payroll.view only.

beforeEach(function () {
    $this->withoutVite();
    xsFreeze();
});

it('gives HR and Admin the tab even when nothing was recorded, with the record action', function () {
    $p = xsPeople();

    $props = $this->actingAs($p['hr'])->get("/employees/{$p['employeeEmp']->id}")->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('exit')
        ->and($props['employee']['exit'])->toMatchArray(['current' => null, 'history' => [], 'can_record' => true, 'today' => '2026-11-25']);
});

it('sends HR the open exit with its settlement status and net, and the history apart', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp'], ['status' => 'withdrawn', 'submitted_on' => '2026-05-01']);
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');
    settlementRow($exit, ['status' => 'draft', 'net' => 4984500]);

    $tab = $this->actingAs($p['hr'])->get("/employees/{$p['employeeEmp']->id}")->inertiaProps()['employee']['exit'];

    expect($tab['current']['id'])->toBe($exit->id)
        ->and($tab['current']['settlement'])->toBe('draft')
        ->and($tab['current']['net'])->toBe(4984500)
        ->and($tab['current']['url'])->toBe("/exit/{$exit->id}")
        ->and($tab['history'])->toHaveCount(1)
        ->and($tab['can_record'])->toBeFalse();
});

it('gives a manager the tab for a report with an exit, without any figure', function () {
    $p = xsPeople();
    $exit = approvedExit($p['employeeEmp'], '2026-12-10');
    settlementRow($exit, ['status' => 'approved', 'net' => 4984500, 'approved_at' => now()]);

    $props = $this->actingAs($p['manager'])->get("/employees/{$p['employeeEmp']->id}")->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('exit')
        ->and($props['employee']['exit']['current']['id'])->toBe($exit->id)
        ->and($props['employee']['exit']['current'])->not->toHaveKeys(['settlement', 'net'])
        ->and($props['employee']['exit']['can_record'])->toBeFalse()
        ->and(json_encode($props['employee']['exit']))->not->toContain('4984500');
});

it('gives a manager no tab for a report with no exit, and an employee none on a colleague', function () {
    $p = xsPeople();

    $props = $this->actingAs($p['manager'])->get("/employees/{$p['employeeEmp']->id}")->assertOk()->inertiaProps();

    expect($props['tabs'])->not->toContain('exit')
        ->and($props['employee'])->not->toHaveKey('exit');
});

it('gives the person their own exit on My Profile, linking to My Resignation', function () {
    $p = xsPeople();
    resignationRow($p['employeeEmp']);

    $props = $this->actingAs($p['employee'])->get('/my/profile')->assertOk()->inertiaProps();

    expect($props['tabs'] ?? $props['employee']['tabs'] ?? [])->toContain('exit')
        ->and($props['employee']['exit']['current']['url'])->toBe('/my/resignation');
});
