<?php

use App\Models\AssetCategory;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
});

function acAudit(string $description): Activity
{
    return Activity::query()->where('log_name', 'assets')->where('description', $description)->latest('id')->firstOrFail();
}

// ---------------------------------------------------------------- access

it('lets Admin and HR open the page', function (string $role) {
    $this->actingAs(userWithRole($role))->get('/hrm-settings/asset-categories')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('hrm-settings/asset-categories')
            ->has('categories', 5)
            ->where('categories.0.name', 'Laptop')
            ->where('categories.0.assets_count', 0)
            ->where('categories.0.can_delete', true)
            ->has('groups')
            ->where('auth.permissions', fn ($permissions) => collect($permissions)->contains('assets.assign')));
})->with(['admin', 'hr']);

it('keeps Manager and Employee out of all four routes', function (string $role) {
    $user = userWithRole($role);
    $category = assetCategory('Phone');

    $this->actingAs($user)->get('/hrm-settings/asset-categories')->assertForbidden();
    $this->actingAs($user)->post('/hrm-settings/asset-categories', ['name' => 'Tablet'])->assertForbidden();
    $this->actingAs($user)->put("/hrm-settings/asset-categories/{$category->id}", ['name' => 'Changed'])->assertForbidden();
    $this->actingAs($user)->delete("/hrm-settings/asset-categories/{$category->id}")->assertForbidden();

    expect(AssetCategory::count())->toBe(5)
        ->and($category->fresh()->name)->toBe('Phone');
})->with(['manager', 'employee']);

it('sends a guest to sign in', function () {
    $this->get('/hrm-settings/asset-categories')->assertRedirect('/login');
});

// ---------------------------------------------------------------- create

it('adds a category at the end and audits it', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/hrm-settings/asset-categories', ['name' => '  Headset   pro '])
        ->assertRedirect()->assertSessionHasNoErrors();

    $category = AssetCategory::query()->where('name', 'Headset pro')->sole();
    $audit = acAudit('Asset category created');

    expect($category->sort_order)->toBe(6)
        ->and($audit->subject_id)->toBe($category->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['attributes'])->toBe(['name' => 'Headset pro', 'sort_order' => 6]);
});

it('numbers a new category after the highest even when one was deleted', function () {
    $admin = userWithRole('admin');
    AssetCategory::query()->where('name', 'Other')->delete();

    $this->actingAs($admin)->post('/hrm-settings/asset-categories', ['name' => 'Tablet'])->assertSessionHasNoErrors();

    expect(AssetCategory::query()->where('name', 'Tablet')->value('sort_order'))->toBe(5);
});

it('validates the name', function (mixed $name) {
    $this->actingAs(userWithRole('hr'))->post('/hrm-settings/asset-categories', ['name' => $name])->assertSessionHasErrors('name');

    expect(AssetCategory::count())->toBe(5);
})->with([[''], [null], ['   '], [str_repeat('a', 61)]]);

it('accepts a name of 60 characters', function () {
    $this->actingAs(userWithRole('hr'))->post('/hrm-settings/asset-categories', ['name' => str_repeat('a', 60)])->assertSessionHasNoErrors();

    expect(AssetCategory::count())->toBe(6);
});

it('refuses a duplicate name ignoring case', function (string $typed) {
    $this->actingAs(userWithRole('hr'))->post('/hrm-settings/asset-categories', ['name' => $typed])
        ->assertSessionHasErrors(['name' => 'A category called '.trim($typed).' already exists.']);

    expect(AssetCategory::count())->toBe(5)
        ->and(Activity::query()->where('log_name', 'assets')->count())->toBe(0);
})->with(['laptop', 'LAPTOP', 'Laptop', ' laptop ']);

// ---------------------------------------------------------------- rename

