<?php

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\Employee;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('has the asset tables and columns', function () {
    expect(Schema::getColumnListing('asset_categories'))->toEqualCanonicalizing(['id', 'name', 'sort_order', 'created_at', 'updated_at'])
        ->and(Schema::getColumnListing('assets'))->toEqualCanonicalizing([
            'id', 'asset_code', 'name', 'asset_category_id', 'serial_number', 'purchase_date', 'cost', 'condition', 'status', 'notes',
            'created_by', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('asset_assignments'))->toEqualCanonicalizing([
            'id', 'asset_id', 'employee_id', 'assigned_on', 'returned_on', 'condition_out', 'return_condition', 'note', 'return_note',
            'open_asset_id', 'assigned_by', 'returned_by', 'created_at', 'updated_at',
        ]);
});

it('seeds the five default categories in order', function () {
    expect(AssetCategory::query()->orderBy('sort_order')->pluck('sort_order', 'name')->all())
        ->toBe(['Laptop' => 1, 'Phone' => 2, 'Monitor' => 3, 'Licence' => 4, 'Other' => 5]);
});

it('keeps the status default and the config lists', function () {
    $asset = Asset::query()->create([
        'asset_code' => 'AST-0001', 'name' => 'X', 'asset_category_id' => assetCategory()->id, 'condition' => 'new',
    ]);

    expect($asset->fresh()->status)->toBe('available')
        ->and(config('hrm.assets.conditions'))->toBe(['new', 'good', 'fair', 'poor', 'damaged'])
        ->and(config('hrm.assets.statuses'))->toBe(['available', 'assigned', 'in_repair', 'retired'])
        ->and(config('hrm.assets.code_prefix'))->toBe('AST-');
});

it('refuses a duplicate category name, asset code or serial number', function () {
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'SN-1']);

    expect(fn () => AssetCategory::query()->create(['name' => 'Laptop']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => assetRow(['asset_code' => 'AST-0001']))->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => assetRow(['serial_number' => 'SN-1']))->toThrow(UniqueConstraintViolationException::class);
});

it('lets many assets have no serial number', function () {
    assetRow(['serial_number' => null]);
    assetRow(['serial_number' => null]);

    expect(Asset::query()->whereNull('serial_number')->count())->toBe(2);
});

it('stores cost as integer paisa and the purchase date as a bare date', function () {
    $asset = assetRow(['cost' => 8_500_000, 'purchase_date' => '2026-03-01']);

    expect($asset->fresh()->cost)->toBe(8_500_000)
        ->and($asset->getRawOriginal('purchase_date'))->toBe('2026-03-01')
        ->and(Asset::query()->onDate('2026-03-01')->count())->toBe(1);
});

it('refuses a second open assignment for one asset', function () {
    $asset = assetRow();
    $a = Employee::factory()->create();
    $b = Employee::factory()->create();

    AssetAssignment::factory()->create(['asset_id' => $asset->id, 'employee_id' => $a->id]);

    expect(fn () => AssetAssignment::factory()->create(['asset_id' => $asset->id, 'employee_id' => $b->id]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lets returned assignments of one asset coexist with an open one', function () {
    $asset = assetRow();

    AssetAssignment::factory()->returned('2026-11-10')->create(['asset_id' => $asset->id, 'assigned_on' => '2026-11-01']);
    AssetAssignment::factory()->returned('2026-11-18')->create(['asset_id' => $asset->id, 'assigned_on' => '2026-11-12']);
    AssetAssignment::factory()->create(['asset_id' => $asset->id, 'assigned_on' => '2026-11-20']);

    expect(AssetAssignment::query()->where('asset_id', $asset->id)->count())->toBe(3)
        ->and(AssetAssignment::query()->where('asset_id', $asset->id)->open()->count())->toBe(1)
        ->and(AssetAssignment::query()->where('asset_id', $asset->id)->whereNotNull('open_asset_id')->count())->toBe(1);
});

it('fixes open_asset_id when a caller sets it wrongly', function () {
    $asset = assetRow();
    $other = assetRow();

    $open = AssetAssignment::query()->create([
        'asset_id' => $asset->id, 'employee_id' => Employee::factory()->create()->id, 'assigned_on' => '2026-11-20',
        'condition_out' => 'good', 'open_asset_id' => $other->id,
    ]);
    $returned = AssetAssignment::query()->create([
        'asset_id' => $other->id, 'employee_id' => Employee::factory()->create()->id, 'assigned_on' => '2026-11-01',
        'returned_on' => '2026-11-05', 'condition_out' => 'good', 'return_condition' => 'good', 'open_asset_id' => $other->id,
    ]);

    expect($open->fresh()->open_asset_id)->toBe($asset->id)
        ->and($returned->fresh()->open_asset_id)->toBeNull();
});

it('clears open_asset_id when an open assignment is returned', function () {
    $assignment = AssetAssignment::factory()->create();

    $assignment->update(['returned_on' => '2026-11-25', 'return_condition' => 'fair']);

    expect($assignment->fresh()->open_asset_id)->toBeNull()
        ->and($assignment->fresh()->returned_on->format('Y-m-d'))->toBe('2026-11-25');
});

it('restricts deleting an employee, a category or an asset that is referenced', function () {
    $assignment = AssetAssignment::factory()->create();

    expect(fn () => Employee::query()->whereKey($assignment->employee_id)->delete())->toThrow(Exception::class)
        ->and(fn () => AssetCategory::query()->whereKey($assignment->asset->asset_category_id)->delete())->toThrow(Exception::class);
});

it('adds the assetAssignments relation to the employee', function () {
    $employee = Employee::factory()->create();
    assignedAsset($employee);
    assignedAsset($employee, ['returned_on' => '2026-11-22']);

    expect($employee->assetAssignments()->count())->toBe(2)
        ->and($employee->assetAssignments()->open()->count())->toBe(1);
});

it('has working test helpers', function () {
    $employee = Employee::factory()->create();
    $held = assignedAsset($employee, ['name' => 'MacBook', 'category' => 'Laptop']);
    $back = assignedAsset($employee, ['returned_on' => '2026-11-22', 'return_condition' => 'fair']);

    expect($held->status)->toBe('assigned')
        ->and($held->name)->toBe('MacBook')
        ->and($held->category->name)->toBe('Laptop')
        ->and($held->openAssignment?->employee_id)->toBe($employee->id)
        ->and($back->status)->toBe('available')
        ->and($back->openAssignment)->toBeNull()
        ->and(assetCategory('Laptop')->id)->toBe($held->asset_category_id)
        ->and(assetCategory('Headset')->name)->toBe('Headset');
});
