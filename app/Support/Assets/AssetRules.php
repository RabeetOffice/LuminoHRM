<?php

namespace App\Support\Assets;

use App\Models\Asset;
use App\Models\User;

/**
 * The hand-made status changes of an asset (the Status menu and the import). `assigned` is never set by hand: an assignment
 * sets it and a return clears it. statusRefusal() returns the plain-English reason a change is not allowed, or null.
 *
 *   available <-> in_repair           assets.assign (Admin, HR)
 *   available / in_repair -> retired  assets.manage (Admin only)
 *   retired -> available              assets.manage (Admin only)
 *   retired -> in_repair              never (bring it back to available first)
 *   anything -> assigned              never (assign it to an employee)
 *   assigned -> anything              never (return it first)
 */
class AssetRules
{
    public const ONLY_ADMIN_RETIRES = 'Only an Admin can retire an asset.';

    public const ONLY_ADMIN_RESTORES = 'Only an Admin can bring a retired asset back.';

    public const RETIRED_TO_REPAIR = 'Bring a retired asset back to available first.';

    public const TO_ASSIGNED = 'Assign the asset to an employee instead.';

    public const NOT_ALLOWED = 'You are not allowed to change the status of an asset.';

    /** Status labels as people read them. */
    public const STATUS_LABELS = [
        'available' => 'available',
        'assigned' => 'assigned',
        'in_repair' => 'in repair',
        'retired' => 'retired',
    ];

    public static function alreadyMessage(string $status): string
    {
        return 'The asset is already '.(self::STATUS_LABELS[$status] ?? $status).'.';
    }

    public static function heldMessage(string $code, string $holder): string
    {
        return "{$code} is held by {$holder}. Return it first.";
    }

    /**
     * Why $by cannot move an asset from $from to $to, or null when they can.
     *
     * @param  bool  $hasOpenAssignment  true when the asset is handed out right now (read under the asset's lock by the caller)
     * @param  Asset|null  $asset  needed for the "held by" text (its code and open assignment's employee)
     */
    public static function statusRefusal(User $by, string $from, string $to, bool $hasOpenAssignment, ?Asset $asset = null): ?string
    {
        if ($from === 'assigned' || $hasOpenAssignment) {
            $open = $asset === null ? null : $asset->getRelationValue('openAssignment');
            $employee = $open === null ? null : $open->getRelationValue('employee');
            $holder = $employee === null ? 'an employee' : $employee->full_name;

            return self::heldMessage($asset === null ? 'This asset' : $asset->asset_code, $holder);
        }

        if ($from === $to) {
            return self::alreadyMessage($to);
        }

        if ($to === 'assigned') {
            return self::TO_ASSIGNED;
        }

        $assign = $by->can('assets.assign');
        $manage = $by->can('assets.manage');

        return match ([$from, $to]) {
            ['available', 'in_repair'], ['in_repair', 'available'] => $assign ? null : self::NOT_ALLOWED,
            ['available', 'retired'], ['in_repair', 'retired'] => $manage ? null : self::ONLY_ADMIN_RETIRES,
            ['retired', 'available'] => $manage ? null : self::ONLY_ADMIN_RESTORES,
            ['retired', 'in_repair'] => self::RETIRED_TO_REPAIR,
            default => self::NOT_ALLOWED,
        };
    }
}
