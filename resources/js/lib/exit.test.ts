import { describe, expect, it } from 'vitest';
import {
    errorMessages,
    exitStageText,
    exitStageTone,
    exitStatusLabel,
    exitTypeLabel,
    exitTypeTone,
    flowText,
    flowTone,
    generalErrors,
    isOpenExit,
    noticeGiven,
    noticeLabel,
    noticeTone,
    paidButtonLabel,
    settlementNetText,
    settlementStatusLabel,
    settlementStatusTone,
} from './exit';

describe('exitStatusLabel', () => {
    it('reads each status in plain words', () => {
        expect(exitStatusLabel('pending')).toBe('With manager');
        expect(exitStatusLabel('manager_approved')).toBe('With HR');
        expect(exitStatusLabel('approved')).toBe('Approved');
        expect(exitStatusLabel('rejected')).toBe('Rejected');
        expect(exitStatusLabel('withdrawn')).toBe('Withdrawn');
        expect(exitStatusLabel('cancelled')).toBe('Cancelled');
    });

    it('falls back to the raw value for an unknown status', () => {
        expect(exitStatusLabel('left')).toBe('left');
        expect(exitStatusLabel('toString')).toBe('toString');
    });
});

describe('exitStageTone', () => {
    it('colours the waiting and ended states', () => {
        expect(exitStageTone('pending')).toBe('amber');
        expect(exitStageTone('manager_approved')).toBe('violet');
        expect(exitStageTone('approved')).toBe('green');
        expect(exitStageTone('rejected')).toBe('red');
        expect(exitStageTone('withdrawn')).toBe('grey');
        expect(exitStageTone('cancelled')).toBe('grey');
        expect(exitStageTone('mystery')).toBe('grey');
    });

    it('colours the finer stage of an approved exit', () => {
        expect(exitStageTone('approved', 'Clearance')).toBe('amber');
        expect(exitStageTone('approved', 'Settlement draft')).toBe('blue');
        expect(exitStageTone('approved', 'Settlement approved')).toBe('green');
        expect(exitStageTone('approved', 'Paid')).toBe('green');
        expect(exitStageTone('approved', 'Left')).toBe('grey');
    });

    it('ignores a finer stage on an exit that is not approved', () => {
        expect(exitStageTone('pending', 'Paid')).toBe('amber');
    });
});

describe('exitStageText', () => {
    it('shows the finer stage of an approved exit and the stage otherwise', () => {
        expect(
            exitStageText({
                status: 'approved',
                stage: 'Approved',
                detail: 'Paid',
            }),
        ).toBe('Paid');
        expect(
            exitStageText({
                status: 'approved',
                stage: 'Approved',
                detail: null,
            }),
        ).toBe('Approved');
        expect(
            exitStageText({
                status: 'pending',
                stage: 'With manager',
                detail: 'Paid',
            }),
        ).toBe('With manager');
    });
});

describe('exit type', () => {
    it('labels and colours the two types', () => {
        expect(exitTypeLabel('resignation')).toBe('Resignation');
        expect(exitTypeLabel('termination')).toBe('Termination');
        expect(exitTypeLabel('other')).toBe('other');
        expect(exitTypeTone('termination')).toBe('red');
        expect(exitTypeTone('resignation')).toBe('grey');
    });
});

describe('isOpenExit', () => {
    it('is true while waiting or approved', () => {
        expect(
            ['pending', 'manager_approved', 'approved'].every(isOpenExit),
        ).toBe(true);
        expect(['rejected', 'withdrawn', 'cancelled'].some(isOpenExit)).toBe(
            false,
        );
    });
});

describe('noticeLabel', () => {
    it('says the notice given when it is enough', () => {
        expect(noticeLabel(30, 30)).toBe('30 days notice');
        expect(noticeLabel(45, 30)).toBe('45 days notice');
        expect(noticeLabel(1, 0)).toBe('1 day notice');
    });

    it('says how short it falls, in the singular for one day', () => {
        expect(noticeLabel(18, 30)).toBe(
            '12 days short of notice (30 required)',
        );
        expect(noticeLabel(29, 30)).toBe('1 day short of notice (30 required)');
    });

    it('is green when enough and amber when short', () => {
        expect(noticeTone({ short: 0 })).toBe('green');
        expect(noticeTone({ short: 12 })).toBe('amber');
    });
});

describe('flow steps', () => {
    it('has a word and a colour for every state', () => {
        const states = [
            'done',
            'current',
            'skipped',
            'pending',
            'rejected',
            'withdrawn',
            'cancelled',
        ];

        for (const state of states) {
            expect(flowText(state)).not.toBe(state);
        }

        expect(flowTone('done')).toBe('green');
        expect(flowTone('current')).toBe('amber');
        expect(flowTone('rejected')).toBe('red');
        expect(flowTone('nope')).toBe('grey');
        expect(flowText('nope')).toBe('nope');
    });
});

describe('settlement text', () => {
    it('formats a positive net as money', () => {
        expect(settlementNetText(4984500)).toBe('Rs 49,845');
        expect(settlementNetText(0)).toBe('Rs 0');
    });

    it('says what is owed when the net is below zero', () => {
        expect(settlementNetText(-420000)).toBe('Owed by employee: Rs 4,200');
    });

    it('offers Mark settled when nothing is paid out', () => {
        expect(paidButtonLabel(4984500)).toBe('Mark paid');
        expect(paidButtonLabel(0)).toBe('Mark settled');
        expect(paidButtonLabel(-100)).toBe('Mark settled');
    });

    it('labels and colours each status', () => {
        expect(settlementStatusLabel('draft')).toBe('Draft');
        expect(settlementStatusLabel('approved')).toBe('Approved');
        expect(settlementStatusLabel('paid')).toBe('Paid');
        expect(settlementStatusLabel('x')).toBe('x');
        expect(settlementStatusTone('draft')).toBe('grey');
        expect(settlementStatusTone('approved')).toBe('green');
        expect(settlementStatusTone('paid')).toBe('blue');
    });
});

describe('errorMessages', () => {
    it('lists every message whatever its key, skipping empty ones', () => {
        expect(
            errorMessages({
                exit: 'Not now.',
                reason: 'Enter a reason.',
                note: undefined,
                x: '',
            }),
        ).toEqual(['Not now.', 'Enter a reason.']);
        expect(errorMessages({})).toEqual([]);
    });
});

describe('noticeGiven', () => {
    it('counts both days, so the same day is one', () => {
        expect(noticeGiven('2026-11-26', '2026-11-26')).toBe(1);
        expect(noticeGiven('2026-11-01', '2026-11-30')).toBe(30);
    });

    it('crosses a month and a year without a time zone shift', () => {
        expect(noticeGiven('2026-12-20', '2027-01-18')).toBe(30);
        expect(noticeGiven('2028-02-28', '2028-03-01')).toBe(3);
    });

    it('gives 0 for a bad date or a last day before the start', () => {
        expect(noticeGiven('2026-11-30', '2026-11-01')).toBe(0);
        expect(noticeGiven('x', '2026-11-01')).toBe(0);
    });
});

describe('generalErrors', () => {
    it('keeps only the messages that belong to no field of the dialog', () => {
        expect(
            generalErrors(
                {
                    reason: 'Enter a reason.',
                    exit: 'This exit has ended and cannot change.',
                    settlement: 'Not now.',
                },
                ['reason'],
            ),
        ).toEqual(['This exit has ended and cannot change.', 'Not now.']);
        expect(generalErrors({}, ['reason'])).toEqual([]);
    });
});
