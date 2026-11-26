import { describe, expect, it } from 'vitest';
import {
    generalErrors,
    ratingsPayload,
    ratingText,
    reviewColumns,
} from '@/lib/review-view';
import type { ReviewCan } from '@/lib/review-view';

const none: ReviewCan = {
    save_self: false,
    submit_self: false,
    save_manager: false,
    submit_manager: false,
    send_back: false,
    close: false,
    reopen: false,
    skip: false,
    restore: false,
    waive: false,
    change_reviewer: false,
};

const base = {
    selfEnabled: true,
    hasSelfData: true,
    hasManagerData: true,
};

describe('reviewColumns', () => {
    it('gives the employee an editable self column and no manager column in self-review', () => {
        expect(
            reviewColumns({
                ...base,
                role: 'reviewee',
                state: 'self_review',
                can: { ...none, save_self: true, submit_self: true },
                hasManagerData: false,
            }),
        ).toEqual({ self: 'edit', manager: 'hidden' });
    });

    it('keeps the employee read-only and without a manager column before close', () => {
        for (const state of ['manager_review', 'ready_to_close']) {
            expect(
                reviewColumns({
                    ...base,
                    role: 'reviewee',
                    state,
                    can: none,
                    hasManagerData: false,
                }),
            ).toEqual({ self: 'read', manager: 'hidden' });
        }
    });

    it('shows both sides read-only once closed', () => {
        expect(
            reviewColumns({
                ...base,
                role: 'reviewee',
                state: 'closed',
                can: none,
            }),
        ).toEqual({ self: 'read', manager: 'read' });
    });

    it('shows the reviewer no columns while the employee is rating', () => {
        expect(
            reviewColumns({
                ...base,
                role: 'reviewer',
                state: 'self_review',
                can: none,
                hasSelfData: false,
                hasManagerData: false,
            }),
        ).toEqual({ self: 'hidden', manager: 'hidden' });
    });

    it('gives the reviewer the self side to read and the manager side to edit', () => {
        expect(
            reviewColumns({
                ...base,
                role: 'reviewer',
                state: 'manager_review',
                can: { ...none, save_manager: true, submit_manager: true },
            }),
        ).toEqual({ self: 'read', manager: 'edit' });
        expect(
            reviewColumns({
                ...base,
                role: 'reviewer',
                state: 'ready_to_close',
                can: none,
            }),
        ).toEqual({ self: 'read', manager: 'read' });
    });

    it('shows HR both sides read-only, even when the cycle has no self-reviews', () => {
        expect(
            reviewColumns({
                ...base,
                role: 'hr',
                state: 'ready_to_close',
                can: none,
            }),
        ).toEqual({ self: 'read', manager: 'read' });
        expect(
            reviewColumns({
                ...base,
                selfEnabled: false,
                role: 'hr',
                state: 'manager_review',
                can: none,
            }),
        ).toEqual({ self: 'read', manager: 'read' });
    });

    it('hides the self column of a cycle without self-reviews for everyone else', () => {
        expect(
            reviewColumns({
                ...base,
                selfEnabled: false,
                role: 'reviewer',
                state: 'manager_review',
                can: { ...none, save_manager: true },
            }),
        ).toEqual({ self: 'hidden', manager: 'edit' });
    });
});

describe('ratingText', () => {
    it('reads "n of 5" or a dash', () => {
        expect(ratingText(4)).toBe('4 of 5');
        expect(ratingText(null)).toBe('–');
        expect(ratingText(undefined)).toBe('–');
    });
});

describe('generalErrors', () => {
    it('keeps the messages no field shows', () => {
        expect(
            generalErrors({
                review: 'This review is closed and cannot be changed that way.',
                ratings: 'Rate every KPI from 1 to 5 before submitting.',
                'ratings.4.rating':
                    'A rating must be a whole number from 1 to 5.',
                manager_comment:
                    'The overall comment can be at most 2,000 characters.',
            }),
        ).toEqual([
            'This review is closed and cannot be changed that way.',
            'Rate every KPI from 1 to 5 before submitting.',
        ]);
    });
});

describe('ratingsPayload', () => {
    it('sends a blank comment as null and keeps ids as numbers', () => {
        expect(
            ratingsPayload({
                7: { rating: 4, comment: '  ' },
                8: { rating: null, comment: 'Good' },
            }),
        ).toEqual({
            7: { rating: 4, comment: null },
            8: { rating: null, comment: 'Good' },
        });
    });
});
