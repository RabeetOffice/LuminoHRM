import { describe, expect, it } from 'vitest';
import {
    bpToPercent,
    filterLines,
    firstOpenDate,
    formatMoney,
    groupDigits,
    lineDeductions,
    monthLabel,
    needsAttention,
    nextMonth,
    parseRupees,
    peopleCount,
    percentToBp,
    rupeesText,
    rupeesToPaisa,
    runStatusPill,
    salaryTotals,
    shortMonthLabel,
    sortChecks,
    taxLabel,
} from '@/lib/payroll';
import type { RunCheck, RunLine } from '@/lib/payroll';

describe('formatMoney', () => {
    it('matches the server Money::format', () => {
        expect(formatMoney(6493000)).toBe('Rs 64,930');
        expect(formatMoney(0)).toBe('Rs 0');
        expect(formatMoney(100)).toBe('Rs 1');
        expect(formatMoney(6493050)).toBe('Rs 64,930.50');
        expect(formatMoney(6493005)).toBe('Rs 64,930.05');
        expect(formatMoney(-50000)).toBe('-Rs 500');
        expect(formatMoney(100000000000)).toBe('Rs 1,000,000,000');
        expect(formatMoney(999999900)).toBe('Rs 9,999,999');
        expect(formatMoney(245000000)).toBe('Rs 2,450,000');
        expect(formatMoney(99999)).toBe('Rs 999.99');
    });

    it('groups digits the Western way', () => {
        expect(groupDigits('1000000')).toBe('1,000,000');
        expect(groupDigits('999')).toBe('999');
        expect(rupeesText(9000000)).toBe('90,000');
    });
});

describe('rupeesToPaisa', () => {
    it('turns typed whole rupees into exact paisa', () => {
        expect(rupeesToPaisa('64930')).toBe(6493000);
        expect(rupeesToPaisa('64,930')).toBe(6493000);
        expect(rupeesToPaisa(' 1,000,000 ')).toBe(100000000);
        expect(rupeesToPaisa('0')).toBe(0);
        expect(rupeesToPaisa('99,999,999')).toBe(9999999900);
    });

    it('refuses anything that is not whole rupees', () => {
        expect(rupeesToPaisa('1,234.5')).toBeNull();
        expect(rupeesToPaisa('1234.50')).toBeNull();
        expect(rupeesToPaisa('')).toBeNull();
        expect(rupeesToPaisa('-5')).toBeNull();
        expect(rupeesToPaisa('12a')).toBeNull();
        expect(rupeesToPaisa('1,23')).toBeNull();
        expect(rupeesToPaisa('100,000,000')).toBeNull();
        expect(parseRupees('1e5')).toBeNull();
    });
});

describe('percentToBp and bpToPercent', () => {
    it('converts with string arithmetic, no float drift', () => {
        expect(percentToBp('5.25')).toBe(525);
        expect(percentToBp('5')).toBe(500);
        expect(percentToBp('5.1')).toBe(510);
        expect(percentToBp('0.07')).toBe(7);
        expect(percentToBp('100')).toBe(10000);
        expect(percentToBp('0')).toBe(0);
    });

    it('refuses more than two decimals, above 100 and junk', () => {
        expect(percentToBp('5.255')).toBeNull();
        expect(percentToBp('101')).toBeNull();
        expect(percentToBp('100.01')).toBeNull();
        expect(percentToBp('-1')).toBeNull();
        expect(percentToBp('')).toBeNull();
        expect(percentToBp('5%')).toBeNull();
    });

    it('shows basis points as people type them', () => {
        expect(bpToPercent(525)).toBe('5.25');
        expect(bpToPercent(500)).toBe('5');
        expect(bpToPercent(510)).toBe('5.1');
        expect(bpToPercent(7)).toBe('0.07');
        expect(bpToPercent(10000)).toBe('100');
        expect(percentToBp(bpToPercent(1234))).toBe(1234);
    });
});

describe('month labels', () => {
    it('reads a month as text', () => {
        expect(monthLabel('2026-11')).toBe('November 2026');
        expect(monthLabel('2027-01')).toBe('January 2027');
        expect(shortMonthLabel('2026-11')).toBe('Nov 2026');
        expect(monthLabel('2026-13')).toBe('2026-13');
        expect(monthLabel('junk')).toBe('junk');
    });
});

describe('taxLabel', () => {
    it('describes each tax mode', () => {
        expect(taxLabel('percentage', 500, 'gross_taxable')).toBe(
            '5% of gross taxable',
        );
        expect(taxLabel('percentage', 525, 'basic')).toBe('5.25% of basic');
        expect(taxLabel('fixed', 200000, null)).toBe('Rs 2,000 fixed');
        expect(taxLabel('none', 0, null)).toBe('None');
    });
});

