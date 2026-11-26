/**
 * Assets helpers shared by the asset screens: the labels people read for an asset's status and condition, and the
 * colour (StatusPill tone) of each status. The values are the ones in config('hrm.assets'); an unknown value falls back
 * to the raw text with a grey tone, so a new value never breaks a screen.
 */
import type { StatusTone } from '@/components/hrm/status-pill';

export type AssetStatus = 'available' | 'assigned' | 'in_repair' | 'retired';

export type AssetCondition = 'new' | 'good' | 'fair' | 'poor' | 'damaged';

export const ASSET_STATUSES: AssetStatus[] = [
    'available',
    'assigned',
    'in_repair',
    'retired',
];

export const ASSET_CONDITIONS: AssetCondition[] = [
    'new',
    'good',
    'fair',
    'poor',
    'damaged',
];

const STATUS_LABELS: Record<AssetStatus, string> = {
    available: 'Available',
    assigned: 'Assigned',
    in_repair: 'In repair',
    retired: 'Retired',
};

const CONDITION_LABELS: Record<AssetCondition, string> = {
    new: 'New',
    good: 'Good',
    fair: 'Fair',
    poor: 'Poor',
    damaged: 'Damaged',
};

// Available is ready to hand out (green), assigned is in use (blue), in repair needs attention (amber), retired is
// out of the register's working set (grey).
const STATUS_TONES: Record<AssetStatus, StatusTone> = {
    available: 'green',
    assigned: 'blue',
    in_repair: 'amber',
    retired: 'grey',
};

const has = <T extends string>(
    map: Record<T, unknown>,
    key: string,
): key is T => Object.prototype.hasOwnProperty.call(map, key);

/** "Available", "In repair" ... */
export function assetStatusLabel(status: string): string {
    return has(STATUS_LABELS, status) ? STATUS_LABELS[status] : status;
}

/** The pill colour of an asset status. */
export function assetStatusTone(status: string): StatusTone {
    return has(STATUS_TONES, status) ? STATUS_TONES[status] : 'grey';
}

/** "New", "Good", "Fair", "Poor", "Damaged". */
export function assetConditionLabel(condition: string): string {
    return has(CONDITION_LABELS, condition)
        ? CONDITION_LABELS[condition]
        : condition;
}

/** Condition options for a select, best first. */
export function assetConditionOptions(): { value: string; label: string }[] {
    return ASSET_CONDITIONS.map((value) => ({
        value,
        label: CONDITION_LABELS[value],
    }));
}
