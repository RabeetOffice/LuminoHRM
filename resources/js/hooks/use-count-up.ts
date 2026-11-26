import { useEffect, useRef, useState } from 'react';
import { countUpValue, prefersReducedMotion } from '@/lib/motion';

type Options = {
    /** Animation length in ms. Default 900. */
    duration?: number;
    /** Decimal places to show while counting. Default 0. */
    decimals?: number;
};

/**
 * Counts a stat figure up to `target` on mount and whenever `target` changes, starting from
 * the value currently shown. Shows the final value at once when reduced motion is on.
 * Usage: const shown = useCountUp(stats.headcount);
 */
export function useCountUp(
    target: number,
    { duration = 900, decimals = 0 }: Options = {},
): number {
    const [value, setValue] = useState(() =>
        prefersReducedMotion() ? target : 0,
    );
    const shown = useRef(value);

    useEffect(() => {
        const from = shown.current;

        if (prefersReducedMotion() || duration <= 0 || from === target) {
            shown.current = target;
            setValue(target);

            return;
        }

        let frame = 0;
        const start = performance.now();

        const tick = (now: number) => {
            const progress = (now - start) / duration;
            const next = countUpValue(from, target, progress, decimals);

            shown.current = next;
            setValue(next);

            if (progress < 1) {
                frame = requestAnimationFrame(tick);
            }
        };

        frame = requestAnimationFrame(tick);

        return () => cancelAnimationFrame(frame);
    }, [target, duration, decimals]);

    return value;
}
