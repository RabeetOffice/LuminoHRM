import { describe, expect, it } from 'vitest';
import {
    isMonth,
    loanStatusPill,
    loanTypeLabel,
    taxYearLabel,
} from '@/lib/payroll';

describe('loans and opening figures', () => {
    it('labels loan types and statuses', () => {
        expect(loanTypeLabel('loan')).toBe('Loan');
        expect(loanTypeLabel('advance')).toBe('Advance');
        expect(loanStatusPill('active')).toEqual({
            label: 'Active',
            tone: 'green',
        });
        expect(loanStatusPill('cleared')).toEqual({
            label: 'Cleared',
            tone: 'grey',
        });
        expect(loanStatusPill('cancelled')).toEqual({
            label: 'Cancelled',
            tone: 'red',
        });
    });

    it('names tax years like the server', () => {
        expect(taxYearLabel(2026)).toBe('2026-27');
        expect(taxYearLabel(2025)).toBe('2025-26');
        expect(taxYearLabel(2099)).toBe('2099-00');
    });

    it('recognises months', () => {
        expect(isMonth('2026-11')).toBe(true);
        expect(isMonth('2026-13')).toBe(false);
        expect(isMonth('2026-1')).toBe(false);
        expect(isMonth('2026-11-01')).toBe(false);
    });
});
