import { describe, expect, it } from 'vitest';
import { formatDate } from '@/lib/format-date';

describe('formatDate', () => {
    it('formats a calendar date in each format Settings offers', () => {
        expect(formatDate('2026-03-01', 'd M Y')).toBe('01 Mar 2026');
        expect(formatDate('2026-03-01', 'd/m/Y')).toBe('01/03/2026');
        expect(formatDate('2026-03-01', 'm/d/Y')).toBe('03/01/2026');
        expect(formatDate('2026-03-01', 'Y-m-d')).toBe('2026-03-01');
        expect(formatDate('2026-03-01', 'd-m-Y')).toBe('01-03-2026');
        expect(formatDate('2026-03-01', 'M d, Y')).toBe('Mar 01, 2026');
    });

    it('does not shift the day with the time zone', () => {
        expect(formatDate('2026-12-31', 'Y-m-d')).toBe('2026-12-31');
        expect(formatDate('2026-01-01', 'Y-m-d')).toBe('2026-01-01');
    });

    it('accepts a date-time and uses its date part', () => {
        expect(formatDate('2026-03-01T00:00:00.000000Z', 'd M Y')).toBe(
            '01 Mar 2026',
        );
    });

    it('falls back to d M Y for an unknown format', () => {
        expect(formatDate('2026-03-01', 'zzz')).toBe('01 Mar 2026');
        expect(formatDate('2026-03-01', undefined)).toBe('01 Mar 2026');
    });

    it('returns an empty string for an empty or invalid date', () => {
        expect(formatDate(null, 'd M Y')).toBe('');
        expect(formatDate('', 'd M Y')).toBe('');
        expect(formatDate('not a date', 'd M Y')).toBe('');
        expect(formatDate('2026-13-45', 'd M Y')).toBe('');
    });
});
