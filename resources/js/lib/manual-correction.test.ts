import { describe, expect, it } from 'vitest';
import {
    CALCULATE,
    correctionPayload,
    initialCorrectionStatus,
} from './manual-correction';

const allowed = ['present', 'late', 'half_day', 'absent', 'holiday', 'weekend'];

describe('initialCorrectionStatus', () => {
    it('starts on the current status for a manual override', () => {
        expect(
            initialCorrectionStatus(
                { status: 'absent', is_manual: true, status_overridden: true },
                allowed,
            ),
        ).toBe('absent');
    });

    it('works the status out from the times otherwise', () => {
        expect(
            initialCorrectionStatus(
                { status: 'late', is_manual: true, status_overridden: false },
                allowed,
            ),
        ).toBe(CALCULATE);
        expect(
            initialCorrectionStatus(
                { status: 'late', is_manual: false },
                allowed,
            ),
        ).toBe(CALCULATE);
        expect(
            initialCorrectionStatus(
                { status: null, is_manual: true, status_overridden: true },
                allowed,
            ),
        ).toBe(CALCULATE);
        expect(
            initialCorrectionStatus(
                {
                    status: 'on_leave',
                    is_manual: true,
                    status_overridden: true,
                },
                allowed,
            ),
        ).toBe(CALCULATE);
    });
});

describe('correctionPayload', () => {
    it('sends empty times as null and "calculate" as no override', () => {
        expect(
            correctionPayload({
                clock_in: '09:00',
                clock_out: '',
                status: CALCULATE,
                reason: 'Forgot to punch',
            }),
        ).toEqual({
            clock_in: '09:00',
            clock_out: null,
            status: null,
            reason: 'Forgot to punch',
        });
    });

    it('sends no times with absent', () => {
        expect(
            correctionPayload({
                clock_in: '09:00',
                clock_out: '18:00',
                status: 'absent',
                reason: 'Was not in',
            }),
        ).toEqual({
            clock_in: null,
            clock_out: null,
            status: 'absent',
            reason: 'Was not in',
        });
    });
});
