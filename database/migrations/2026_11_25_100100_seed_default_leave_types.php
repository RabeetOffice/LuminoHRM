<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Data migration: production runs `migrate --force` but never seeds. Inserts the default leave types (design section
// 15: Annual 14, Sick 8, Casual 10, Unpaid) only when leave_types is empty, so running it again (or on a database where
// HR has already renamed, edited or removed types) never duplicates or overwrites anything. Query builder only: no
// model events, no audit entry (a system write). Frozen values: later changes to LeaveType never change what this writes.
return new class extends Migration
{
    private const TYPES = [
        ['name' => 'Annual', 'yearly_quota' => 14, 'is_paid' => true, 'carry_forward' => true, 'carry_forward_cap' => 5, 'is_system' => false, 'colour' => 'emerald', 'sort_order' => 1],
        ['name' => 'Sick', 'yearly_quota' => 8, 'is_paid' => true, 'carry_forward' => false, 'carry_forward_cap' => 0, 'is_system' => false, 'colour' => 'rose', 'sort_order' => 2],
        ['name' => 'Casual', 'yearly_quota' => 10, 'is_paid' => true, 'carry_forward' => false, 'carry_forward_cap' => 0, 'is_system' => false, 'colour' => 'sky', 'sort_order' => 3],
        ['name' => 'Unpaid', 'yearly_quota' => 0, 'is_paid' => false, 'carry_forward' => false, 'carry_forward_cap' => 0, 'is_system' => true, 'colour' => 'slate', 'sort_order' => 4],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            if (DB::table('leave_types')->lockForUpdate()->exists()) {
                return;
            }

            $now = now();

            DB::table('leave_types')->insert(array_map(fn (array $type) => [
                ...$type,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], self::TYPES));
        });
    }

    public function down(): void
    {
        // Intentionally empty: the table itself is dropped by the schema migration's down().
    }
};
