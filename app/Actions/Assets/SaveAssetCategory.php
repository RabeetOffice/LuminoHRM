<?php

namespace App\Actions\Assets;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Names;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Create, rename and delete asset categories (Settings > Asset categories). One transaction each, audited in it
 * (area assets). Update and delete lock the category row first.
 *
 * Refusals are ValidationExceptions: a duplicate name (compared lower-cased in PHP, because SQLite compares case-sensitively
 * and MariaDB does not) on key "name", a category that assets still use on key "category".
 */
class SaveAssetCategory
{
    /** @param  string  $name  the name typed */
    public static function duplicateMessage(string $name): string
    {
        return "A category called {$name} already exists.";
    }

    public static function inUseMessage(int $count): string
    {
        return ($count === 1 ? '1 asset uses' : "{$count} assets use").' this category. Move them to another category first.';
    }

    /** @param  array<string, mixed>  $data  `name`: the name typed */
    public function create(User $by, array $data): AssetCategory
    {
        $name = $this->clean($data['name'] ?? '');

        return DB::transaction(function () use ($by, $name): AssetCategory {
            // Every row is read under the lock, so two people adding at once queue up and the second sees the first.
            $existing = AssetCategory::query()->lockForUpdate()->get(['id', 'name', 'sort_order']);
            $this->refuseDuplicate($existing->all(), $name, null);

            $category = AssetCategory::query()->create([
                'name' => $name,
                'sort_order' => (int) $existing->max('sort_order') + 1,
            ]);

            Audit::record('assets', 'Asset category created', $category, [
                'attributes' => ['name' => $category->name, 'sort_order' => $category->sort_order],
            ], $by);

            return $category;
        }, 3);
    }

    /** @param  array<string, mixed>  $data  `name`: the name typed */
    public function update(User $by, AssetCategory $category, array $data): AssetCategory
    {
        $name = $this->clean($data['name'] ?? '');

        return DB::transaction(function () use ($by, $category, $name): AssetCategory {
            $current = AssetCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();
            $this->refuseDuplicate(AssetCategory::query()->get(['id', 'name'])->all(), $name, $current->id);

            $old = ['name' => $current->name];
            $current->name = $name;

            if ($current->isDirty('name')) {
                $current->save();

                Audit::record('assets', 'Asset category updated', $current, [
                    'old' => $old,
                    'attributes' => ['name' => $current->name],
                ], $by);
            }

            return $current;
        }, 3);
    }

    public function delete(User $by, AssetCategory $category): void
    {
        try {
            DB::transaction(function () use ($by, $category): void {
                $current = AssetCategory::query()->whereKey($category->getKey())->lockForUpdate()->firstOrFail();

                // Retired assets count too: the foreign key restricts the delete for every asset row.
                $used = Asset::query()->where('asset_category_id', $current->id)->count();

                if ($used > 0) {
                    throw ValidationException::withMessages(['category' => self::inUseMessage($used)]);
                }

                $old = ['name' => $current->name, 'sort_order' => $current->sort_order];
                $current->delete();

                Audit::record('assets', 'Asset category deleted', $current, ['old' => $old], $by);
            }, 3);
        } catch (QueryException $e) {
            // Only a foreign key refusal (SQLSTATE 23000) is the friendly case; anything else is a real fault.
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            // An asset was added after the count above and the restrict foreign key refused (the backstop).
            $used = Asset::query()->where('asset_category_id', $category->getKey())->count();

            throw ValidationException::withMessages(['category' => self::inUseMessage(max($used, 1))]);
        }
    }

    private function clean(mixed $name): string
    {
        return (string) Names::normalise($name);
    }

    /**
     * @param  array<int, AssetCategory>  $categories
     */
    private function refuseDuplicate(array $categories, string $name, ?int $exceptId): void
    {
        $key = Names::key($name);

        foreach ($categories as $other) {
            if ($other->id !== $exceptId && Names::key($other->name) === $key) {
                throw ValidationException::withMessages(['name' => self::duplicateMessage($name)]);
            }
        }
    }
}
