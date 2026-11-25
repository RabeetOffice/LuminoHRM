import { describe, expect, it } from 'vitest';
import {
    changedSides,
    correctionState,
    nextDayOut,
    requestedSides,
    timePair,
} from './corrections';

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

describe('requestedSides', () => {
    it('marks the changed side and keeps the other as recorded', () => {
        expect(
            requestedSides(
                { clock_in: '09:00', clock_out: null },
                { clock_in: '09:40', clock_out: '18:10' },
            ),
        ).toEqual([
            { label: 'In', text: '9:00 AM', changed: true },
            { label: 'Out', text: 'keeps recorded', changed: false },
        ]);
    });

    it('marks a requested clock-out after midnight against the kept clock-in', () => {
        expect(
            requestedSides(
                { clock_in: null, clock_out: '02:00' },
                { clock_in: '18:00', clock_out: null },
            )[1].text,
        ).toBe('2:00 AM (+1)');
    });
});

describe('changedSides', () => {
    it('sends only the sides the employee changed', () => {
        expect(
            changedSides(
                { clock_in: '09:00', clock_out: '18:10' },
                { clock_in: '09:40', clock_out: '18:10' },
            ),
        ).toEqual({ clock_in: '09:00', clock_out: '' });
        expect(
            changedSides(
                { clock_in: '', clock_out: '18:00' },
                { clock_in: null, clock_out: null },
            ),
        ).toEqual({ clock_in: '', clock_out: '18:00' });
    });
});

describe('nextDayOut', () => {
    it('is true only for a clock-out before the clock-in', () => {
        expect(nextDayOut('18:00', '02:00')).toBe(true);
        expect(nextDayOut('09:00', '18:00')).toBe(false);
        expect(nextDayOut('', '02:00')).toBe(false);
    });
});
