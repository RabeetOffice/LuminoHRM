import { describe, expect, it } from 'vitest';
import {
    EMPTY_VALUE,
    fileSize,
    isEmptyValue,
    PROFILE_TABS,
    visibleTabs,
    wordFor,
} from '@/lib/employee-profile';

describe('isEmptyValue', () => {
    it('treats null, undefined, blank text and false as empty', () => {
        for (const value of [null, undefined, '', '   ', false]) {
            expect(isEmptyValue(value)).toBe(true);
        }
    });

    it('keeps zero, text and elements as values', () => {
        for (const value of [0, 'x', '0', { a: 1 }]) {
            expect(isEmptyValue(value)).toBe(false);
        }
    });

    it('uses an em dash as the empty marker', () => {
        expect(EMPTY_VALUE).toBe('—');
    });
});

describe('visibleTabs', () => {
    it('keeps only the tabs the server allowed, in display order', () => {
        expect(visibleTabs(['employment', 'basic']).map((t) => t.key)).toEqual([
            'basic',
            'employment',
        ]);
    });

    it('ignores keys it does not know and returns nothing for an empty list', () => {
        expect(visibleTabs(['payslips', 'basic']).map((t) => t.key)).toEqual([
            'basic',
        ]);
        expect(visibleTabs([])).toEqual([]);
    });

    it('has a label and a card title for every tab', () => {
        expect(PROFILE_TABS.map((t) => t.label)).toEqual([
            'Basic Info',
            'Employment',
            'Contact',
            'Banking',
            'Documents',
            'Attendance',
            'Leave',
            'Salary',
            'Assets',
        ]);
        expect(PROFILE_TABS[0].title).toBe('Basic Information');
    });
});

describe('wordFor', () => {
    it('turns stored words into readable ones', () => {
        expect(wordFor('full_time')).toBe('Full time');
        expect(wordFor('hr')).toBe('HR');
        expect(wordFor('none')).toBe('No account');
    });

    it('returns unknown values as stored and empty ones as null', () => {
        expect(wordFor('freelance')).toBe('freelance');
        expect(wordFor(null)).toBeNull();
        expect(wordFor('')).toBeNull();
    });
});

describe('fileSize', () => {
    it('picks bytes, kilobytes or megabytes', () => {
        expect(fileSize(850)).toBe('850 B');
        expect(fileSize(12 * 1024)).toBe('12 KB');
        expect(fileSize(1.5 * 1024 * 1024)).toBe('1.5 MB');
    });
});
