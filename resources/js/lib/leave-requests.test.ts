import { describe, expect, it } from 'vitest';
import {
    canSendDecision,
    cancelFromAllowed,
    cancelsPastDays,
    dayHalfLabel,
    filterPeople,
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

describe('cancelsPastDays', () => {
    it('is true only for a day before today', () => {
        expect(cancelsPastDays('2026-11-24', '2026-11-25')).toBe(true);
        expect(cancelsPastDays('2026-11-25', '2026-11-25')).toBe(false);
        expect(cancelsPastDays('2026-11-26', '2026-11-25')).toBe(false);
        expect(cancelsPastDays('', '2026-11-25')).toBe(false);
    });
});

describe('filterPeople', () => {
    const people = [
        { id: 1, name: 'Sara Khan', code: 'EMP-001' },
        { id: 2, name: 'Ali Raza', code: 'EMP-002' },
        { id: 3, name: 'Sarah Ali', code: 'EMP-010' },
    ];

    it('keeps everyone for an empty search', () => {
        expect(filterPeople(people, '  ')).toHaveLength(3);
    });

    it('matches every word in the name or the ID, ignoring case', () => {
        expect(filterPeople(people, 'sar').map((p) => p.id)).toEqual([1, 3]);
        expect(filterPeople(people, 'ali emp-01').map((p) => p.id)).toEqual([
            3,
        ]);
        expect(filterPeople(people, '002').map((p) => p.id)).toEqual([2]);
        expect(filterPeople(people, 'nobody')).toEqual([]);
    });
});

describe('dayHalfLabel', () => {
    it('words each half', () => {
        expect(dayHalfLabel('full')).toBe('Full day');
        expect(dayHalfLabel('am')).toBe('First half');
        expect(dayHalfLabel('pm')).toBe('Second half');
    });
});
