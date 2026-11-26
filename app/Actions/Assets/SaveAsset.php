<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\User;
use App\Support\Assets\AssetCode;
use App\Support\Attendance\AttendanceClock;
use App\Support\Audit\Audit;
use App\Support\Names;
use App\Support\Payroll\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add an asset to the register and edit one. One transaction each, audited inside it (area assets).
 *
 * $data keys (all optional on update; only the keys given are touched): name, asset_category_id, serial_number (blank
 * becomes null), purchase_date ("Y-m-d" or null), cost (integer paisa or null), condition, notes (blank becomes null).
 * Status is never touched here: a new asset starts `available`, and ChangeAssetStatus / AssignAsset / ReturnAsset own it.
 *
 * Create takes the category row shared (so it cannot be deleted mid-save) and asks AssetCode::next() for the code; a lost
 * code race is retried once. Update locks the asset row first, then the category row shared when the category changes.
 *
 * Refusals are ValidationExceptions on the field: serial_number (taken by another asset, compared ignoring case in PHP
 * because SQLite and MariaDB differ), purchase_date (in the future), asset_category_id (missing).
 */
class SaveAsset
{
    public const CHOOSE_CATEGORY = 'Choose a category.';

    public const FUTURE_DATE = 'The purchase date cannot be in the future.';