describe('salaryTotals', () => {
    it('adds the live totals of the design example in paisa', () => {
        expect(
            salaryTotals('60,000', [
                {
                    name: 'House rent',
                    type: 'allowance',
                    amount: '24,000',
                    is_taxable: true,
                },
                {
                    name: 'Medical',
                    type: 'allowance',
                    amount: '6000',
                    is_taxable: false,
                },
                {
                    name: 'Provident fund',
                    type: 'deduction',
                    amount: '3,000',
                    is_taxable: false,
                },
                {
                    name: 'Typo',
                    type: 'allowance',
                    amount: '1.5',
                    is_taxable: true,
                },
            ]),
        ).toEqual({ gross: 9000000, taxable: 8400000, deductions: 300000 });
    });
});

describe('firstOpenDate', () => {
    it('starts after the latest approved month and not before joining', () => {
        expect(firstOpenDate('2026-12-03', null, '2024-03-01')).toBe(
            '2026-12-01',
        );
        expect(firstOpenDate('2026-12-03', '2026-11', '2024-03-01')).toBe(
            '2026-12-01',
        );
        expect(firstOpenDate('2026-11-24', '2026-11', '2024-03-01')).toBe(
            '2026-12-01',
        );
        expect(firstOpenDate('2026-12-03', '2026-12', '2024-03-01')).toBe(
            '2027-01-01',
        );
        expect(firstOpenDate('2026-12-03', null, '2027-01-10')).toBe(
            '2027-01-10',
        );
        expect(nextMonth('2026-09')).toBe('2026-10');
    });
});

describe('runStatusPill', () => {
    it('reads Draft amber, Approved green and Paid blue', () => {
        expect(runStatusPill('draft')).toEqual({
            label: 'Draft',
            tone: 'amber',
        });
        expect(runStatusPill('approved')).toEqual({
            label: 'Approved',
            tone: 'green',
        });
        expect(runStatusPill('paid')).toEqual({ label: 'Paid', tone: 'blue' });
    });
});

describe('sortChecks', () => {
    const check = (key: string, severity: 'block' | 'warn'): RunCheck => ({
        key,
        severity,
        message: key,
        people: [],
        count: 0,
    });

    it('puts blocking checks first and keeps the order inside each group', () => {
        const sorted = sortChecks([
            check('w1', 'warn'),
            check('b1', 'block'),
            check('w2', 'warn'),
            check('b2', 'block'),
        ]);

        expect(sorted.map((c) => c.key)).toEqual(['b1', 'b2', 'w1', 'w2']);
    });
});

describe('run lines', () => {
    const line = (over: Partial<RunLine> = {}): RunLine => ({
        id: 1,
        employee: {
            id: 1,
            name: 'Sara Ahmed',
            code: 'LD-0007',
            department: 'HR',
        },
        days_paid: 30,
        gross: 9000000,
        attendance_deduction: 1350000,
        other_deductions: 300000,
        loan_deduction: 500000,
        tax: 357000,
        net: 6493000,
        excluded: false,
        excluded_reason: null,
        tax_overridden: false,
        warnings: 0,
        blocking: [],
        can_edit: true,
        payslip_url: null,
        ...over,
    });

    it('adds up the deductions taken before tax, in paisa', () => {
        expect(lineDeductions(line())).toBe(2150000);
        expect(formatMoney(lineDeductions(line()))).toBe('Rs 21,500');
    });

    it('needs attention for a blocking key or a warning, never when excluded', () => {
        expect(needsAttention(line())).toBe(false);
        expect(needsAttention(line({ warnings: 1 }))).toBe(true);
        expect(needsAttention(line({ blocking: ['no_profile'] }))).toBe(true);
        expect(
            needsAttention(line({ excluded: true, blocking: ['no_profile'] })),
        ).toBe(false);
    });

    it('filters by name or code and by needs attention', () => {
        const lines = [
            line(),
            line({
                id: 2,
                employee: {
                    id: 2,
                    name: 'Omar Khan',
                    code: 'LD-0008',
                    department: null,
                },
                warnings: 2,
            }),
        ];

        expect(filterLines(lines, '', false)).toHaveLength(2);
        expect(filterLines(lines, ' sara ', false).map((l) => l.id)).toEqual([
            1,
        ]);
        expect(filterLines(lines, 'ld-0008', false).map((l) => l.id)).toEqual([
            2,
        ]);
        expect(filterLines(lines, '', true).map((l) => l.id)).toEqual([2]);
        expect(filterLines(lines, 'sara', true)).toEqual([]);
    });

    it('counts people', () => {
        expect(peopleCount(1)).toBe('1 person');
        expect(peopleCount(3)).toBe('3 people');
    });
});
