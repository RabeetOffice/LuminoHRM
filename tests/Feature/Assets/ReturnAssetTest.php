<?php

use App\Actions\Assets\EditAssignmentNote;
use App\Actions\Assets\ReturnAsset;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

// Return and the note edit: the happy paths, every refusal, the routes. "Today" is 2026-11-26 (Asia/Karachi).
require_once __DIR__.'/Support/assignment-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

if (! function_exists('raHeld')) {
    /** An asset in good condition held by Sara Ahmed since 2026-11-10, and its open assignment. */
    function raHeld(array $person = [], array $asset = []): array
    {
        $sara = aaPerson($person);
        $held = assignedAsset($sara, array_merge(['asset_code' => 'AST-0004', 'name' => 'Dell Latitude', 'condition' => 'good', 'assigned_on' => '2026-11-10'], $asset));

        return [$sara, $held, AssetAssignment::query()->where('asset_id', $held->id)->sole()];
    }
}

it('takes an asset back: the row closed, the asset condition and status updated, audited', function (string $role, string $next) {
    $by = userWithRole($role);
    [$sara, $asset, $assignment] = raHeld();

    app(ReturnAsset::class)->handle($by, $assignment, '2026-11-25', 'damaged', $next, ' Screen cracked ');

    $assignment->refresh();
    expect($assignment->returned_on->format('Y-m-d'))->toBe('2026-11-25')
        ->and($assignment->return_condition)->toBe('damaged')
        ->and($assignment->return_note)->toBe('Screen cracked')
        ->and($assignment->returned_by)->toBe($by->id)
        ->and($assignment->open_asset_id)->toBeNull()
        ->and($assignment->condition_out)->toBe('good')
        ->and($asset->refresh()->status)->toBe($next)
        ->and($asset->condition)->toBe('damaged');

    $entry = Activity::query()->where('description', 'Asset returned')->sole();
    expect($entry->log_name)->toBe('assets')
        ->and($entry->subject_type)->toBe(Employee::class)
        ->and($entry->subject_id)->toBe($sara->id)
        ->and($entry->causer_id)->toBe($by->id)
        ->and($entry->properties->all())->toMatchArray([
            'asset_id' => $asset->id,
            'code' => 'AST-0004',
            'asset_name' => 'Dell Latitude',
            'assigned_on' => '2026-11-10',
            'returned_on' => '2026-11-25',
            'condition_out' => 'good',
            'return_condition' => 'damaged',
            'next_status' => $next,
            'note' => 'Screen cracked',
        ]);

    assertAssetInvariant();
})->with([
    'HR, back to available' => ['hr', 'available'],
    'HR, into repair' => ['hr', 'in_repair'],
    'Admin, retired' => ['admin', 'retired'],
]);

it('lets only an Admin retire on return, and writes nothing for HR', function () {
    [, $asset, $assignment] = raHeld();

    expect(aaRefusal(fn () => app(ReturnAsset::class)->handle(userWithRole('hr'), $assignment, '2026-11-26', 'poor', 'retired', null)))
        ->toBe(['next_status' => 'Only an Admin can retire an asset.'])
        ->and($assignment->refresh()->isOpen())->toBeTrue()
        ->and($asset->refresh()->status)->toBe('assigned')
        ->and($asset->condition)->toBe('good')
        ->and(Activity::query()->where('description', 'Asset returned')->count())->toBe(0);

    assertAssetInvariant();
});

it('checks the return date against the hand-over date and today', function (string $date, ?string $message) {
    [, $asset, $assignment] = raHeld();
    $hr = userWithRole('hr');

    if ($message === null) {
        app(ReturnAsset::class)->handle($hr, $assignment, $date, 'good', 'available', null);
        expect($assignment->refresh()->returned_on->format('Y-m-d'))->toBe($date);
    } else {
        expect(aaRefusal(fn () => app(ReturnAsset::class)->handle($hr, $assignment, $date, 'good', 'available', null)))->toBe(['returned_on' => $message])
            ->and($assignment->refresh()->isOpen())->toBeTrue();
    }

    assertAssetInvariant();
})->with([
    'same day as the hand-over' => ['2026-11-10', null],
    'exactly today' => ['2026-11-26', null],
    'before the hand-over' => ['2026-11-09', 'The return date cannot be before it was handed over (10 Nov 2026).'],
    'tomorrow' => ['2026-11-27', 'The return date cannot be in the future.'],
    'not a date' => ['2026-13-01', 'Enter the date the asset came back.'],
]);

it('refuses an unknown condition or next status', function () {
    [, , $assignment] = raHeld();
    $hr = userWithRole('hr');

    expect(aaRefusal(fn () => app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'broken', 'available', null)))
        ->toBe(['return_condition' => ReturnAsset::BAD_CONDITION])
        ->and(aaRefusal(fn () => app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'good', 'assigned', null)))
        ->toBe(['next_status' => 'Choose what happens to the asset next.'])
        ->and(aaRefusal(fn () => app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'good', 'available', str_repeat('n', 501))))
        ->toBe(['return_note' => 'The note cannot be longer than 500 characters.']);
});

it('takes an asset back from someone who is no longer active', function (string $status) {
    [$sara, $asset, $assignment] = raHeld();
    Employee::query()->whereKey($sara->id)->update(['status' => $status]);

    app(ReturnAsset::class)->handle(userWithRole('hr'), $assignment, '2026-11-26', 'good', 'available', null);

    expect($asset->refresh()->status)->toBe('available');
    assertAssetInvariant();
})->with(['inactive', 'resigned', 'terminated']);

