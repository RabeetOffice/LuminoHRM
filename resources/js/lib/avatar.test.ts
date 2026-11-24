import { describe, expect, it } from 'vitest';
import { AVATAR_TINTS, initials, tintFor } from '@/lib/avatar';

describe('initials', () => {
    it('takes the first letter of the first and last name', () => {
        expect(initials('Ali Khan')).toBe('AK');
        expect(initials('ayesha bibi malik')).toBe('AM');
    });

    it('gives one letter for a single name', () => {
        expect(initials('Madonna')).toBe('M');
        expect(initials('x')).toBe('X');
    });

    it('ignores extra spaces, tabs and edges', () => {
        expect(initials('   Ali    Khan  ')).toBe('AK');
        expect(initials('\tAli\nKhan')).toBe('AK');
    });

    it('skips characters that are not letters', () => {
        expect(initials('(Ali) 123 Khan.')).toBe('AK');
        expect(initials("O'Brien")).toBe('O');
        expect(initials('- Ali -')).toBe('A');
        expect(initials('3 4 5')).toBe('');
    });

    it('handles non-Latin letters', () => {
        expect(initials('علی خان')).toBe('عخ');
        expect(initials('élodie Çelik')).toBe('ÉÇ');
    });

    it('returns an empty string for nothing', () => {
        expect(initials('')).toBe('');
        expect(initials('   ')).toBe('');
    });
});

describe('tintFor', () => {
    it('always gives the same colour for the same name, ignoring case and outer spaces', () => {
        expect(tintFor('Ali Khan')).toBe(tintFor('Ali Khan'));
        expect(tintFor('Ali Khan')).toBe(tintFor('  ali khan '));
    });

    it('stays inside the palette for any input', () => {
        for (const name of [
            '',
            'A',
            'Ali Khan',
            'علی خان',
            '😀',
            'x'.repeat(500),
        ]) {
            expect(AVATAR_TINTS).toContain(tintFor(name));
        }
    });

    it('uses more than one colour across different names', () => {
        const used = new Set(
            [
                'Ali Khan',
                'Sara Ahmed',
                'Bilal Raza',
                'Hina Malik',
                'Omar Sheikh',
                'Zara Iqbal',
                'Usman Ali',
                'Noor Fatima',
            ].map(tintFor),
        );

        expect(used.size).toBeGreaterThan(2);
    });
});
