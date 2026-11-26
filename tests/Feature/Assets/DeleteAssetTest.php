<?php

use App\Models\Asset;
use Spatie\Activitylog\Models\Activity;

it('lets Admin delete a never-assigned asset and audits it', function () {
    $admin = userWithRole('admin');
    $asset = assetRow(['name' => 'Spare mouse', 'category' => 'Phone']);

    $this->actingAs($admin)->delete("/assets/{$asset->id}")->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Asset deleted.');

    $audit = Activity::query()->where('description', 'Asset deleted')->latest('id')->firstOrFail();

    expect(Asset::find($asset->id))->toBeNull()
        ->and($audit->properties['old'])->toBe(['code' => $asset->asset_code, 'name' => 'Spare mouse', 'category' => 'Phone'])
        ->and($audit->causer_id)->toBe($admin->id);
});

it('keeps HR, Manager and Employee out', function (string $role) {
    $asset = assetRow();

    $this->actingAs(userWithRole($role))->delete("/assets/{$asset->id}")->assertForbidden();
    expect(Asset::find($asset->id))->not->toBeNull();
})->with(['hr', 'manager', 'employee']);

it('refuses an asset that was assigned before and keeps it', function () {
    $employee = employeeFor(userWithRole('employee'));
    $asset = assignedAsset($employee, ['returned_on' => '2026-11-22']);

    $this->actingAs(userWithRole('admin'))->delete("/assets/{$asset->id}")
        ->assertSessionHasErrors(['asset' => 'This asset has been assigned before, so it cannot be deleted. Retire it instead.']);

    expect(Asset::find($asset->id))->not->toBeNull()
        ->and(Activity::query()->where('description', 'Asset deleted')->count())->toBe(0);
});

it('refuses an asset that is held right now', function () {
    $asset = assignedAsset(employeeFor(userWithRole('employee')));

    $this->actingAs(userWithRole('admin'))->delete("/assets/{$asset->id}")->assertSessionHasErrors('asset');
    expect(Asset::find($asset->id))->not->toBeNull();
});
