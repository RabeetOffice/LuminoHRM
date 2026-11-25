import { describe, expect, test } from 'vitest';
import {
    cellText,
    daysLabel,
    formatDays,
    parseHalves,
    remainingAfter,
} from './leave-balance';
import type { BalanceCell } from './leave-balance';

const cell = (over: Partial<BalanceCell> = {}): BalanceCell => ({
    type_id: 1,
    type_name: 'Annual',
    colour: 'emerald',
    year: 2026,
    is_paid: true,
    unlimited: false,
    allocated: 14,
    carried: 2,
    adjustment: 0,
    opening_used: 0,
    used: 3.5,
    pending: 1,
    remaining: 12.5,
    available: 11.5,
    entitlement: 16,
    overdrawn: false,
    projected: false,
    label: '12.5 of 16',
    can_edit: true,
    ...over,
});

describe('formatDays', () => {
    test.each([
        [14, '14'],
        [9.5, '9.5'],
        [0, '0'],
        [-1.5, '-1.5'],
        [0.1 + 0.2 + 0.2, '0.5'],
    ])('%s → %s', (value, shown) => {
        expect(formatDays(value)).toBe(shown);
    });

    test('daysLabel', () => {
        expect(daysLabel(1)).toBe('1 day');
        expect(daysLabel(2.5)).toBe('2.5 days');
        expect(daysLabel(0)).toBe('0 days');
    });
});

describe('parseHalves', () => {
    test.each([
        ['1.5', 3],
        ['0', 0],
        [' 14 ', 28],
        ['-2.5', -5],
        ['', null],
        ['abc', null],
        ['1.25', null],
        ['1e2', null],
    ])('%j → %j', (value, halves) => {
        expect(parseHalves(value)).toBe(halves);
    });
});

describe('remainingAfter', () => {
    test('allocated + carried + adjustment − opening used − used', () => {
        expect(
            remainingAfter(cell(), {
                allocated: '14',
                adjustment: '1.5',
                opening_used: '2',
            }),
        ).toBe(12);
    });

    test('can go below zero', () => {
        expect(
            remainingAfter(cell({ carried: 0, used: 5 }), {
                allocated: '3',
                adjustment: '0',
                opening_used: '0',
            }),
        ).toBe(-2);
    });

    test('null while a figure is not a whole or half day', () => {
        expect(
            remainingAfter(cell(), {
                allocated: '14.3',
                adjustment: '0',
                opening_used: '0',
            }),
        ).toBeNull();
        expect(
            remainingAfter(cell(), {
                allocated: '',
                adjustment: '0',
                opening_used: '0',
            }),
        ).toBeNull();
    });
});

describe('cellText', () => {
    test('paid: remaining / entitlement', () => {
        expect(cellText(cell())).toBe('12.5 / 16');
    });

    test('unpaid: used', () => {
        expect(
            cellText(
                cell({
                    is_paid: false,
                    unlimited: true,
                    remaining: null,
                    available: null,
                    entitlement: null,
                    used: 2,
                }),
            ),
        ).toBe('2 used');
    });
});
