import { describe, expect, it } from 'vitest';
import {
    CYCLE_STATES,
    REVIEW_STATES,
    cycleStateLabel,
    cycleProgressPercent,
    cycleStateTone,
    cycleTabs,
    firstError,
    formatScore,
    reviewStateLabel,
    reviewStateTone,
} from './performance';

describe('reviewStateLabel', () => {
    it('reads each state in plain words', () => {
        expect(reviewStateLabel('self_review')).toBe('Self-review');
        expect(reviewStateLabel('manager_review')).toBe('With manager');
        expect(reviewStateLabel('ready_to_close')).toBe('Ready to close');
        expect(reviewStateLabel('closed')).toBe('Closed');
        expect(reviewStateLabel('skipped')).toBe('Skipped');
    });

    it('has a label for every state and falls back to the raw text', () => {
        for (const state of REVIEW_STATES) {
            expect(reviewStateLabel(state)).not.toBe(state);
        }

        expect(reviewStateLabel('something_new')).toBe('something_new');
        expect(reviewStateLabel('toString')).toBe('toString');
    });
});

describe('reviewStateTone', () => {
    it('colours each state', () => {
        expect(reviewStateTone('self_review')).toBe('blue');
        expect(reviewStateTone('manager_review')).toBe('amber');
        expect(reviewStateTone('ready_to_close')).toBe('violet');
        expect(reviewStateTone('closed')).toBe('green');
        expect(reviewStateTone('skipped')).toBe('grey');
    });

    it('is grey for an unknown state', () => {
        expect(reviewStateTone('something_new')).toBe('grey');
    });
});

describe('cycle states', () => {
    it('labels and colours both states', () => {
        expect(CYCLE_STATES).toEqual(['open', 'closed']);
        expect(cycleStateLabel('open')).toBe('Open');
        expect(cycleStateLabel('closed')).toBe('Closed');
        expect(cycleStateTone('open')).toBe('green');
        expect(cycleStateTone('closed')).toBe('grey');
        expect(cycleStateLabel('x')).toBe('x');
        expect(cycleStateTone('x')).toBe('grey');
    });
});

describe('formatScore', () => {
    it('shows hundredths as two decimals', () => {
        expect(formatScore(412)).toBe('4.12');
        expect(formatScore(450)).toBe('4.50');
        expect(formatScore(100)).toBe('1.00');
        expect(formatScore(500)).toBe('5.00');
        expect(formatScore(305)).toBe('3.05');
        expect(formatScore(99)).toBe('0.99');
        expect(formatScore(0)).toBe('0.00');
    });

    it('shows a dash when there is no score yet', () => {
        expect(formatScore(null)).toBe('–');
        expect(formatScore(undefined)).toBe('–');
    });
});

describe('cycleProgressPercent', () => {
    it('is the closed share of what needs closing, skipped ones set aside', () => {
        expect(
            cycleProgressPercent({
                self_review: 2,
                manager_review: 1,
                ready_to_close: 1,
                closed: 4,
                skipped: 5,
            }),
        ).toBe(50);
        expect(cycleProgressPercent({ closed: 1, self_review: 2 })).toBe(33);
        expect(cycleProgressPercent({ closed: 3 })).toBe(100);
    });

    it('is 0 when there is nothing to close', () => {
        expect(cycleProgressPercent({})).toBe(0);
        expect(cycleProgressPercent({ skipped: 3 })).toBe(0);
    });
});

describe('cycleTabs', () => {
    it('lists All and each state with its count', () => {
        const tabs = cycleTabs({ self_review: 2, closed: 1, skipped: 1 });

        expect(tabs.map((t) => t.key)).toEqual([
            '',
            'self_review',
            'manager_review',
            'ready_to_close',
            'closed',
            'skipped',
        ]);
        expect(tabs[0]).toEqual({ key: '', label: 'All', count: 4 });
        expect(tabs[2]).toEqual({
            key: 'manager_review',
            label: 'With manager',
            count: 0,
        });
    });
});

describe('firstError', () => {
    it('gives the first message, or nothing', () => {
        expect(firstError({ cycle: '2 reviews are not finished yet.' })).toBe(
            '2 reviews are not finished yet.',
        );
        expect(firstError({ a: undefined, b: 'Second' })).toBe('Second');
        expect(firstError({})).toBe('');
    });
});