// ---------------------------------------------------------------- notes

it('changes only the two notes, open or returned, and audits old and new', function () {
    $hr = userWithRole('hr');
    [$sara, $asset, $assignment] = raHeld(asset: ['note' => 'Bag included']);

    app(EditAssignmentNote::class)->handle($hr, $assignment, 'Bag and mouse', null);
    expect($assignment->refresh()->note)->toBe('Bag and mouse');

    app(ReturnAsset::class)->handle($hr, $assignment, '2026-11-26', 'good', 'available', 'Fine');
    app(EditAssignmentNote::class)->handle($hr, $assignment, 'Bag and mouse', 'Fine, mouse missing');

    $assignment->refresh();
    expect($assignment->note)->toBe('Bag and mouse')
        ->and($assignment->return_note)->toBe('Fine, mouse missing')
        ->and($assignment->returned_on->format('Y-m-d'))->toBe('2026-11-26');

    $entries = Activity::query()->where('description', 'Asset assignment note changed')->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($sara->id)
        ->and($entries[0]->subject_type)->toBe(Employee::class)
        ->and($entries[0]->properties['old'])->toBe(['note' => 'Bag included'])
        ->and($entries[0]->properties['attributes'])->toBe(['note' => 'Bag and mouse'])
        ->and($entries[0]->properties['code'])->toBe('AST-0004')
        ->and($entries[1]->properties['old'])->toBe(['return_note' => 'Fine'])
        ->and($entries[1]->properties['attributes'])->toBe(['return_note' => 'Fine, mouse missing']);
});

it('writes nothing when the notes do not change, and refuses a return note before the return', function () {
    $hr = userWithRole('hr');
    [, , $assignment] = raHeld(asset: ['note' => 'Bag included']);

    $action = app(EditAssignmentNote::class);
    $action->handle($hr, $assignment, ' Bag included ', '');
    expect($action->changed())->toBeFalse()
        ->and(Activity::query()->where('description', 'Asset assignment note changed')->count())->toBe(0)
        ->and(aaRefusal(fn () => app(EditAssignmentNote::class)->handle($hr, $assignment, 'x', 'too early')))
        ->toBe(['return_note' => EditAssignmentNote::NOT_RETURNED_YET])
        ->and($assignment->refresh()->note)->toBe('Bag included');
});

// ---------------------------------------------------------------- routes

it('returns through the route with a flash, and saves notes through the route', function () {
    $hr = userWithRole('hr');
    [, $asset, $assignment] = raHeld();

    $this->actingAs($hr)->from("/assets/{$asset->id}")->post("/assets/assignments/{$assignment->id}/return", [
        'returned_on' => '2026-11-26', 'return_condition' => 'fair', 'next_status' => 'in_repair', 'return_note' => 'Keyboard sticky',
    ])->assertRedirect("/assets/{$asset->id}")->assertSessionHasNoErrors()->assertSessionHas('success', 'AST-0004 returned.');

    expect($asset->refresh()->status)->toBe('in_repair')->and($asset->condition)->toBe('fair');

    $this->actingAs($hr)->patch("/assets/assignments/{$assignment->id}/note", ['note' => 'Bag', 'return_note' => 'Keyboard sticky'])
        ->assertSessionHasNoErrors()->assertSessionHas('success', 'Note saved.');
    expect($assignment->refresh()->note)->toBe('Bag');

    $this->actingAs($hr)->post("/assets/assignments/{$assignment->id}/return", [
        'returned_on' => '2026-11-26', 'return_condition' => 'fair', 'next_status' => 'available',
    ])->assertSessionHasErrors(['assignment' => 'This asset was already returned.']);
    expect($asset->refresh()->status)->toBe('in_repair');
});

it('shows the return refusals as form errors on the route', function () {
    $hr = userWithRole('hr');
    [, , $assignment] = raHeld();
    $url = "/assets/assignments/{$assignment->id}/return";

    $this->actingAs($hr)->post($url, ['returned_on' => '2026-11-26', 'next_status' => 'available'])
        ->assertSessionHasErrors(['return_condition' => ReturnAsset::BAD_CONDITION]);
    $this->actingAs($hr)->post($url, ['returned_on' => '2026-11-26', 'return_condition' => 'good'])
        ->assertSessionHasErrors(['next_status' => ReturnAsset::BAD_NEXT_STATUS]);
    $this->actingAs($hr)->post($url, ['returned_on' => '2026-11-26', 'return_condition' => 'good', 'next_status' => 'retired'])
        ->assertSessionHasErrors(['next_status' => 'Only an Admin can retire an asset.']);
    $this->actingAs($hr)->post($url, ['returned_on' => '2026-11-09', 'return_condition' => 'good', 'next_status' => 'available'])
        ->assertSessionHasErrors(['returned_on' => 'The return date cannot be before it was handed over (10 Nov 2026).']);
    $this->actingAs($hr)->post('/assets/assignments/999999/return', ['returned_on' => '2026-11-26', 'return_condition' => 'good', 'next_status' => 'available'])
        ->assertNotFound();

    expect($assignment->refresh()->isOpen())->toBeTrue();
    $this->actingAs(userWithRole('admin'))->post($url, ['returned_on' => '2026-11-26', 'return_condition' => 'good', 'next_status' => 'retired'])
        ->assertSessionHasNoErrors();
    expect(Asset::query()->sole()->status)->toBe('retired');
});
