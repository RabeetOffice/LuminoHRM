/**
 * Shows a time of day as the server sends it ("07:00", "18:30", or with
 * seconds) in 12-hour form: "7:00 AM", "6:30 PM". The text is read as it is,
 * never turned into a moment in time, so it cannot move with the time zone.
 * Returns '' for an empty or impossible time.
 */
export function formatClockTime(value: string | null | undefined): string {
    const match = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value ?? '');

    if (!match) {
        return '';
    }

    const hours = Number(match[1]);
    const minutes = match[2];

    if (hours > 23 || Number(minutes) > 59) {
        return '';
    }

    const suffix = hours < 12 ? 'AM' : 'PM';
    const twelve = hours % 12 === 0 ? 12 : hours % 12;

    return `${twelve}:${minutes} ${suffix}`;
}

/** "7:00 AM – 3:00 PM" for a shift's start and end. */
export function formatClockRange(start: string, end: string): string {
    return `${formatClockTime(start)} – ${formatClockTime(end)}`;
}
