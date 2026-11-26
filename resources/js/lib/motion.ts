import type { CSSProperties } from 'react';

/**
 * Small motion helpers shared by the shell. Durations and curves live as CSS variables in
 * app.css (--duration-*, --ease-*); this file only holds what JavaScript needs.
 */

/** Matches --stagger-step in app.css. */
export const STAGGER_STEP_MS = 35;

/** Reveals past this index start together, so a long list never waits on its tail. */
export const STAGGER_CAP = 10;

/** True when the person asked the OS for less motion. Safe to call during SSR. */
export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** Delay in ms for the item at `index` in a staggered reveal. */
export function staggerDelay(
    index: number,
    step = STAGGER_STEP_MS,
    cap = STAGGER_CAP,
): number {
    return Math.min(Math.max(0, Math.floor(index)), cap) * step;
}

/**
 * Inline style for an element using the `reveal` class: sets its place in the stagger.
 * Usage: <div className="reveal" style={revealStyle(i)} />
 */
export function revealStyle(index: number): CSSProperties {
    return {
        '--stagger-index': Math.min(
            Math.max(0, Math.floor(index)),
            STAGGER_CAP,
        ),
    } as CSSProperties;
}

/** Ease-out cubic: fast start, gentle landing. `t` is clamped to 0..1. */
export function easeOutCubic(t: number): number {
    const x = Math.min(Math.max(t, 0), 1);

    return 1 - (1 - x) ** 3;
}

/**
 * The value a count-up shows at `progress` (0..1) on its way from `from` to `to`,
 * rounded to `decimals` places. Lands exactly on `to` at the end.
 */
export function countUpValue(
    from: number,
    to: number,
    progress: number,
    decimals = 0,
): number {
    if (progress >= 1) {
        return to;
    }

    const factor = 10 ** decimals;
    const raw = from + (to - from) * easeOutCubic(progress);

    return Math.round(raw * factor) / factor;
}
