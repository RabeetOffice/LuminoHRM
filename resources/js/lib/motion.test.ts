import { describe, expect, it } from 'vitest';
import {
    countUpValue,
    easeOutCubic,
    revealStyle,
    STAGGER_CAP,
    STAGGER_STEP_MS,
    staggerDelay,
} from './motion';

describe('staggerDelay', () => {
    it('steps by the stagger interval', () => {
        expect(staggerDelay(0)).toBe(0);
        expect(staggerDelay(3)).toBe(3 * STAGGER_STEP_MS);
    });

    it('caps long lists and ignores negative or fractional indexes', () => {
        expect(staggerDelay(50)).toBe(STAGGER_CAP * STAGGER_STEP_MS);
        expect(staggerDelay(-2)).toBe(0);
        expect(staggerDelay(2.9)).toBe(2 * STAGGER_STEP_MS);
    });
});

describe('revealStyle', () => {
    it('sets the stagger index, capped', () => {
        expect(revealStyle(4)).toEqual({ '--stagger-index': 4 });
        expect(revealStyle(99)).toEqual({ '--stagger-index': STAGGER_CAP });
    });
});

describe('easeOutCubic', () => {
    it('runs from 0 to 1 and clamps outside that range', () => {
        expect(easeOutCubic(0)).toBe(0);
        expect(easeOutCubic(1)).toBe(1);
        expect(easeOutCubic(-1)).toBe(0);
        expect(easeOutCubic(2)).toBe(1);
        expect(easeOutCubic(0.5)).toBeGreaterThan(0.5);
    });
});

describe('countUpValue', () => {
    it('starts at from and lands exactly on to', () => {
        expect(countUpValue(0, 120, 0)).toBe(0);
        expect(countUpValue(0, 120, 1)).toBe(120);
        expect(countUpValue(0, 120, 1.4)).toBe(120);
    });

    it('rounds to the requested decimals and counts down too', () => {
        expect(countUpValue(0, 10, 0.5, 1)).toBe(8.8);
        expect(countUpValue(100, 0, 0.5)).toBe(13);
    });
});
