<?php

use App\Models\Employee;
use App\Support\Assets\DashboardAssets;
use Illuminate\Support\Facades\DB;

/*
| The dashboard's "My assets" card: count, the first five names and the rest as a number. Own assets only, no cost, no
| codes, no one else's.
*/

beforeEach(function () {
    $this->withoutVite();
});

it('gives an employee with two assets their count and names', function () {
    $user = userWithRole('employee');
    $me = employeeFor($user);
    assignedAsset($me, ['name' => 'Dell Latitude', 'assigned_on' => '2026-11-01', 'cost' => 15_000_000]);
    assignedAsset($me, ['name' => 'iPhone 15', 'assigned_on' => '2026-11-10']);
    assignedAsset($me, ['name' => 'Old monitor', 'returned_on' => '2026-11-12', 'return_condition' => 'good']);

    $props = $this->actingAs($user->refresh())->get('/dashboard')->assertOk()->inertiaProps();

    expect($props['myAssets'])->toBe(['count' => 2, 'names' => ['Dell Latitude', 'iPhone 15'], 'more' => 0]);
    expect(json_encode($props))->not->toContain('15000000')->not->toContain('AST-');
});

it('lists the first five names and counts the rest', function () {
    $user = userWithRole('manager');
    $me = employeeFor($user);
    foreach (range(1, 7) as $i) {
        assignedAsset($me, ['name' => "Item {$i}", 'assigned_on' => sprintf('2026-11-%02d', $i)]);
    }

    $mine = $this->actingAs($user->refresh())->get('/dashboard')->inertiaProps()['myAssets'];

    expect($mine['count'])->toBe(7)
        ->and($mine['names'])->toBe(['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5'])
        ->and($mine['more'])->toBe(2);
});

it('gives count 0 when nothing is held', function () {
    $user = userWithRole('employee');
    employeeFor($user);

    expect($this->actingAs($user->refresh())->get('/dashboard')->inertiaProps()['myAssets'])
        ->toBe(['count' => 0, 'names' => [], 'more' => 0]);
});

it('sends no key to an account without an employee record', function () {
    $user = userWithRole('employee');

    $props = $this->actingAs($user)->get('/dashboard')->assertOk()->inertiaProps();

    expect($props)->not->toHaveKey('myAssets');
});

it("never shows another person's assets, whatever the role", function (string $role) {
    $user = userWithRole($role);
    employeeFor($user);
    assignedAsset(Employee::factory()->create(), ['name' => 'Someone else laptop']);

    $props = $this->actingAs($user->refresh())->get('/dashboard')->inertiaProps();

    expect($props['myAssets']['count'])->toBe(0)
        ->and(json_encode($props))->not->toContain('Someone else laptop');
})->with(['admin', 'hr', 'manager', 'employee']);

it('uses two queries at most', function () {
    $me = Employee::factory()->create();
    foreach (range(1, 6) as $i) {
        assignedAsset($me);
    }

    DB::enableQueryLog();
    DashboardAssets::mine($me->id);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($count)->toBeLessThanOrEqual(2);
});
