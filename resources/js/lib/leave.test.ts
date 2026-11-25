import { describe, expect, test } from 'vitest';
import {
    balanceCardFigures,
    formatDayAmount,
    formatLeaveDays,
    leaveDateLabel,
    leaveRangeLabel,
    leaveStageLabel,
    leaveStatusLabel,
    leaveStatusTone,
    leftAfterLabel,
    PREVIEW_FAILED_MESSAGE,
    previewBlocksSubmit,
    weekdayOf,
} from '@/lib/leave';

describe('day amounts', () => {
    test.each([
        [1, '1 day'],
        [0.5, '0.5 day'],
        [2.5, '2.5 days'],
        [2, '2 days'],
        [0, '0 days'],
        [14, '14 days'],
    ])('%s → %s', (days, label) => {
        expect(formatLeaveDays(days)).toBe(label);
    });

    test('amounts have at most one decimal and never drift', () => {
        expect(formatDayAmount(14)).toBe('14');
        expect(formatDayAmount(9.5)).toBe('9.5');
        expect(formatDayAmount(-1.5)).toBe('-1.5');
        expect(formatDayAmount(0.1 + 0.2 + 0.2)).toBe('0.5');
    });

    test('left after this', () => {
        expect(leftAfterLabel(10, 3.5)).toBe('Left after this: 6.5 days');
        expect(leftAfterLabel(1.5, 0.5)).toBe('Left after this: 1 day');
        expect(leftAfterLabel(4, 4)).toBe('Left after this: 0 days');
    });
});

describe('date labels without Date', () => {
    test('weekdays by arithmetic', () => {
        expect(weekdayOf('2026-12-01')).toBe(2); // Tuesday
        expect(weekdayOf('2026-11-24')).toBe(2);
        expect(weekdayOf('2027-01-01')).toBe(5); // Friday
        expect(weekdayOf('2024-02-29')).toBe(4); // Thursday
        expect(weekdayOf('2026-13-01')).toBeNull();
        expect(weekdayOf('nonsense')).toBeNull();
    });

    test('one date', () => {
        expect(leaveDateLabel('2026-12-01')).toBe('Tue 1 Dec 2026');
        expect(leaveDateLabel('2027-01-01')).toBe('Fri 1 Jan 2027');
        expect(leaveDateLabel('bad')).toBe('bad');
    });

    test('ranges and halves', () => {
        expect(leaveRangeLabel('2026-12-01', '2026-12-03', null)).toBe(
            'Tue 1 Dec 2026 – Thu 3 Dec 2026',
        );
        expect(leaveRangeLabel('2026-12-01', '2026-12-01', null)).toBe(
            'Tue 1 Dec 2026',
        );
        expect(leaveRangeLabel('2026-12-07', '2026-12-07', 'am')).toBe(
            'Mon 7 Dec 2026, first half',
        );
        expect(leaveRangeLabel('2026-12-07', '2026-12-07', 'pm')).toBe(
            'Mon 7 Dec 2026, second half',
        );
        expect(leaveRangeLabel('2026-12-30', '2027-01-05', null)).toBe(
            'Wed 30 Dec 2026 – Tue 5 Jan 2027',
        );
    });

    test('does not depend on the browser time zone', () => {
        const original = process.env.TZ;
        process.env.TZ = 'Pacific/Kiritimati';
        expect(leaveDateLabel('2026-12-01')).toBe('Tue 1 Dec 2026');
        process.env.TZ = 'America/Adak';
        expect(leaveDateLabel('2026-12-01')).toBe('Tue 1 Dec 2026');
        process.env.TZ = original;
    });
});

describe('status and stage', () => {
    test('status words and tones', () => {
        expect(leaveStatusLabel('pending')).toBe('Pending');
        expect(leaveStatusLabel('manager_approved')).toBe('Pending');
        expect(leaveStatusLabel('approved')).toBe('Approved');
        expect(leaveStatusLabel('rejected')).toBe('Rejected');
        expect(leaveStatusLabel('cancelled')).toBe('Cancelled');
        expect(leaveStatusTone('pending')).toBe('amber');
        expect(leaveStatusTone('approved')).toBe('green');
        expect(leaveStatusTone('rejected')).toBe('red');
        expect(leaveStatusTone('cancelled')).toBe('grey');
    });

    test('stage labels', () => {
        expect(leaveStageLabel('manager', 'Ahmed')).toBe('Waiting for Ahmed');
        expect(leaveStageLabel('manager', null)).toBe(
            'Waiting for the manager',
        );
        expect(leaveStageLabel('final', 'Ahmed')).toBe('Waiting for HR');
        expect(leaveStageLabel(null, null)).toBeNull();
    });
});

describe('apply preview and balance cards', () => {
    const answer = (
        over: Partial<{
            refusal: string | null;
            overlap: string | null;
            years: { asked: number; available: number | null }[];
        }> = {},
    ) => ({
        refusal: null,
        overlap: null,
        years: [{ asked: 2, available: 10 }],
        ...over,
    });

    test('a failed preview never blocks Send; loading, no answer and refusals do', () => {
        expect(
            previewBlocksSubmit({ loading: false, failed: true, data: null }),
        ).toBe(false);
        expect(
            previewBlocksSubmit({ loading: true, failed: false, data: null }),
        ).toBe(true);
        expect(
            previewBlocksSubmit({ loading: false, failed: false, data: null }),
        ).toBe(true);
        expect(
            previewBlocksSubmit({
                loading: false,
                failed: false,
                data: answer(),
            }),
        ).toBe(false);
        expect(
            previewBlocksSubmit({
                loading: false,
                failed: false,
                data: answer({ refusal: 'No.' }),
            }),
        ).toBe(true);
        expect(
            previewBlocksSubmit({
                loading: false,
                failed: false,
                data: answer({ overlap: 'Taken.' }),
            }),
        ).toBe(true);
        expect(
            previewBlocksSubmit({
                loading: false,
                failed: false,
                data: answer({ years: [{ asked: 3, available: 2.5 }] }),
            }),
        ).toBe(true);
        expect(
            previewBlocksSubmit({
                loading: false,
                failed: false,
                data: answer({ years: [{ asked: 30, available: null }] }),
            }),
        ).toBe(false);
        expect(PREVIEW_FAILED_MESSAGE).toBe(
            'Could not check — you can still submit; it will be checked again.',
        );
    });

    test('cards show available first, remaining before pending only when something is pending', () => {
        expect(balanceCardFigures(10, 7.5, 2.5)).toEqual({
            main: '7.5 available',
            secondary: '10 remaining before pending',
        });
        expect(balanceCardFigures(10, 10, 0)).toEqual({
            main: '10 available',
            secondary: null,
        });
        expect(balanceCardFigures(2, -1, 3)).toEqual({
            main: '-1 available',
            secondary: '2 remaining before pending',
        });
    });
});