    public static function serialTakenMessage(string $code): string
    {
        return "Another asset ({$code}) already has this serial number.";
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $by, array $data): Asset
    {
        try {
            return DB::transaction(fn () => $this->within($by, null, $data), 3);
        } catch (UniqueConstraintViolationException $e) {
            if (! AssetCode::isCodeConflict($e)) {
                throw $this->serialConflict($data) ?? $e;
            }
        }

        // Someone else took the same code between reading and saving: ask again, once.
        try {
            return DB::transaction(fn () => $this->within($by, null, $data), 3);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->serialConflict($data) ?? $e;
        }
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $by, Asset $asset, array $data): Asset
    {
        try {
            return DB::transaction(function () use ($by, $asset, $data): Asset {
                $locked = Asset::query()->whereKey($asset->getKey())->lockForUpdate()->firstOrFail();

                return $this->within($by, $locked, $data);
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // A same-instant duplicate serial that the check inside the transaction could not see: the validation message,
            // not a 500. Judged on the driver's own message (errorInfo), as AssignAsset does: getMessage() quotes the whole
            // UPDATE, whose column list always names serial_number.
            if (! $this->isSerialConflict($e)) {
                throw $e;
            }

            throw $this->serialConflict($data) ?? $e;
        }
    }

    /**
     * The work itself, for a caller that already holds a transaction (the import). With $locked null a new asset is added;
     * otherwise $locked is the asset row, already read FOR UPDATE by the caller.
     *
     * @param  array<string, mixed>  $data
     */
    public function within(User $by, ?Asset $locked, array $data): Asset
    {
        $clean = $this->clean($data);

        if (array_key_exists('purchase_date', $clean) && $clean['purchase_date'] !== null
            && $clean['purchase_date'] > app(AttendanceClock::class)->today()->toDateString()) {
            throw ValidationException::withMessages(['purchase_date' => self::FUTURE_DATE]);
        }

        if ($locked === null) {
            return $this->add($by, $clean);
        }

        return $this->change($by, $locked, $clean);
    }

    /** @param  array<string, mixed>  $clean */
    private function add(User $by, array $clean): Asset
    {
        // Lock order: every asset row (the code scan), then the category (shared): the same as an edit (asset row, then
        // category), so a create and an edit cannot wait on each other through a category rename.
        $code = AssetCode::next();
        $category = $this->lockCategory($clean['asset_category_id'] ?? null);
        $this->refuseSerial($clean['serial_number'] ?? null, null);

        $asset = new Asset([
            'asset_code' => $code,
            'name' => $clean['name'] ?? '',
            'asset_category_id' => $category->id,
            'serial_number' => $clean['serial_number'] ?? null,
            'purchase_date' => $clean['purchase_date'] ?? null,
            'cost' => $clean['cost'] ?? null,
            'condition' => $clean['condition'] ?? 'good',
            'notes' => $clean['notes'] ?? null,
            'status' => 'available',
            'created_by' => $by->id,
        ]);
        $asset->save();

        Audit::record('assets', 'Asset created', $asset, [
            'attributes' => [
                'code' => $asset->asset_code,
                'name' => $asset->name,
                'category' => $category->name,
                'serial_number' => $asset->serial_number,
                'purchase_date' => $this->dateText($asset),
                'cost' => $asset->cost,
                'cost_formatted' => $asset->cost === null ? null : Money::format($asset->cost),
                'condition' => $asset->condition,
                'notes' => $asset->notes,
            ],
        ], $by);

        return $asset;
    }

    /** @param  array<string, mixed>  $clean */
    private function change(User $by, Asset $asset, array $clean): Asset
    {
        $old = $new = [];
        $category = null;

        foreach (['name', 'serial_number', 'purchase_date', 'cost', 'condition', 'notes'] as $field) {
            if (! array_key_exists($field, $clean)) {
                continue;
            }

            $current = $field === 'purchase_date' ? $this->dateText($asset) : $asset->{$field};

            if ($current !== $clean[$field]) {
                $old[$field] = $current;
                $new[$field] = $clean[$field];
            }
        }

        if (array_key_exists('asset_category_id', $clean)) {
            $wanted = $clean['asset_category_id'];

            if ($wanted !== $asset->asset_category_id) {
                $category = $this->lockCategory($wanted);
                $old['category'] = $asset->category->name;
                $new['category'] = $category->name;
            }
        }

        if ($new === []) {
            return $asset;
        }

        if (array_key_exists('serial_number', $new)) {
            $this->refuseSerial($new['serial_number'], $asset->id);
        }

        foreach ($new as $field => $value) {
            if ($field !== 'category') {
                $asset->{$field} = $value;
            }
        }

        if ($category !== null) {
            $asset->asset_category_id = $category->id;
        }

        $asset->save();

        if (array_key_exists('cost', $new)) {
            $oldCost = $old['cost'] ?? null;
            $newCost = $new['cost'];
            $old['cost_formatted'] = $oldCost === null ? null : Money::format((int) $oldCost);
            $new['cost_formatted'] = $newCost === null ? null : Money::format((int) $newCost);
        }

        Audit::record('assets', 'Asset updated', $asset, ['old' => $old, 'attributes' => $new], $by);

        return $asset;
    }

    /**
     * The values as stored: names collapsed, blanks null, category id an int, cost an int. Keys the caller did not give
     * stay absent (so an update leaves those fields alone).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $clean = [];

        if (array_key_exists('name', $data)) {
            $clean['name'] = (string) Names::normalise($data['name']);
        }

        if (array_key_exists('asset_category_id', $data)) {
            $clean['asset_category_id'] = $data['asset_category_id'] === null || $data['asset_category_id'] === '' ? null : (int) $data['asset_category_id'];
        }

        foreach (['serial_number', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $text = is_string($data[$field]) ? trim($data[$field]) : '';
                $clean[$field] = $text === '' ? null : $text;
            }
        }

        if (array_key_exists('purchase_date', $data)) {
            $clean['purchase_date'] = $data['purchase_date'] === null || $data['purchase_date'] === '' ? null : (string) $data['purchase_date'];
        }

        if (array_key_exists('cost', $data)) {
            $clean['cost'] = $data['cost'] === null || $data['cost'] === '' ? null : (int) $data['cost'];
        }

        if (array_key_exists('condition', $data)) {
            $clean['condition'] = (string) $data['condition'];
        }

        return $clean;
    }

    private function lockCategory(?int $id): AssetCategory
    {
        $category = $id === null ? null : AssetCategory::query()->whereKey($id)->sharedLock()->first();

        if ($category === null) {
            throw ValidationException::withMessages(['asset_category_id' => self::CHOOSE_CATEGORY]);
        }

        return $category;
    }

    private function refuseSerial(?string $serial, ?int $exceptId): void
    {
        if ($serial === null) {
            return;
        }

        $key = mb_strtolower($serial);

        $taken = Asset::query()->whereNotNull('serial_number')->get(['id', 'asset_code', 'serial_number'])
            ->first(fn (Asset $other) => $other->id !== $exceptId && mb_strtolower((string) $other->serial_number) === $key);

        if ($taken !== null) {
            throw ValidationException::withMessages(['serial_number' => self::serialTakenMessage($taken->asset_code)]);
        }
    }

    /**
     * A unique violation that is not the code: the serial index refused a same-instant duplicate (the backstop).
     *
     * @param  array<string, mixed>  $data
     */
    private function serialConflict(array $data): ?ValidationException
    {
        $serial = trim((string) ($data['serial_number'] ?? ''));

        if ($serial === '') {
            return null;
        }

        $other = Asset::query()->where('serial_number', $serial)->first(['asset_code']);

        return ValidationException::withMessages(['serial_number' => self::serialTakenMessage($other->asset_code ?? 'another asset')]);
    }

    /**
     * True when the unique violation is on the serial number. The driver's message: "UNIQUE constraint failed:
     * assets.serial_number" on SQLite, "Duplicate entry 'x' for key 'assets_serial_number_unique'" on MariaDB.
     */
    private function isSerialConflict(UniqueConstraintViolationException $e): bool
    {
        $driverMessage = (string) ($e->errorInfo[2] ?? $e->getPrevious()?->getMessage() ?? '');

        return str_contains($driverMessage, 'assets.serial_number') || str_contains($driverMessage, 'assets_serial_number_unique');
    }

    private function dateText(Asset $asset): ?string
    {
        return $asset->purchase_date?->format('Y-m-d');
    }
}
