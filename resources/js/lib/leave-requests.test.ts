import { describe, expect, it } from 'vitest';
import {
    canSendDecision,
    cancelFromAllowed,
    dayHalfLabel,
    requestPill,
} from './leave-requests';

describe('requestPill', () => {
    it('names the manager while waiting at the manager step', () => {
        expect(requestPill('pending', 'manager', 'Ahmed', false)).toEqual({
            tone: 'amber',
            label: 'Pending',
            note: 'Waiting for Ahmed',
        });
    });

    it('says HR at the final step, also after a manager approval', () => {
        expect(requestPill('pending', 'final', null, false).note).toBe(
            'Waiting for HR',
        );
        expect(requestPill('manager_approved', 'final', null, false)).toEqual({
            tone: 'amber',
            label: 'Pending',
            note: 'Waiting for HR',
        });
    });

    it('marks approved, partly cancelled, rejected and cancelled', () => {
        expect(requestPill('approved', null, null, false)).toEqual({
            tone: 'green',
            label: 'Approved',
            note: null,
        });
        expect(requestPill('approved', null, null, true).note).toBe(
            'Partly cancelled',
        );
        expect(requestPill('rejected', null, null, false).tone).toBe('red');
        expect(requestPill('cancelled', null, null, false)).toEqual({
            tone: 'grey',
            label: 'Cancelled',
            note: null,
        });
    });
});

describe('canSendDecision', () => {
    it('always allows an approval', () => {
        expect(canSendDecision('approve', '')).toBe(true);
    });

    it('needs a reason of at least 5 characters to reject', () => {
        expect(canSendDecision('reject', '')).toBe(false);
        expect(canSendDecision('reject', '  no  ')).toBe(false);
        expect(canSendDecision('reject', 'Busy week')).toBe(true);
    });
});

describe('cancelFromAllowed', () => {
    const range = { min: '2026-11-23', max: '2026-11-27' };

    it('accepts the ends and days between', () => {
        expect(cancelFromAllowed('2026-11-23', range)).toBe(true);
        expect(cancelFromAllowed('2026-11-25', range)).toBe(true);
        expect(cancelFromAllowed('2026-11-27', range)).toBe(true);
    });

    it('refuses days outside and malformed text', () => {
        expect(cancelFromAllowed('2026-11-22', range)).toBe(false);
        expect(cancelFromAllowed('2026-11-28', range)).toBe(false);
        expect(cancelFromAllowed('', range)).toBe(false);
        expect(cancelFromAllowed('25/11/2026', range)).toBe(false);
    });
});

describe('dayHalfLabel', () => {
    it('words each half', () => {
        expect(dayHalfLabel('full')).toBe('Full day');
        expect(dayHalfLabel('am')).toBe('First half');
        expect(dayHalfLabel('pm')).toBe('Second half');
    });
});
