<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: production runs `migrate --force` but never seeds. Inserts the five default asset categories only when
// asset_categories is empty, so running it again (or on a database where HR has already renamed or removed categories)
// never duplicates or overwrites anything. Query builder only: no model events, no audit entry (a system write).
// Frozen values: later changes to AssetCategory never change what this writes.
return new class extends Migration
{
    private const NAMES = ['Laptop', 'Phone', 'Monitor', 'Licence', 'Other'];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('asset_categories')->lockForUpdate()->exists()) {
                return;
            }

            $now = now();

            DB::table('asset_categories')->insert(array_map(fn (string $name, int $i) => [
                'name' => $name,
                'sort_order' => $i + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::NAMES, array_keys(self::NAMES)));
        });
    }

    public function down(): void
    {
        // Intentionally empty: the table itself is dropped by the schema migration's down().
    }
};
