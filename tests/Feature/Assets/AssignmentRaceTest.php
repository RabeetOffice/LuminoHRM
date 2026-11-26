<?php

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\ChangeAssetStatus;
use App\Actions\Assets\DeleteAsset;
use App\Actions\Assets\ReturnAsset;
use App\Models\Asset;
use App\Models\AssetAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

// Races: double assign (stale model, the unique index, a competing insert), double return, assign against a status change
// and delete against assign. SQLite runs the tests one statement at a time, so a "race" is a stale model or a row written
// behind the action's back; the actions must decide from what they read under their locks, never from what they were given.

require_once __DIR__.'/Support/assignment-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

it('refuses a second assign made from a stale model, keeping the first holder', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $ali = aaPerson(['first_name' => 'Ali', 'last_name' => 'Raza']);
    $asset = assetRow(['asset_code' => 'AST-0004']);

    $stale = Asset::query()->findOrFail($asset->id); // still says "available"
    $aliLoaded = $ali->fresh();

    app(AssignAsset::class)->handle($hr, $asset, $sara, '2026-11-26', null);

    expect($stale->status)->toBe('available')
        ->and(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $stale, $aliLoaded, '2026-11-26', null)))
        ->toBe(['asset' => 'AST-0004 is already assigned to Sara Ahmed. Return it first.'])
        ->and(AssetAssignment::query()->open()->where('asset_id', $asset->id)->count())->toBe(1)
        ->and(AssetAssignment::query()->open()->sole()->employee_id)->toBe($sara->id)
        ->and(Activity::query()->where('description', 'Asset assigned')->count())->toBe(1)
        ->and($asset->refresh()->status)->toBe('assigned');

    assertAssetInvariant();
});

it('has the unique open_asset_id index refuse a second open row written directly', function () {
    $sara = aaPerson();
    $asset = assignedAsset($sara);

    expect(fn () => DB::table('asset_assignments')->insert([
        'asset_id' => $asset->id,
        'employee_id' => aaPerson(['first_name' => 'Ali'])->id,
        'assigned_on' => '2026-11-26',
        'condition_out' => 'good',
        'open_asset_id' => $asset->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);

    expect(AssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(1);
});

it('reports "just assigned" when its own insert collides, and rolls everything back', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $ali = aaPerson(['first_name' => 'Ali', 'last_name' => 'Raza']);
    $asset = assetRow();

    // A writer that took no locks slips an open row in just before the action's own insert.
    AssetAssignment::creating(function (AssetAssignment $a) use ($ali): void {
        DB::table('asset_assignments')->insert([
            'asset_id' => $a->asset_id, 'employee_id' => $ali->id, 'assigned_on' => '2026-11-26', 'condition_out' => 'good',
            'open_asset_id' => $a->asset_id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $asset, $sara, '2026-11-26', null)))
        ->toBe(['asset' => AssignAsset::JUST_ASSIGNED])
        ->and(AssetAssignment::query()->count())->toBe(0) // the transaction (and the competing row inside it) rolled back
        ->and($asset->refresh()->status)->toBe('available')
        ->and(Activity::query()->where('description', 'Asset assigned')->count())->toBe(0);
});

it('refuses a second return made from a stale model, keeping the first return', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $asset = assignedAsset($sara, ['assigned_on' => '2026-11-10', 'condition' => 'good']);
    $first = AssetAssignment::query()->sole();
    $stale = AssetAssignment::query()->findOrFail($first->id); // still open

    app(ReturnAsset::class)->handle($hr, $first, '2026-11-25', 'fair', 'in_repair', null);

    expect($stale->isOpen())->toBeTrue()
        ->and(aaRefusal(fn () => app(ReturnAsset::class)->handle($hr, $stale, '2026-11-26', 'new', 'available', null)))
        ->toBe(['assignment' => 'This asset was already returned.'])
        ->and(Activity::query()->where('description', 'Asset returned')->count())->toBe(1)
        ->and($asset->refresh()->status)->toBe('in_repair')
        ->and($asset->condition)->toBe('fair')
        ->and($first->refresh()->returned_on->format('Y-m-d'))->toBe('2026-11-25')
        ->and($first->return_condition)->toBe('fair');

    assertAssetInvariant();
});

it('refuses an assign from a stale model after the asset went into repair', function () {
    $hr = userWithRole('hr');
    $asset = assetRow();
    $stale = Asset::query()->findOrFail($asset->id);

    app(ChangeAssetStatus::class)->handle($hr, $asset, 'in_repair');

    expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $stale, aaPerson(), '2026-11-26', null)))
        ->toBe(['asset' => 'This asset is not available. It is in repair.'])
        ->and(AssetAssignment::query()->count())->toBe(0);

    assertAssetInvariant();
});

it('refuses a status change from a stale model after an assign', function () {
    $hr = userWithRole('hr');
    $asset = assetRow();
    $stale = Asset::query()->findOrFail($asset->id);

    app(AssignAsset::class)->handle($hr, $asset, aaPerson(), '2026-11-26', null);

    expect(fn () => app(ChangeAssetStatus::class)->handle($hr, $stale, 'in_repair'))->toThrow(ValidationException::class)
        ->and($asset->refresh()->status)->toBe('assigned');

    assertAssetInvariant();
});

it('refuses to delete an asset once it has been assigned, even from a stale model', function () {
    $admin = userWithRole('admin');
    $asset = assetRow();
    $stale = Asset::query()->findOrFail($asset->id);

    app(AssignAsset::class)->handle($admin, $asset, aaPerson(), '2026-11-26', null);

    expect(fn () => app(DeleteAsset::class)->handle($admin, $stale))->toThrow(ValidationException::class)
        ->and(Asset::query()->whereKey($asset->id)->exists())->toBeTrue();

    assertAssetInvariant();
});

it('assigns, returns and assigns again without ever breaking the invariant', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $ali = aaPerson(['first_name' => 'Ali', 'last_name' => 'Raza']);
    $asset = assetRow();

    $first = app(AssignAsset::class)->handle($hr, $asset, $sara, '2026-11-20', null);
    assertAssetInvariant();
    app(ReturnAsset::class)->handle($hr, $first, '2026-11-22', 'good', 'available', null);
    assertAssetInvariant();
    $second = app(AssignAsset::class)->handle($hr, $asset, $ali, '2026-11-22', null);
    assertAssetInvariant();

    expect($second->id)->not->toBe($first->id)
        ->and($first->refresh()->open_asset_id)->toBeNull()
        ->and($first->employee_id)->toBe($sara->id)
        ->and($second->refresh()->open_asset_id)->toBe($asset->id)
        ->and(AssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(2);
});
