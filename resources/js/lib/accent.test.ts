import { describe, expect, it } from 'vitest';
import {
    accentCss,
    contrastRatio,
    inkForDark,
    inkForLight,
    isHexColour,
    onAccent,
} from '@/lib/accent';

describe('contrastRatio', () => {
    it('is 21 for black on white and 1 for identical colours', () => {
        expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 1);
        expect(contrastRatio('#336699', '#336699')).toBeCloseTo(1, 5);
    });
});

describe('inkForLight', () => {
    it('darkens the default green until it reads on white at 4.5:1', () => {
        const ink = inkForLight('#10b77f');

        expect(contrastRatio(ink, '#ffffff')).toBeGreaterThanOrEqual(4.5);
        expect(ink).not.toBe('#10b77f');
    });

    it('keeps a colour that already passes', () => {
        expect(inkForLight('#1d4ed8')).toBe('#1d4ed8');
    });

    it.each(['#ffff00', '#ffffff', '#00ffff', '#f5c518', '#10b77f'])(
        'reaches 4.5:1 for %s',
        (accent) => {
            expect(
                contrastRatio(inkForLight(accent), '#ffffff'),
            ).toBeGreaterThanOrEqual(4.5);
        },
    );
});

describe('inkForDark', () => {
    it('lightens a dark accent until it reads on a near-black surface', () => {
        const ink = inkForDark('#1e3a8a');

        expect(contrastRatio(ink, '#0a0a0a')).toBeGreaterThanOrEqual(4.5);
    });

    it('keeps a colour that already passes', () => {
        expect(inkForDark('#10b77f')).toBe('#10b77f');
    });
});

describe('onAccent', () => {
    it('uses white text on mid and dark accents and dark text on pale ones', () => {
        expect(onAccent('#10b77f')).toBe('#ffffff');
        expect(onAccent('#1e3a8a')).toBe('#ffffff');
        expect(onAccent('#ffff00')).toBe('#111111');
    });
});

describe('isHexColour', () => {
    it('accepts only a 6-digit hex colour', () => {
        expect(isHexColour('#10b77f')).toBe(true);
        expect(isHexColour('#10B77F')).toBe(true);
        expect(isHexColour('#fff')).toBe(false);
        expect(isHexColour('red;}')).toBe(false);
        expect(isHexColour('#10b77f;')).toBe(false);
    });
});

describe('accentCss', () => {
    it('emits the shell variables for light and dark mode', () => {
        const css = accentCss('#3366ff');

        expect(css).toContain('--primary: #3366ff');
        expect(css).toContain('--sidebar-primary: #3366ff');
        expect(css).toContain('--ring: #3366ff');
        expect(css).toContain('--brand-ink:');
        expect(css).toContain('.dark');
    });

    it('refuses anything that is not a hex colour', () => {
        expect(accentCss('red;}')).toBe('');
        expect(accentCss('#fff')).toBe('');
    });
});
