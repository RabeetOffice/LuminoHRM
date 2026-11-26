<?php

use App\Models\Asset;
use App\Models\Employee;
use App\Support\Assets\AssetScope;

// AssetScope: which assets a user may list.

it('gives HR and Admin every asset', function (string $role) {
    assignedAsset(Employee::factory()->create());
    assetRow();
    $user = userWithRole($role);

    expect(AssetScope::assets($user, Asset::query())->count())->toBe(2)
        ->and(AssetScope::scopeName($user))->toBe('all');
})->with(['admin', 'hr']);

it('limits a Manager to assets held by themself and their direct reports', function () {
    $manager = userWithRole('manager');
    $me = employeeFor($manager);
    $report = Employee::factory()->create(['manager_id' => $me->id]);
    $reportsReport = Employee::factory()->create(['manager_id' => $report->id]);
    $stranger = Employee::factory()->create();

    $mine = assignedAsset($me);
    $theirs = assignedAsset($report);
    assignedAsset($reportsReport);
    assignedAsset($stranger);
    assetRow();
    assignedAsset($stranger, ['assigned_on' => '2026-10-01', 'returned_on' => '2026-10-30', 'return_condition' => 'good']);
    // Something the manager once handed back is not "held" any more.
    assignedAsset($me, ['assigned_on' => '2026-09-01', 'returned_on' => '2026-09-30', 'return_condition' => 'good']);

    $ids = AssetScope::assets($manager, Asset::query())->orderBy('assets.id')->pluck('assets.id')->all();

    expect($ids)->toBe([$mine->id, $theirs->id])
        ->and(AssetScope::scopeName($manager))->toBe('team');
});

it('gives an Employee and a user without a record nothing', function () {
    assignedAsset(Employee::factory()->create());

    expect(AssetScope::assets(userWithRole('employee'), Asset::query())->count())->toBe(0)
        ->and(AssetScope::assets(userWithRole('manager'), Asset::query())->count())->toBe(0);
});
