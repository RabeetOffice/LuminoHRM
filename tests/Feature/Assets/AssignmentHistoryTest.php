<?php

use App\Actions\Assets\AssignAsset;
use App\Actions\Assets\EditAssignmentNote;
use App\Actions\Assets\ReturnAsset;
use App\Models\AssetAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

// History: a returned assignment is kept as it was (only its two notes change), never deleted, and the asset can be
// handed out again as a new row. There is no undo of an assignment (open item 3).

require_once __DIR__.'/Support/assignment-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

if (! function_exists('ahReturned')) {
    /** An assignment made and returned through the actions. */
    function ahReturned(): AssetAssignment
    {
        $hr = userWithRole('hr');
        $assignment = app(AssignAsset::class)->handle($hr, assetRow(), aaPerson(), '2026-11-10', 'Bag');
        app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-20', 'fair', 'available', 'OK');

        return $assignment->refresh();
    }
}

it('refuses any change to a returned assignment but its notes', function (string $column, mixed $value) {
    $assignment = ahReturned();
    $other = aaPerson(['first_name' => 'Ali']);
    $value = $column === 'employee_id' ? $other->id : $value;

    expect(fn () => AssetAssignment::query()->findOrFail($assignment->id)->forceFill([$column => $value])->save())
        ->toThrow(LogicException::class);

    $stored = $assignment->fresh();
    expect($stored->returned_on->format('Y-m-d'))->toBe('2026-11-20')
        ->and($stored->assigned_on->format('Y-m-d'))->toBe('2026-11-10')
        ->and($stored->condition_out)->toBe('good')
        ->and($stored->return_condition)->toBe('fair')
        ->and($stored->employee_id)->toBe($assignment->employee_id);

    assertAssetInvariant();
})->with([
    'returned_on' => ['returned_on', '2026-11-21'],
    'assigned_on' => ['assigned_on', '2026-11-09'],
    'employee_id' => ['employee_id', null],
    'condition_out' => ['condition_out', 'new'],
    'return_condition' => ['return_condition', 'new'],
]);

it('lets only the two notes change after the return', function () {
    $assignment = ahReturned();

    app(EditAssignmentNote::class)->handle(userWithRole('hr'), $assignment, 'Bag and charger', 'Fine');

    expect($assignment->refresh()->note)->toBe('Bag and charger')->and($assignment->return_note)->toBe('Fine');
});

it('never deletes an assignment, and offers no route that edits or deletes one', function () {
    $assignment = ahReturned();

    expect(fn () => $assignment->delete())->toThrow(LogicException::class)
        ->and(AssetAssignment::query()->whereKey($assignment->id)->exists())->toBeTrue();

    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'assets/assignments'));
    expect($routes->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->sort()->values()->all())->toBe([
        'PATCH assets/assignments/{assignment}/note',
        'POST assets/assignments/{assignment}/return',
    ]);

    expect($this->actingAs(userWithRole('admin'))->delete("/assets/assignments/{$assignment->id}")->status())->toBeIn([404, 405]);
});

it('hands the same asset out again as a new row, leaving the old one intact', function () {
    $old = ahReturned();
    $asset = $old->asset;

    $new = app(AssignAsset::class)->handle(userWithRole('hr'), $asset, aaPerson(['first_name' => 'Ali']), '2026-11-21', null);

    $old->refresh();
    expect($new->id)->not->toBe($old->id)
        ->and($old->open_asset_id)->toBeNull()
        ->and($old->returned_on->format('Y-m-d'))->toBe('2026-11-20')
        ->and($old->note)->toBe('Bag')
        ->and($new->refresh()->open_asset_id)->toBe($asset->id)
        ->and($new->condition_out)->toBe('fair') // the condition it came back in
        ->and($asset->refresh()->status)->toBe('assigned');

    assertAssetInvariant();
});
