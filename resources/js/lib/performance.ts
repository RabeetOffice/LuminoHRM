/**
 * Performance helpers shared by the review screens: the words people read for a review's and a cycle's state, the pill
 * colour of each, and how a score in hundredths is shown. The values are the ones in config('hrm.performance'); an unknown
 * value falls back to the raw text with a grey tone, so a new state never breaks a screen.
 */
import type { StatusTone } from '@/components/hrm/status-pill';

export type ReviewState =
    | 'self_review'
    | 'manager_review'
    | 'ready_to_close'
    | 'closed'
    | 'skipped';

export type CycleState = 'open' | 'closed';

export const REVIEW_STATES: ReviewState[] = [
    'self_review',
    'manager_review',
    'ready_to_close',
    'closed',
    'skipped',
];

export const CYCLE_STATES: CycleState[] = ['open', 'closed'];

const REVIEW_LABELS: Record<ReviewState, string> = {
    self_review: 'Self-review',
    manager_review: 'With manager',
    ready_to_close: 'Ready to close',
    closed: 'Closed',
    skipped: 'Skipped',
};

// Waiting on the person is blue, waiting on the manager amber, waiting on HR violet, done green, set aside grey.
const REVIEW_TONES: Record<ReviewState, StatusTone> = {
    self_review: 'blue',
    manager_review: 'amber',
    ready_to_close: 'violet',
    closed: 'green',
    skipped: 'grey',
};

const CYCLE_LABELS: Record<CycleState, string> = {
    open: 'Open',
    closed: 'Closed',
};

const CYCLE_TONES: Record<CycleState, StatusTone> = {
    open: 'green',
    closed: 'grey',
};

const has = <T extends string>(
    map: Record<T, unknown>,
    key: string,
): key is T => Object.prototype.hasOwnProperty.call(map, key);

/** "Self-review", "With manager", "Ready to close", "Closed", "Skipped". */
export function reviewStateLabel(state: string): string {
    return has(REVIEW_LABELS, state) ? REVIEW_LABELS[state] : state;
}

/** The pill colour of a review state. */
export function reviewStateTone(state: string): StatusTone {
    return has(REVIEW_TONES, state) ? REVIEW_TONES[state] : 'grey';
}

/** "Open" or "Closed". */
export function cycleStateLabel(state: string): string {
    return has(CYCLE_LABELS, state) ? CYCLE_LABELS[state] : state;
}

/** The pill colour of a cycle state. */
export function cycleStateTone(state: string): StatusTone {
    return has(CYCLE_TONES, state) ? CYCLE_TONES[state] : 'grey';
}

/**
 * A score in hundredths as people read it: 412 is "4.12", 450 is "4.50", 100 is "1.00". Whole-number arithmetic only (a score
 * is never a float). Nothing yet (null) shows "–".
 */
export function formatScore(hundredths: number | null | undefined): string {
    if (hundredths === null || hundredths === undefined) {
        return '–';
    }

    const whole = Math.trunc(hundredths / 100);
    const cents = hundredths - whole * 100;

    return `${whole}.${String(cents).padStart(2, '0')}`;
}
