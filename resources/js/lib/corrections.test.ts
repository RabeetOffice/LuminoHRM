import { describe, expect, it } from 'vitest';
import { correctionState, timePair } from './corrections';

describe('correctionState', () => {
    it('names the open step', () => {
        expect(correctionState('pending', 'manager')).toEqual({
            label: 'Waiting for manager',
            tone: 'amber',
        });
        expect(correctionState('pending', 'hr').label).toBe('Waiting for HR');
        expect(correctionState('manager_approved', 'hr').label).toBe(
            'Waiting for HR',
        );
    });

    it('names the outcome once decided', () => {
        expect(correctionState('approved', null)).toEqual({
            label: 'Approved',
            tone: 'green',
        });
        expect(correctionState('rejected', null).tone).toBe('red');
        expect(correctionState('cancelled', null).label).toBe('Cancelled');
    });
});

describe('timePair', () => {
    it('shows both times in 12-hour form', () => {
        expect(timePair('09:00', '18:30')).toBe('9:00 AM – 6:30 PM');
    });

    it('marks a missing side and a day with no record', () => {
        expect(timePair('09:40', null)).toBe('9:40 AM – –');
        expect(timePair(null, '18:00')).toBe('– – 6:00 PM');
        expect(timePair(null, null)).toBe('No record');
    });

    it('marks a clock-out after midnight', () => {
        expect(timePair('18:00', '02:00')).toBe('6:00 PM – 2:00 AM (+1)');
    });
});
