<?php

use App\Models\Employee;
use Carbon\CarbonImmutable;

/*
| The profile's "Assets" tab: what the person holds and the last 20 returned. Whoever may open the profile sees it; the
| cost (and can_assign) only with assets.assign, absent otherwise. "Now" is 2026-12-03.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
});

function apaPerson(array $attrs = []): Employee
{
    $e = Employee::factory()->create($attrs);
    assignedAsset($e, ['name' => 'Dell Latitude', 'cost' => 15_000_000, 'assigned_on' => '2026-11-02', 'condition_out' => 'good']);
    assignedAsset($e, ['name' => 'Old phone', 'category' => 'Phone', 'assigned_on' => '2026-01-05', 'returned_on' => '2026-10-10', 'return_condition' => 'fair', 'cost' => 4_000_000]);

    return $e;
}

function apaUser(string $role, array $attrs = [])
{
    $user = userWithRole($role);
    employeeFor($user, $attrs);

    return $user->refresh();
}

it('lists what is held and the history for HR and Admin, with the cost', function (string $role) {
    $person = apaPerson();

    $props = $this->actingAs(apaUser($role))->get("/employees/{$person->id}")->assertOk()->inertiaProps();
    $assets = $props['employee']['assets'];

    expect($props['tabs'])->toContain('assets')
        ->and($assets['can_assign'])->toBeTrue()
        ->and($assets['holding'])->toHaveCount(1)
        ->and($assets['holding'][0])->toMatchArray(['name' => 'Dell Latitude', 'category' => 'Laptop', 'assigned_on' => '2026-11-02', 'condition_out' => 'good', 'cost' => 15_000_000])
        ->and($assets['holding'][0]['code'])->toStartWith('AST-')
        ->and($assets['holding'][0])->toHaveKeys(['id', 'asset_id'])
        ->and($assets['history'])->toHaveCount(1)
        ->and($assets['history'][0])->toMatchArray(['name' => 'Old phone', 'category' => 'Phone', 'returned_on' => '2026-10-10', 'return_condition' => 'fair', 'cost' => 4_000_000]);
})->with(['admin', 'hr']);

it('gives a Manager viewing a report the tab without any cost key', function () {
    $manager = apaUser('manager');
    $report = apaPerson(['manager_id' => $manager->employee->id]);

    $props = $this->actingAs($manager)->get("/employees/{$report->id}")->assertOk()->inertiaProps();
    $assets = $props['employee']['assets'];

    expect($props['tabs'])->toContain('assets')
        ->and($assets['holding'])->toHaveCount(1)
        ->and($assets['holding'][0])->not->toHaveKey('cost')
        ->and($assets['history'][0])->not->toHaveKey('cost')
        ->and($assets['can_assign'])->toBeFalse()
        ->and(json_encode($props))->not->toContain('15000000')->not->toContain('4000000');
});

it('gives an Employee on My Profile their own tab without cost', function () {
    $user = apaUser('employee');
    assignedAsset($user->employee, ['name' => 'My laptop', 'cost' => 9_900_000]);

    $props = $this->actingAs($user)->get('/my/profile')->assertOk()->inertiaProps();

    expect($props['tabs'])->toContain('assets')
        ->and($props['employee']['assets']['holding'][0]['name'])->toBe('My laptop')
        ->and($props['employee']['assets']['holding'][0])->not->toHaveKey('cost')
        ->and(json_encode($props))->not->toContain('9900000');
});

it('keeps a Manager out of a person who is not their report', function () {
    $stranger = apaPerson();

    $this->actingAs(apaUser('manager'))->get("/employees/{$stranger->id}")->assertForbidden();
});

it('shows an empty tab for someone who never held anything', function () {
    $person = Employee::factory()->create();

    $assets = $this->actingAs(apaUser('hr'))->get("/employees/{$person->id}")->inertiaProps()['employee']['assets'];

    expect($assets['holding'])->toBe([])->and($assets['history'])->toBe([]);
});

it('limits the history to the last 20 returned, newest return first', function () {
    $person = Employee::factory()->create();
    foreach (range(1, 22) as $i) {
        assignedAsset($person, ['name' => "Item {$i}", 'assigned_on' => '2026-01-01', 'returned_on' => sprintf('2026-02-%02d', $i), 'return_condition' => 'good']);
    }

    $history = $this->actingAs(apaUser('hr'))->get("/employees/{$person->id}")->inertiaProps()['employee']['assets']['history'];

    expect($history)->toHaveCount(20)
        ->and($history[0]['name'])->toBe('Item 22')
        ->and($history[19]['name'])->toBe('Item 3');
});

it("shows only that person's assets", function () {
    $person = apaPerson();
    assignedAsset(Employee::factory()->create(), ['name' => 'Not theirs']);

    $props = $this->actingAs(apaUser('hr'))->get("/employees/{$person->id}")->inertiaProps();

    expect(json_encode($props['employee']['assets']))->not->toContain('Not theirs');
});