it('renames a category and audits old and new', function () {
    $hr = userWithRole('hr');
    $category = assetCategory('Phone');

    $this->actingAs($hr)->put("/hrm-settings/asset-categories/{$category->id}", ['name' => 'Mobile'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $audit = acAudit('Asset category updated');

    expect($category->fresh()->name)->toBe('Mobile')
        ->and($audit->subject_id)->toBe($category->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['old'])->toBe(['name' => 'Phone'])
        ->and($audit->properties['attributes'])->toBe(['name' => 'Mobile']);
});

it('renames a category by changing only the letter case', function () {
    $category = assetCategory('Phone');

    $this->actingAs(userWithRole('hr'))->put("/hrm-settings/asset-categories/{$category->id}", ['name' => 'PHONE'])
        ->assertSessionHasNoErrors();

    $audit = acAudit('Asset category updated');

    expect($category->fresh()->name)->toBe('PHONE')
        ->and($audit->properties['old'])->toBe(['name' => 'Phone'])
        ->and($audit->properties['attributes'])->toBe(['name' => 'PHONE']);
});

it('writes nothing when a rename changes nothing', function () {
    $category = assetCategory('Phone');

    $this->actingAs(userWithRole('hr'))->put("/hrm-settings/asset-categories/{$category->id}", ['name' => ' Phone '])
        ->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'assets')->count())->toBe(0);
});

it('refuses to rename a category to another one ignoring case', function () {
    $category = assetCategory('Phone');

    $this->actingAs(userWithRole('hr'))->put("/hrm-settings/asset-categories/{$category->id}", ['name' => 'monitor'])
        ->assertSessionHasErrors(['name' => 'A category called monitor already exists.']);

    expect($category->fresh()->name)->toBe('Phone')
        ->and(Activity::query()->where('log_name', 'assets')->count())->toBe(0);
});

it('answers 404 for a category that does not exist', function () {
    $this->actingAs(userWithRole('hr'))->put('/hrm-settings/asset-categories/9999', ['name' => 'X'])->assertNotFound();
    $this->actingAs(userWithRole('hr'))->delete('/hrm-settings/asset-categories/9999')->assertNotFound();
});

// ---------------------------------------------------------------- delete

it('deletes an unused category and audits it', function () {
    $hr = userWithRole('hr');
    $category = assetCategory('Phone');

    $this->actingAs($hr)->delete("/hrm-settings/asset-categories/{$category->id}")
        ->assertRedirect()->assertSessionHasNoErrors();

    $audit = acAudit('Asset category deleted');

    expect(AssetCategory::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and($audit->subject_id)->toBe($category->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['old'])->toBe(['name' => 'Phone', 'sort_order' => 2]);
});

it('refuses to delete a category that assets use, retired ones included', function (string $status) {
    $category = assetCategory('Laptop');
    assetRow(['category' => 'Laptop']);
    assetRow(['category' => 'Laptop', 'status' => $status]);

    $this->actingAs(userWithRole('hr'))->delete("/hrm-settings/asset-categories/{$category->id}")
        ->assertSessionHasErrors(['category' => '2 assets use this category. Move them to another category first.']);

    expect(AssetCategory::query()->whereKey($category->id)->exists())->toBeTrue()
        ->and(Activity::query()->where('log_name', 'assets')->count())->toBe(0);
})->with(['available', 'retired']);

it('says "1 asset uses" for a single asset', function () {
    $category = assetCategory('Laptop');
    assetRow(['status' => 'retired']);

    $this->actingAs(userWithRole('hr'))->delete("/hrm-settings/asset-categories/{$category->id}")
        ->assertSessionHasErrors(['category' => '1 asset uses this category. Move them to another category first.']);
});

it('lists the asset count and whether a category can be deleted', function () {
    assetRow(['category' => 'Phone']);
    assetRow(['category' => 'Phone', 'status' => 'retired']);

    $this->actingAs(userWithRole('admin'))->get('/hrm-settings/asset-categories')
        ->assertInertia(fn ($p) => $p
            ->where('categories.1.name', 'Phone')
            ->where('categories.1.assets_count', 2)
            ->where('categories.1.can_delete', false)
            ->where('categories.0.can_delete', true));
});
