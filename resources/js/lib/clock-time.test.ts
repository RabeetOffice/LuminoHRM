import { describe, expect, it } from 'vitest';
import { formatClockRange, formatClockTime } from '@/lib/clock-time';

describe('formatClockTime', () => {
    it('shows times in 12-hour form', () => {
        expect(formatClockTime('07:00')).toBe('7:00 AM');
        expect(formatClockTime('09:05')).toBe('9:05 AM');
        expect(formatClockTime('18:30')).toBe('6:30 PM');
        expect(formatClockTime('23:59')).toBe('11:59 PM');
    });

    it('treats midnight and noon as 12', () => {
        expect(formatClockTime('00:00')).toBe('12:00 AM');
        expect(formatClockTime('00:15')).toBe('12:15 AM');
        expect(formatClockTime('12:00')).toBe('12:00 PM');
        expect(formatClockTime('12:45')).toBe('12:45 PM');
    });

    it('accepts seconds and ignores them', () => {
        expect(formatClockTime('07:30:00')).toBe('7:30 AM');
    });

    it('returns an empty string for empty or impossible times', () => {
        expect(formatClockTime('')).toBe('');
        expect(formatClockTime(null)).toBe('');
        expect(formatClockTime(undefined)).toBe('');
        expect(formatClockTime('24:00')).toBe('');
        expect(formatClockTime('12:60')).toBe('');
        expect(formatClockTime('7:00')).toBe('');
        expect(formatClockTime('noon')).toBe('');
    });
});

describe('formatClockRange', () => {
    it('joins start and end', () => {
        expect(formatClockRange('09:00', '18:00')).toBe('9:00 AM – 6:00 PM');
    });
});
