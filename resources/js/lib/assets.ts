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

/** A person as the asset screens carry them: the name and the employee code. */
export type AssetPerson = { id: number; code: string; name: string };

/** "Sara Ahmed (EMP-0007)". */
export function personLabel(person: Pick<AssetPerson, 'code' | 'name'>) {
    return `${person.name} (${person.code})`;
}

/**
 * People whose name or code contains the typed text (case-insensitive, extra spaces ignored); everyone for empty text.
 * The server's own search (the "employee-options" route) is used instead when the list was cut short.
 */
export function filterPeople<T extends Pick<AssetPerson, 'code' | 'name'>>(
    people: T[],
    term: string,
): T[] {
    const needle = term.trim().replace(/\s+/g, ' ').toLowerCase();

    if (needle === '') {
        return people;
    }

    return people.filter(
        (person) =>
            person.name.toLowerCase().includes(needle) ||
            person.code.toLowerCase().includes(needle),
    );
}

/**
 * What a returned asset can become: available or in repair for HR; retired too for an Admin (the server refuses a
 * retirement from anyone else, this only keeps the choice off the screen).
 */
export function returnStatusOptions(canRetire: boolean): {
    value: AssetStatus;
    label: string;
}[] {
    const next: AssetStatus[] = canRetire
        ? ['available', 'in_repair', 'retired']
        : ['available', 'in_repair'];

    return next.map((value) => ({ value, label: STATUS_LABELS[value] }));
}

/** The status choices for the Change status dialog, from the server's list, in the order given. */
export function statusChoices(
    targets: string[],
): { value: string; label: string }[] {
    return targets.map((value) => ({
        value,
        label: assetStatusLabel(value),
    }));
}

/** A cost in paisa as the text of the whole-rupees field: 15050000 -> "150500"; empty for no cost. */
export function costFieldText(paisa: number | null | undefined): string {
    if (paisa === null || paisa === undefined) {
        return '';
    }

    return paisa % 100 === 0 ? String(paisa / 100) : (paisa / 100).toFixed(2);
}

/** The first server error found under any of `keys` (the ones a dialog has no field for), or undefined. */
export function firstError(
    errors: Record<string, string | undefined>,
    keys: string[],
): string | undefined {
    for (const key of keys) {
        const message = errors[key];

        if (message) {
            return message;
        }
    }

    return undefined;
}
