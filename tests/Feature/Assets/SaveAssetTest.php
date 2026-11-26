<?php

use App\Actions\Assets\SaveAsset;
use App\Models\Asset;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Spatie\Activitylog\Models\Activity;

function saAudit(string $description): Activity
{
    return Activity::query()->where('log_name', 'assets')->where('description', $description)->latest('id')->firstOrFail();
}

function saForm(array $over = []): array
{
    return array_merge([
        'name' => 'MacBook Pro 14',
        'asset_category_id' => assetCategory('Laptop')->id,
        'serial_number' => 'ABC123',
        'purchase_date' => '2026-11-01',
        'cost_rupees' => 450000,
        'condition' => 'new',
        'notes' => 'With charger',
    ], $over);
}

it('hands out AST-0001 then AST-0002, starts available and records who added it', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/assets', saForm())->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Asset AST-0001 added.');
    $this->actingAs($hr)->post('/assets', saForm(['serial_number' => 'XYZ9']))
        ->assertSessionHas('success', 'Asset AST-0002 added.');

    $first = Asset::query()->where('asset_code', 'AST-0001')->sole();

    expect($first->status)->toBe('available')
        ->and($first->created_by)->toBe($hr->id)
        ->and($first->cost)->toBe(45000000)
        ->and($first->purchase_date->format('Y-m-d'))->toBe('2026-11-01')
        ->and(Asset::count())->toBe(2);
});

it('audits a created asset with its figures', function () {
    $this->actingAs(userWithRole('admin'))->post('/assets', saForm())->assertSessionHasNoErrors();

    expect(saAudit('Asset created')->properties['attributes'])->toBe([
        'code' => 'AST-0001', 'name' => 'MacBook Pro 14', 'category' => 'Laptop', 'serial_number' => 'ABC123',
        'purchase_date' => '2026-11-01', 'cost' => 45000000, 'cost_formatted' => 'Rs 450,000', 'condition' => 'new', 'notes' => 'With charger',
    ]);
});

it('refuses a duplicate serial number ignoring case, naming the other asset', function () {
    $existing = assetRow(['serial_number' => 'ABC123']);

    $this->actingAs(userWithRole('hr'))->post('/assets', saForm(['serial_number' => 'abc123']))
        ->assertSessionHasErrors(['serial_number' => "Another asset ({$existing->asset_code}) already has this serial number."]);

    expect(Asset::count())->toBe(1);
});

it('refuses a serial taken by another asset on edit but not the asset own serial', function () {
    $a = assetRow(['serial_number' => 'ABC123']);
    $b = assetRow(['serial_number' => 'OTHER1']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/assets/{$b->id}", saForm(['serial_number' => 'abc123']))->assertSessionHasErrors('serial_number');
    $this->actingAs($hr)->put("/assets/{$a->id}", saForm(['serial_number' => 'abc123']))->assertSessionHasNoErrors();

    expect($a->fresh()->serial_number)->toBe('abc123')->and($b->fresh()->serial_number)->toBe('OTHER1');
});

it('lets blank serials coexist and stores them as null', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/assets', saForm(['serial_number' => '']))->assertSessionHasNoErrors();
    $this->actingAs($hr)->post('/assets', saForm(['serial_number' => '   ']))->assertSessionHasNoErrors();

    expect(Asset::query()->whereNull('serial_number')->count())->toBe(2);
});

it('refuses a purchase date in the future', function () {
    $this->actingAs(userWithRole('hr'))->post('/assets', saForm(['purchase_date' => '2099-01-01']))
        ->assertSessionHasErrors(['purchase_date' => 'The purchase date cannot be in the future.']);

    expect(Asset::count())->toBe(0);
});

it('accepts a purchase date that is today', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $hr = userWithRole('hr');

    $this->actingAs($hr)->post('/assets', saForm(['purchase_date' => '2026-11-26']))->assertSessionHasNoErrors();
    $this->actingAs($hr)->post('/assets', saForm(['serial_number' => 'NEXT', 'purchase_date' => '2026-11-27']))
        ->assertSessionHasErrors(['purchase_date' => 'The purchase date cannot be in the future.']);

    expect(Asset::query()->where('serial_number', 'ABC123')->sole()->purchase_date->format('Y-m-d'))->toBe('2026-11-26');
});

