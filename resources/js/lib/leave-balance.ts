/**
 * Shaping helpers for Leave > Balances (the grid cell and the edit dialog).
 * Every figure is a multiple of 0.5 days; sums are done in whole half days so
 * no float drift can show (the same rule as the server's LeaveBalances).
 */

/** One balance cell as the server sends it (BalanceLine::toArray + can_edit). */
export type BalanceCell = {
    type_id: number;
    type_name: string;
    colour: string;
    year: number;
    is_paid: boolean;
    /** An unpaid type: never limited; remaining / available / entitlement are null. */
    unlimited: boolean;
    allocated: number;
    carried: number;
    adjustment: number;
    opening_used: number;
    used: number;
    pending: number;
    remaining: number | null;
    available: number | null;
    entitlement: number | null;
    overdrawn: boolean;
    /** No balance row yet: allocated is the expected allocation (nothing stored). */
    projected: boolean;
    label: string;
    can_edit: boolean;
};

/** A day amount with at most one decimal: 14 → "14", 9.5 → "9.5", -1.5 → "-1.5". */
export function formatDays(value: number): string {
    const halves = Math.round(value * 2);

    return halves % 2 === 0 ? String(halves / 2) : (halves / 2).toFixed(1);
}

/** "1 day", "2.5 days", "0 days". */
export function daysLabel(value: number): string {
    const shown = formatDays(value);

    return shown === '1' || shown === '-1' ? `${shown} day` : `${shown} days`;
}

/**
 * A typed amount as whole half days, or null when it is not a number or not
 * a whole or half day ("" → null, "1.5" → 3, "1.25" → null).
 */
export function parseHalves(value: string): number | null {
    const trimmed = value.trim();

    if (trimmed === '' || !/^-?\d+(\.\d+)?$/.test(trimmed)) {
        return null;
    }

    const doubled = Number(trimmed) * 2;

    return Math.abs(doubled - Math.round(doubled)) > 1e-9
        ? null
        : Math.round(doubled);
}

/**
 * The remaining days after an edit, or null while a typed figure is not valid:
 * allocated + carried + adjustment − opening used − used.
 */
export function remainingAfter(
    cell: Pick<BalanceCell, 'carried' | 'used'>,
    typed: { allocated: string; adjustment: string; opening_used: string },
): number | null {
    const allocated = parseHalves(typed.allocated);
    const adjustment = parseHalves(typed.adjustment);
    const openingUsed = parseHalves(typed.opening_used);

    if (allocated === null || adjustment === null || openingUsed === null) {
        return null;
    }

    const halves =
        allocated +
        Math.round(cell.carried * 2) +
        adjustment -
        openingUsed -
        Math.round(cell.used * 2);

    return halves / 2;
}

/**
 * The three editable figures as the breakdown shows them while the form is
 * edited: each typed value when it is a valid whole or half day, else the
 * stored figure (so the breakdown never shows a half-typed number).
 */
export function editedFigures(
    cell: Pick<BalanceCell, 'allocated' | 'adjustment' | 'opening_used'>,
    typed: { allocated: string; adjustment: string; opening_used: string },
): { allocated: number; adjustment: number; opening_used: number } {
    const pick = (value: string, stored: number): number => {
        const halves = parseHalves(value);

        return halves === null ? stored : halves / 2;
    };

    return {
        allocated: pick(typed.allocated, cell.allocated),
        adjustment: pick(typed.adjustment, cell.adjustment),
        opening_used: pick(typed.opening_used, cell.opening_used),
    };
}

/** What a grid cell shows: "9.5 / 14" for a paid type, "3 used" for an unpaid one. */
export function cellText(cell: BalanceCell): string {
    if (cell.unlimited || cell.remaining === null) {
        return `${formatDays(cell.used)} used`;
    }

    return `${formatDays(cell.remaining)} / ${formatDays(cell.entitlement ?? cell.allocated)}`;
}
