/**
 * Whole minutes as a short duration: 500 → "8h 20m", 45 → "45m", 120 → "2h".
 * Zero, negative or missing values give '' so a table cell can show a dash.
 */
export function formatMinutes(value: number | null | undefined): string {
    if (value === null || value === undefined || !Number.isFinite(value)) {
        return '';
    }

    const total = Math.floor(value);

    if (total <= 0) {
        return '';
    }

    const hours = Math.floor(total / 60);
    const minutes = total % 60;

    if (hours === 0) {
        return `${minutes}m`;
    }

    return minutes === 0 ? `${hours}h` : `${hours}h ${minutes}m`;
}
