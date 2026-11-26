<?php

namespace App\Support\Assets;

use App\Models\AssetAssignment;
use Illuminate\Support\Facades\DB;

/** The dashboard's "My assets" card: what the viewer holds themself. Never a cost, a code or anyone else's asset. */
class DashboardAssets
{
    /** How many asset names the card lists before it says "and N more". */
    public const NAMES = 5;

    /**
     * @return array{count: int, names: list<string>, more: int}
     */
    public static function mine(int $employeeId): array
    {
        $count = AssetAssignment::query()->where('employee_id', $employeeId)->open()->count();

        if ($count === 0) {
            return ['count' => 0, 'names' => [], 'more' => 0];
        }

        $names = array_values(DB::table('asset_assignments')
            ->join('assets', 'assets.id', '=', 'asset_assignments.asset_id')
            ->where('asset_assignments.employee_id', $employeeId)
            ->whereNull('asset_assignments.returned_on')
            ->orderBy('asset_assignments.assigned_on')
            ->orderBy('asset_assignments.id')
            ->limit(self::NAMES)
            ->pluck('assets.name')
            ->map(fn ($name) => (string) $name)
            ->all());

        return ['count' => $count, 'names' => $names, 'more' => max(0, $count - self::NAMES)];
    }
}
