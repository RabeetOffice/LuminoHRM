import { describe, expect, it } from 'vitest';
import { firstDateIn } from './holidays';

describe('firstDateIn', () => {
    const today = new Date(2026, 10, 24);

    it('starts empty when viewing this year', () => {
        expect(firstDateIn(2026, today)).toBe('');
    });

    it('starts on 1 January of another year being viewed', () => {
        expect(firstDateIn(2027, today)).toBe('2027-01-01');
        expect(firstDateIn(2025, today)).toBe('2025-01-01');
    });
});
