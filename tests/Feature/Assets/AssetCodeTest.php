<?php

use App\Models\Asset;
use App\Support\Assets\AssetCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

it('starts at AST-0001 on an empty table', function () {
    expect(AssetCode::next())->toBe('AST-0001');
});

it('continues from the highest code', function () {
    assetRow(['asset_code' => 'AST-0003']);
    assetRow(['asset_code' => 'AST-0011']);
    assetRow(['asset_code' => 'AST-0002']);

    expect(AssetCode::next())->toBe('AST-0012');
});

it('grows past AST-9999', function () {
    assetRow(['asset_code' => 'AST-9999']);

    expect(AssetCode::next())->toBe('AST-10000');

    assetRow(['asset_code' => 'AST-10000']);

    expect(AssetCode::next())->toBe('AST-10001');
});

it('ignores codes that do not follow the pattern', function () {
    assetRow(['asset_code' => 'AST-0005']);
    assetRow(['asset_code' => 'AST-OLD']);

    expect(AssetCode::next())->toBe('AST-0006');
});

it('matches the pattern for typed codes', function () {
    expect(preg_match(AssetCode::PATTERN, 'AST-0001'))->toBe(1)
        ->and(preg_match(AssetCode::PATTERN, 'AST-12345'))->toBe(1)
        ->and(preg_match(AssetCode::PATTERN, 'AST-001'))->toBe(0)
        ->and(preg_match(AssetCode::PATTERN, 'ast-0001'))->toBe(0)
        ->and(preg_match(AssetCode::PATTERN, 'AST-0001x'))->toBe(0);
});

it('refuses a duplicate code and recognises the conflict', function () {
    assetRow(['asset_code' => 'AST-0001']);

    try {
        DB::transaction(fn () => Asset::query()->create([
            'asset_code' => AssetCode::PREFIX.'0001', 'name' => 'Dup', 'asset_category_id' => assetCategory()->id, 'condition' => 'good',
        ]));
        $this->fail('The duplicate code was accepted.');
    } catch (UniqueConstraintViolationException $e) {
        expect(AssetCode::isCodeConflict($e))->toBeTrue();
    }
});

it('does not take a serial number conflict for a code conflict', function () {
    assetRow(['serial_number' => 'SN-1']);

    try {
        Asset::query()->create([
            'asset_code' => AssetCode::next(), 'name' => 'Dup', 'asset_category_id' => assetCategory()->id,
            'condition' => 'good', 'serial_number' => 'SN-1',
        ]);
        $this->fail('The duplicate serial number was accepted.');
    } catch (UniqueConstraintViolationException $e) {
        expect(AssetCode::isCodeConflict($e))->toBeFalse();
    }
});

it('hands out the next code inside the saving transaction', function () {
    $first = DB::transaction(fn () => Asset::query()->create([
        'asset_code' => AssetCode::next(), 'name' => 'One', 'asset_category_id' => assetCategory()->id, 'condition' => 'new',
    ]));
    $second = DB::transaction(fn () => Asset::query()->create([
        'asset_code' => AssetCode::next(), 'name' => 'Two', 'asset_category_id' => assetCategory()->id, 'condition' => 'new',
    ]));

    expect([$first->asset_code, $second->asset_code])->toBe(['AST-0001', 'AST-0002']);
});
