import { describe, expect, it } from 'vitest';
import { formatMinutes } from '@/lib/minutes';

describe('formatMinutes', () => {
    it('shows hours and minutes', () => {
        expect(formatMinutes(500)).toBe('8h 20m');
        expect(formatMinutes(61)).toBe('1h 1m');
    });

    it('drops the empty part', () => {
        expect(formatMinutes(45)).toBe('45m');
        expect(formatMinutes(120)).toBe('2h');
    });

    it('gives an empty string for nothing to show', () => {
        expect(formatMinutes(0)).toBe('');
        expect(formatMinutes(-5)).toBe('');
        expect(formatMinutes(null)).toBe('');
        expect(formatMinutes(undefined)).toBe('');
        expect(formatMinutes(Number.NaN)).toBe('');
    });
});
