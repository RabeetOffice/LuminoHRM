<?php

use App\Actions\Assets\AssignAsset;
use App\Models\AssetAssignment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

// Assign: the happy path, every refusal, the routes and their permissions. "Today" is 2026-11-26 (Asia/Karachi).

require_once __DIR__.'/Support/assignment-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

it('hands an asset over: one open row, the asset assigned, condition_out copied, audited with the employee as subject', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $asset = assetRow(['asset_code' => 'AST-0004', 'name' => 'Dell Latitude', 'condition' => 'fair']);

    $assignment = app(AssignAsset::class)->handle($hr, $asset, $sara, '2026-11-25', '  Charger included ');

    $assignment->refresh();
    expect($assignment->asset_id)->toBe($asset->id)
        ->and($assignment->employee_id)->toBe($sara->id)
        ->and($assignment->assigned_on->format('Y-m-d'))->toBe('2026-11-25')
        ->and($assignment->returned_on)->toBeNull()
        ->and($assignment->open_asset_id)->toBe($asset->id)
        ->and($assignment->condition_out)->toBe('fair')
        ->and($assignment->note)->toBe('Charger included')
        ->and($assignment->assigned_by)->toBe($hr->id)
        ->and($asset->refresh()->status)->toBe('assigned');

    $entry = Activity::query()->where('description', 'Asset assigned')->sole();
    expect($entry->log_name)->toBe('assets')
        ->and($entry->subject_type)->toBe(Employee::class)
        ->and($entry->subject_id)->toBe($sara->id)
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties->all())->toMatchArray([
            'asset_id' => $asset->id,
            'code' => 'AST-0004',
            'asset_name' => 'Dell Latitude',
            'assigned_on' => '2026-11-25',
            'condition' => 'fair',
            'note' => 'Charger included',
        ]);

    assertAssetInvariant();
});

it('refuses an asset that is not available', function (string $status, string $message) {
    $hr = userWithRole('hr');
    $asset = assetRow(['status' => $status]);

    expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $asset, aaPerson(), '2026-11-26', null)))
        ->toBe(['asset' => $message])
        ->and(AssetAssignment::query()->count())->toBe(0)
        ->and($asset->refresh()->status)->toBe($status)
        ->and(Activity::query()->where('description', 'Asset assigned')->count())->toBe(0);
})->with([
    'in repair' => ['in_repair', 'This asset is not available. It is in repair.'],
    'retired' => ['retired', 'This asset is not available. It is retired.'],
]);

it('refuses an asset somebody already holds, naming the holder', function () {
    $hr = userWithRole('hr');
    $ali = aaPerson(['first_name' => 'Ali', 'last_name' => 'Raza']);
    $asset = assignedAsset($ali, ['asset_code' => 'AST-0007']);

    expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $asset, aaPerson(), '2026-11-26', null)))
        ->toBe(['asset' => 'AST-0007 is already assigned to Ali Raza. Return it first.'])
        ->and(AssetAssignment::query()->count())->toBe(1);

    assertAssetInvariant();
});

it('gives assets to active and probation employees only', function (string $status, bool $allowed) {
    $hr = userWithRole('hr');
    $person = aaPerson(['status' => $status]);
    $asset = assetRow();

    if ($allowed) {
        app(AssignAsset::class)->handle($hr, $asset, $person, '2026-11-26', null);
        expect($asset->refresh()->status)->toBe('assigned');
    } else {
        expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $asset, $person, '2026-11-26', null)))
            ->toBe(['employee_id' => 'Sara Ahmed is not an active employee, so assets cannot be given to them.'])
            ->and($asset->refresh()->status)->toBe('available');
    }

    assertAssetInvariant();
})->with([
    'active' => ['active', true],
    'probation' => ['probation', true],
    'inactive' => ['inactive', false],
    'resigned' => ['resigned', false],
    'terminated' => ['terminated', false],
]);

it('checks the assigned date against today and the joining date', function (string $date, ?string $message) {
    $hr = userWithRole('hr');
    $person = aaPerson(['joining_date' => '2026-03-02']);
    $asset = assetRow();

    if ($message === null) {
        app(AssignAsset::class)->handle($hr, $asset, $person, $date, null);
        expect(AssetAssignment::query()->sole()->assigned_on->format('Y-m-d'))->toBe($date);
    } else {
        expect(aaRefusal(fn () => app(AssignAsset::class)->handle($hr, $asset, $person, $date, null)))->toBe(['assigned_on' => $message])
            ->and(AssetAssignment::query()->count())->toBe(0);
    }

    assertAssetInvariant();
})->with([
    'exactly today' => ['2026-11-26', null],
    'same day as joining' => ['2026-03-02', null],
    'tomorrow' => ['2026-11-27', 'The assigned date cannot be in the future.'],
    'the day before joining' => ['2026-03-01', 'The assigned date cannot be before Sara Ahmed joined (2 Mar 2026).'],
    'not a date' => ['2026-02-30', 'Enter the date the asset was handed over.'],
]);

