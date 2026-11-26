<?php

use App\Actions\Assets\ChangeAssetStatus;
use App\Models\AssetAssignment;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

it('lets Admin and HR move available and in repair back and forth and audits it', function (string $role) {
    $user = userWithRole($role);
    $asset = assetRow();

    $this->actingAs($user)->post("/assets/{$asset->id}/status", ['status' => 'in_repair'])->assertSessionHasNoErrors();
    expect($asset->fresh()->status)->toBe('in_repair');

    $this->actingAs($user)->post("/assets/{$asset->id}/status", ['status' => 'available'])->assertSessionHasNoErrors();
    expect($asset->fresh()->status)->toBe('available');

    $audit = Activity::query()->where('description', 'Asset status changed')->latest('id')->firstOrFail();
    expect($audit->properties['old'])->toBe(['status' => 'in_repair'])
        ->and($audit->properties['attributes'])->toBe(['status' => 'available'])
        ->and($audit->subject_id)->toBe($asset->id)
        ->and($audit->causer_id)->toBe($user->id);
})->with(['admin', 'hr']);

it('lets Admin retire and restore, and refuses HR', function () {
    $asset = assetRow();

    $this->actingAs(userWithRole('hr'))->post("/assets/{$asset->id}/status", ['status' => 'retired'])
        ->assertSessionHasErrors(['status' => 'Only an Admin can retire an asset.']);
    expect($asset->fresh()->status)->toBe('available');

    $this->actingAs(userWithRole('admin'))->post("/assets/{$asset->id}/status", ['status' => 'retired'])->assertSessionHasNoErrors();
    expect($asset->fresh()->status)->toBe('retired');

    $this->actingAs(userWithRole('hr'))->post("/assets/{$asset->id}/status", ['status' => 'available'])
        ->assertSessionHasErrors(['status' => 'Only an Admin can bring a retired asset back.']);

    $this->actingAs(userWithRole('admin'))->post("/assets/{$asset->id}/status", ['status' => 'available'])->assertSessionHasNoErrors();
    expect($asset->fresh()->status)->toBe('available');
});

it('refuses a hand-made assigned and the other matrix dead ends', function () {
    $admin = userWithRole('admin');
    $available = assetRow();
    $retired = assetRow(['status' => 'retired']);

    $this->actingAs($admin)->post("/assets/{$available->id}/status", ['status' => 'assigned'])
        ->assertSessionHasErrors(['status' => 'Assign the asset to an employee instead.']);
    $this->actingAs($admin)->post("/assets/{$retired->id}/status", ['status' => 'in_repair'])
        ->assertSessionHasErrors(['status' => 'Bring a retired asset back to available first.']);
    $this->actingAs($admin)->post("/assets/{$available->id}/status", ['status' => 'available'])
        ->assertSessionHasErrors(['status' => 'The asset is already available.']);
    $this->actingAs($admin)->post("/assets/{$available->id}/status", ['status' => 'bogus'])->assertSessionHasErrors('status');
});

it('refuses a change on a held asset naming the holder', function () {
    $employee = employeeFor(userWithRole('employee'), ['first_name' => 'Sara', 'last_name' => 'Khan']);
    $asset = assignedAsset($employee);

    $this->actingAs(userWithRole('admin'))->post("/assets/{$asset->id}/status", ['status' => 'retired'])
        ->assertSessionHasErrors(['status' => "{$asset->asset_code} is held by Sara Khan. Return it first."]);

    expect($asset->fresh()->status)->toBe('assigned');
});

it('re-reads under the lock: a stale model that still says available is refused after an assign committed', function () {
    $employee = employeeFor(userWithRole('employee'), ['first_name' => 'Ali', 'last_name' => 'Raza']);
    $asset = assetRow();
    $stale = $asset->fresh(); // still `available`

    // The hand-over commits first (written directly, as the assign action would).
    $asset->update(['status' => 'assigned']);
    AssetAssignment::query()->create([
        'asset_id' => $asset->id, 'employee_id' => $employee->id, 'assigned_on' => '2026-11-20', 'condition_out' => 'good',
    ]);

    expect($stale->status)->toBe('available');

    expect(fn () => app(ChangeAssetStatus::class)->handle(userWithRole('admin'), $stale, 'in_repair'))
        ->toThrow(ValidationException::class, "{$asset->asset_code} is held by Ali Raza. Return it first.");

    expect($asset->fresh()->status)->toBe('assigned');
});

it('keeps Manager and Employee out of the status route', function (string $role) {
    $asset = assetRow();

    $this->actingAs(userWithRole($role))->post("/assets/{$asset->id}/status", ['status' => 'in_repair'])->assertForbidden();
    expect($asset->fresh()->status)->toBe('available');
})->with(['manager', 'employee']);
