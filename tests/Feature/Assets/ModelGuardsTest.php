<?php

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;

it('refuses to change a returned assignment except its notes', function (string $column, mixed $value) {
    $assignment = AssetAssignment::factory()->returned('2026-11-25', 'fair')->create();
    $before = $assignment->fresh()->only(['asset_id', 'employee_id', 'assigned_on', 'returned_on', 'condition_out', 'return_condition']);

    $assignment->{$column} = $value instanceof Closure ? $value() : $value;

    expect(fn () => $assignment->save())->toThrow(LogicException::class);

    expect($assignment->fresh()->only(array_keys($before)))->toEqual($before);
})->with([
    'asset' => ['asset_id', fn () => assetRow()->id],
    'employee' => ['employee_id', fn () => Employee::factory()->create()->id],
    'assigned on' => ['assigned_on', '2026-11-01'],
    'returned on' => ['returned_on', '2026-11-26'],
    'reopened' => ['returned_on', null],
    'condition out' => ['condition_out', 'poor'],
    'return condition' => ['return_condition', 'damaged'],
    'returned by' => ['returned_by', fn () => userWithRole('hr')->id],
]);

it('lets the notes of a returned assignment change', function () {
    $assignment = AssetAssignment::factory()->returned()->create();

    $assignment->update(['note' => 'Charger included', 'return_note' => 'Scratch on the lid']);

    expect($assignment->fresh()->note)->toBe('Charger included')
        ->and($assignment->fresh()->return_note)->toBe('Scratch on the lid');
});

it('reads the stored row, so a stale model cannot change a returned assignment', function () {
    $assignment = AssetAssignment::factory()->create();
    $stale = AssetAssignment::query()->findOrFail($assignment->id);

    $assignment->update(['returned_on' => '2026-11-25', 'return_condition' => 'good']);

    // $stale still believes the row is open.
    $stale->condition_out = 'damaged';

    expect(fn () => $stale->save())->toThrow(LogicException::class)
        ->and($assignment->fresh()->condition_out)->toBe('good');
});

it('lets an open assignment be returned', function () {
    $assignment = AssetAssignment::factory()->create();

    $assignment->update(['returned_on' => '2026-11-25', 'return_condition' => 'good', 'return_note' => 'Fine', 'returned_by' => null]);

    expect($assignment->fresh()->returned_on->format('Y-m-d'))->toBe('2026-11-25');
});

it('refuses to move an open assignment to another asset, person or date', function (string $column) {
    $assignment = AssetAssignment::factory()->create();

    $assignment->{$column} = match ($column) {
        'asset_id' => assetRow()->id,
        'employee_id' => Employee::factory()->create()->id,
        'assigned_on' => '2026-11-01',
    };

    expect(fn () => $assignment->save())->toThrow(LogicException::class);
})->with(['asset_id', 'employee_id', 'assigned_on']);

it('lets an open assignment change its notes and condition out', function () {
    $assignment = AssetAssignment::factory()->create();

    $assignment->update(['note' => 'With bag', 'condition_out' => 'fair']);

    expect($assignment->fresh()->note)->toBe('With bag');
});

it('never deletes an assignment, open or returned', function (bool $returned) {
    $assignment = $returned ? AssetAssignment::factory()->returned()->create() : AssetAssignment::factory()->create();

    expect(fn () => $assignment->delete())->toThrow(LogicException::class)
        ->and(AssetAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();
})->with([[false], [true]]);

it('refuses to delete an asset that has an assignment row', function (bool $returned) {
    $assignment = $returned ? AssetAssignment::factory()->returned()->create() : AssetAssignment::factory()->create();
    $asset = $assignment->asset;

    expect(fn () => $asset->delete())->toThrow(LogicException::class)
        ->and(Asset::query()->whereKey($asset->id)->exists())->toBeTrue();
})->with([[false], [true]]);

it('reads the stored assignments, not a loaded relation, before deleting an asset', function () {
    $asset = assetRow();
    $loaded = Asset::query()->with('assignments')->findOrFail($asset->id);

    AssetAssignment::factory()->create(['asset_id' => $asset->id]);

    expect(fn () => $loaded->delete())->toThrow(LogicException::class);
});

it('deletes an asset that was never handed out', function () {
    $asset = assetRow();

    $asset->delete();

    expect(Asset::query()->whereKey($asset->id)->exists())->toBeFalse();
});

it('reports open and returned state', function () {
    $open = AssetAssignment::factory()->create();
    $closed = AssetAssignment::factory()->returned()->create();

    expect($open->isOpen())->toBeTrue()
        ->and($closed->isOpen())->toBeFalse()
        ->and(AssetAssignment::query()->open()->pluck('id')->all())->toBe([$open->id]);
});