it('refuses a missing or unknown category', function (mixed $category) {
    $this->actingAs(userWithRole('hr'))->post('/assets', saForm(['asset_category_id' => $category]))
        ->assertSessionHasErrors(['asset_category_id' => 'Choose a category.']);
})->with([null, 99999]);

it('validates the form fields', function (array $over, string $field) {
    $this->actingAs(userWithRole('hr'))->post('/assets', saForm($over))->assertSessionHasErrors($field);
})->with([
    'blank name' => [['name' => '  '], 'name'],
    'long name' => [['name' => str_repeat('a', 121)], 'name'],
    'bad condition' => [['condition' => 'shiny'], 'condition'],
    'negative cost' => [['cost_rupees' => -1], 'cost_rupees'],
    'huge cost' => [['cost_rupees' => 100000000], 'cost_rupees'],
    'fractional cost' => [['cost_rupees' => 1.5], 'cost_rupees'],
    'long notes' => [['notes' => str_repeat('n', 1001)], 'notes'],
    'long serial' => [['serial_number' => str_repeat('s', 101)], 'serial_number'],
]);

it('runs the save again once after a lost code race and does not hide a second one', function () {
    // The insert fails on the code unique index (another save committed the same code between the read and the write).
    // The action asks AssetCode::next() again inside a fresh transaction.
    $hr = userWithRole('hr');
    $attempts = 0;
    $collide = function () {
        $driver = new PDOException('UNIQUE constraint failed: assets.asset_code');
        $driver->errorInfo = ['23000', 19, 'UNIQUE constraint failed: assets.asset_code'];

        throw new UniqueConstraintViolationException('sqlite', 'insert into assets', [], $driver);
    };

    Asset::creating(function () use (&$attempts, $collide): void {
        $attempts++;

        if ($attempts <= 1 || $attempts >= 10) {
            $collide();
        }
    });

    $made = app(SaveAsset::class)->create($hr, ['name' => 'Second', 'asset_category_id' => assetCategory()->id, 'condition' => 'good']);

    expect($attempts)->toBe(2)->and($made->asset_code)->toBe('AST-0001')->and(Asset::count())->toBe(1);

    // Two collisions in a row: the second is thrown, not swallowed.
    $attempts = 9;
    expect(fn () => app(SaveAsset::class)->create($hr, ['name' => 'Third', 'asset_category_id' => assetCategory()->id, 'condition' => 'good']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('changes only the fields given on update and never the status', function () {
    $asset = assetRow(['name' => 'Old name', 'serial_number' => 'S1', 'cost' => 100000, 'status' => 'in_repair', 'condition' => 'fair']);

    app(SaveAsset::class)->update(userWithRole('hr'), $asset, ['name' => 'New name', 'status' => 'retired']);

    $fresh = $asset->fresh();

    expect($fresh->name)->toBe('New name')
        ->and($fresh->serial_number)->toBe('S1')
        ->and($fresh->cost)->toBe(100000)
        ->and($fresh->condition)->toBe('fair')
        ->and($fresh->status)->toBe('in_repair');
});

it('audits old and new of the changed fields only, with the cost in paisa and formatted', function () {
    $asset = assetRow(['name' => 'Old', 'cost' => 100000, 'condition' => 'good', 'category' => 'Laptop']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->put("/assets/{$asset->id}", saForm([
        'name' => 'Old', 'serial_number' => $asset->serial_number, 'purchase_date' => null, 'notes' => null,
        'cost_rupees' => 2500, 'condition' => 'good', 'asset_category_id' => assetCategory('Phone')->id,
    ]))->assertSessionHasNoErrors()->assertSessionHas('success', 'Asset updated.');

    $audit = saAudit('Asset updated');

    expect($audit->properties['old'])->toEqual(['category' => 'Laptop', 'cost' => 100000, 'cost_formatted' => 'Rs 1,000'])
        ->and($audit->properties['attributes'])->toEqual(['category' => 'Phone', 'cost' => 250000, 'cost_formatted' => 'Rs 2,500'])
        ->and($audit->causer_id)->toBe($hr->id);
});

it('writes no audit entry when nothing changes', function () {
    $asset = assetRow(['name' => 'Same', 'serial_number' => 'S1', 'condition' => 'good', 'notes' => null]);

    $this->actingAs(userWithRole('hr'))->put("/assets/{$asset->id}", saForm([
        'name' => 'Same', 'serial_number' => 'S1', 'purchase_date' => null, 'cost_rupees' => null, 'condition' => 'good',
        'notes' => null, 'asset_category_id' => $asset->asset_category_id,
    ]))->assertSessionHasNoErrors();

    expect(Activity::query()->where('description', 'Asset updated')->count())->toBe(0);
});

it('answers a same-instant duplicate serial on edit with the validation message, not a 500', function () {
    $hr = userWithRole('hr');
    assetRow(['asset_code' => 'AST-0001', 'serial_number' => 'TAKEN']);
    $asset = assetRow(['asset_code' => 'AST-0002', 'serial_number' => 'MINE']);

    // The check inside the transaction found nothing; the unique index then refuses the UPDATE (another save won the race).
    Asset::updating(function (): void {
        $driver = new PDOException('UNIQUE constraint failed: assets.serial_number');
        $driver->errorInfo = ['23000', 19, 'UNIQUE constraint failed: assets.serial_number'];

        throw new UniqueConstraintViolationException('sqlite', 'update assets set serial_number = ? where id = ?', [], $driver);
    });

    $this->actingAs($hr)->put("/assets/{$asset->id}", saForm(['serial_number' => 'TAKEN', 'asset_category_id' => $asset->asset_category_id]))
        ->assertSessionHasErrors(['serial_number' => 'Another asset (AST-0001) already has this serial number.']);

    expect($asset->fresh()->serial_number)->toBe('MINE')
        ->and(Activity::query()->where('description', 'Asset updated')->count())->toBe(0);
});

it('rethrows a unique violation on edit that is not about the serial number', function () {
    $asset = assetRow(['serial_number' => 'MINE']);

    Asset::updating(function (): void {
        // The statement quotes serial_number, the driver's message does not: only the driver's message counts.
        $driver = new PDOException('UNIQUE constraint failed: assets.asset_code');
        $driver->errorInfo = ['23000', 19, 'UNIQUE constraint failed: assets.asset_code'];

        throw new UniqueConstraintViolationException('sqlite', 'update assets set serial_number = ? where id = ?', [], $driver);
    });

    expect(fn () => app(SaveAsset::class)->update(userWithRole('hr'), $asset, ['serial_number' => 'OTHER']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('leaves the cost alone when the request does not send cost_rupees, and sets or clears it when it does', function () {
    $hr = userWithRole('hr');
    $asset = assetRow(['cost' => 123400]);
    $form = saForm(['asset_category_id' => $asset->asset_category_id, 'serial_number' => null, 'purchase_date' => null]);
    unset($form['cost_rupees']);

    $this->actingAs($hr)->put("/assets/{$asset->id}", $form)->assertSessionHasNoErrors();
    expect($asset->fresh()->cost)->toBe(123400);

    $this->actingAs($hr)->put("/assets/{$asset->id}", $form + ['cost_rupees' => 50])->assertSessionHasNoErrors();
    expect($asset->fresh()->cost)->toBe(5000);

    $this->actingAs($hr)->put("/assets/{$asset->id}", $form + ['cost_rupees' => ''])->assertSessionHasNoErrors();
    expect($asset->fresh()->cost)->toBeNull();
});

it('keeps Manager and Employee out of add and edit', function (string $role) {
    $asset = assetRow();
    $user = userWithRole($role);

    $this->actingAs($user)->post('/assets', saForm())->assertForbidden();
    $this->actingAs($user)->put("/assets/{$asset->id}", saForm())->assertForbidden();

    expect(Asset::count())->toBe(1);
})->with(['manager', 'employee']);

it('sends a guest to sign in', function () {
    $this->post('/assets', saForm())->assertRedirect('/login');
});
