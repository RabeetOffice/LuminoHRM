<?php

namespace App\Actions\Exits;

use App\Models\Clearance;
use App\Models\Resignation;

/**
 * Creates an exit's clearance checklist: the three built-in items, then each custom item of Settings > Exit in order
 * (a snapshot: later edits to the settings do not touch an exit that already has its list). Called inside the final
 * approval's transaction. Idempotent: an exit that already has rows keeps them.
 */
class SeedClearance
{
    /** The built-in items, in order: kind => label. */
    public const BUILT_IN = [
        'assets' => 'Assets returned',
        'access' => 'System access removed',
        'dues' => 'Dues cleared',
    ];

    public function for(Resignation $resignation): void
    {
        if (Clearance::query()->where('resignation_id', $resignation->id)->exists()) {
            return;
        }

        $rows = [];

        foreach (self::BUILT_IN as $kind => $label) {
            $rows[] = ['kind' => $kind, 'label' => $label];
        }

        foreach ((array) settings('exit.clearance_items', []) as $item) {
            if (is_string($item) && trim($item) !== '') {
                $rows[] = ['kind' => 'custom', 'label' => trim($item)];
            }
        }

        foreach ($rows as $order => $row) {
            Clearance::query()->create([
                'resignation_id' => $resignation->id,
                'kind' => $row['kind'],
                'label' => $row['label'],
                'sort_order' => $order,
            ]);
        }
    }
}