it('takes today from the attendance day cutoff, not the calendar date', function () {
    // 00:30 on the 27th is still the 26th's attendance day (cutoff 04:00), so the 27th is the future.
    $this->travelTo(CarbonImmutable::parse('2026-11-27 00:30:00', 'Asia/Karachi'));

    expect(aaRefusal(fn () => app(AssignAsset::class)->handle(userWithRole('hr'), assetRow(), aaPerson(), '2026-11-27', null)))
        ->toBe(['assigned_on' => AssignAsset::FUTURE]);
});

it('refuses a note over 500 characters', function () {
    expect(aaRefusal(fn () => app(AssignAsset::class)->handle(userWithRole('hr'), assetRow(), aaPerson(), '2026-11-26', str_repeat('a', 501))))
        ->toBe(['note' => AssignAsset::NOTE_TOO_LONG]);

    app(AssignAsset::class)->handle(userWithRole('hr'), $asset = assetRow(), aaPerson(), '2026-11-26', str_repeat('a', 500));
    expect($asset->refresh()->status)->toBe('assigned');
});

// ---------------------------------------------------------------- routes

it('assigns through the route with a flash naming the asset and the person', function () {
    $hr = userWithRole('hr');
    $sara = aaPerson();
    $asset = assetRow(['asset_code' => 'AST-0004']);

    $this->actingAs($hr)->from('/assets')->post("/assets/{$asset->id}/assign", [
        'employee_id' => $sara->id, 'assigned_on' => '2026-11-26', 'note' => '',
    ])->assertRedirect('/assets')->assertSessionHasNoErrors()->assertSessionHas('success', 'AST-0004 assigned to Sara Ahmed.');

    expect($asset->refresh()->status)->toBe('assigned')
        ->and(AssetAssignment::query()->sole()->note)->toBeNull();
});

it('shows the refusals as form errors on the route', function () {
    $hr = userWithRole('hr');
    $asset = assetRow(['status' => 'retired']);

    $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => aaPerson()->id, 'assigned_on' => '2026-11-26'])
        ->assertSessionHasErrors(['asset' => 'This asset is not available. It is retired.']);
    $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => 999999, 'assigned_on' => '2026-11-26'])
        ->assertSessionHasErrors('employee_id');
    $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => aaPerson()->id, 'assigned_on' => '26/11/2026'])
        ->assertSessionHasErrors(['assigned_on' => AssignAsset::BAD_DATE]);
    $this->actingAs($hr)->post("/assets/{$asset->id}/assign", ['employee_id' => aaPerson()->id, 'assigned_on' => '2026-11-26', 'note' => str_repeat('x', 501)])
        ->assertSessionHasErrors(['note' => AssignAsset::NOTE_TOO_LONG]);
    $this->actingAs($hr)->post('/assets/999999/assign', ['employee_id' => aaPerson()->id, 'assigned_on' => '2026-11-26'])->assertNotFound();
});

it('lets Admin assign too', function () {
    $asset = assetRow();

    $this->actingAs(userWithRole('admin'))->post("/assets/{$asset->id}/assign", ['employee_id' => aaPerson()->id, 'assigned_on' => '2026-11-26'])
        ->assertSessionHasNoErrors();

    expect($asset->refresh()->status)->toBe('assigned');
});

it('answers 403 to Managers and Employees on all three routes, and writes nothing', function (string $role) {
    $user = userWithRole($role);
    employeeFor($user);
    $holder = aaPerson();
    $free = assetRow();
    $held = assignedAsset($holder);
    $assignment = AssetAssignment::query()->sole();

    $this->actingAs($user)->post("/assets/{$free->id}/assign", ['employee_id' => $holder->id, 'assigned_on' => '2026-11-26'])->assertForbidden();
    $this->actingAs($user)->post("/assets/assignments/{$assignment->id}/return", [
        'returned_on' => '2026-11-26', 'return_condition' => 'good', 'next_status' => 'available',
    ])->assertForbidden();
    $this->actingAs($user)->patch("/assets/assignments/{$assignment->id}/note", ['note' => 'mine now'])->assertForbidden();

    expect($free->refresh()->status)->toBe('available')
        ->and($held->refresh()->status)->toBe('assigned')
        ->and($assignment->refresh()->note)->toBeNull()
        ->and(Activity::query()->where('log_name', 'assets')->count())->toBe(0);
})->with(['manager', 'employee']);

it('does not let a guest near the routes', function () {
    $asset = assetRow();

    $this->post("/assets/{$asset->id}/assign", ['employee_id' => aaPerson()->id, 'assigned_on' => '2026-11-26'])->assertRedirect('/login');
    expect($asset->refresh()->status)->toBe('available');
});
