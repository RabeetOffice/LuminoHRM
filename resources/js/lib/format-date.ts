const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];

const DEFAULT_FORMAT = 'd M Y';

/** The formats Settings > Company offers (PHP date() letters). Anything else falls back to the default. */
const KNOWN_FORMATS = ['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'M d, Y'];

/**
 * Shows a calendar date (as the server sends it, "2026-03-01") in the company's date format. The text is
 * read as it is, never turned into a moment in time, so the day cannot move with the time zone.
 * Returns '' for an empty or impossible date.
 */
export function formatDate(
    value: string | null | undefined,
    format: string | undefined,
): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value ?? '');

    if (!match) {
        return '';
    }

    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    const check = new Date(Date.UTC(year, month - 1, day));

    if (
        check.getUTCFullYear() !== year ||
        check.getUTCMonth() !== month - 1 ||
        check.getUTCDate() !== day
    ) {
        return '';
    }

    const pattern =
        format !== undefined && KNOWN_FORMATS.includes(format)
            ? format
            : DEFAULT_FORMAT;

    return pattern.replace(/[dmMY]/g, (letter) => {
        switch (letter) {
            case 'd':
                return match[3];
            case 'm':
                return match[2];
            case 'M':
                return MONTHS[month - 1];
            default:
                return match[1];
        }
    });
}
