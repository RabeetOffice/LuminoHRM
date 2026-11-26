<?php

use App\Models\Employee;
use App\Support\Assets\AssetHoldings;
use Illuminate\Support\Facades\DB;

/*
| AssetHoldings: what an employee holds right now (the Phase 6c Exit hook).
*/

it('returns only open rows, oldest hand-over first, with asset and category loaded', function () {
    $e = Employee::factory()->create();
    $late = assignedAsset($e, ['name' => 'Late', 'assigned_on' => '2026-11-20']);
    $early = assignedAsset($e, ['name' => 'Early', 'assigned_on' => '2026-10-01', 'category' => 'Phone']);
    assignedAsset($e, ['name' => 'Gone', 'assigned_on' => '2026-09-01', 'returned_on' => '2026-09-30', 'return_condition' => 'good']);
    assignedAsset(Employee::factory()->create(), ['name' => 'Someone else']);

    $rows = AssetHoldings::openFor($e);

    expect($rows->pluck('asset.name')->all())->toBe(['Early', 'Late'])
        ->and($rows->pluck('asset_id')->all())->toBe([$early->id, $late->id])
        ->and($rows->first()->relationLoaded('asset'))->toBeTrue()
        ->and($rows->first()->asset->relationLoaded('category'))->toBeTrue()
        ->and($rows->first()->asset->category->name)->toBe('Phone');
});

it('breaks a tie on the hand-over date by id', function () {
    $e = Employee::factory()->create();
    $a = assignedAsset($e, ['name' => 'First']);
    $b = assignedAsset($e, ['name' => 'Second']);

    expect(AssetHoldings::openFor($e)->pluck('asset_id')->all())->toBe([$a->id, $b->id]);
});

it('uses a fixed number of queries whatever the number of rows', function (int $n) {
    $e = Employee::factory()->create();
    foreach (range(1, $n) as $i) {
        assignedAsset($e);
    }

    DB::enableQueryLog();
    $rows = AssetHoldings::openFor($e);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($rows)->toHaveCount($n)->and($count)->toBe(3);
})->with([1, 6]);

it('says whether anything is held with a single exists query', function () {
    $holder = Employee::factory()->create();
    $returnedOnly = Employee::factory()->create();
    $nothing = Employee::factory()->create();
    assignedAsset($holder);
    assignedAsset($returnedOnly, ['returned_on' => '2026-11-22', 'return_condition' => 'good']);

    DB::enableQueryLog();
    $held = AssetHoldings::holdsAny($holder);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($held)->toBeTrue()->and($count)->toBe(1)
        ->and(AssetHoldings::holdsAny($returnedOnly))->toBeFalse()
        ->and(AssetHoldings::holdsAny($nothing))->toBeFalse();
});

it('ignores other people', function () {
    assignedAsset(Employee::factory()->create());

    expect(AssetHoldings::holdsAny(Employee::factory()->create()))->toBeFalse();
});

it('works for an employee who has left', function (string $status) {
    $e = Employee::factory()->create(['status' => $status, 'last_working_day' => '2026-11-15']);
    assignedAsset($e, ['name' => 'Still out']);

    expect(AssetHoldings::holdsAny($e))->toBeTrue()
        ->and(AssetHoldings::openFor($e)->pluck('asset.name')->all())->toBe(['Still out']);
})->with(['resigned', 'terminated', 'inactive']);
