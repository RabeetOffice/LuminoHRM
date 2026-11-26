import { describe, expect, it } from 'vitest';
import {
    ASSET_CONDITIONS,
    ASSET_STATUSES,
    assetConditionLabel,
    assetConditionOptions,
    assetStatusLabel,
    assetStatusTone,
    costFieldText,
    filterPeople,
    firstError,
    personLabel,
    returnStatusOptions,
    statusChoices,
} from './assets';

describe('assetStatusLabel', () => {
    it('reads each status in plain words', () => {
        expect(assetStatusLabel('available')).toBe('Available');
        expect(assetStatusLabel('assigned')).toBe('Assigned');
        expect(assetStatusLabel('in_repair')).toBe('In repair');
        expect(assetStatusLabel('retired')).toBe('Retired');
    });

    it('has a label for every status', () => {
        for (const status of ASSET_STATUSES) {
            expect(assetStatusLabel(status)).not.toBe(status);
        }
    });

    it('falls back to the raw value for an unknown status', () => {
        expect(assetStatusLabel('lost')).toBe('lost');
        expect(assetStatusLabel('toString')).toBe('toString');
    });
});

describe('assetStatusTone', () => {
    it('colours each status', () => {
        expect(assetStatusTone('available')).toBe('green');
        expect(assetStatusTone('assigned')).toBe('blue');
        expect(assetStatusTone('in_repair')).toBe('amber');
        expect(assetStatusTone('retired')).toBe('grey');
    });

    it('is grey for an unknown status', () => {
        expect(assetStatusTone('lost')).toBe('grey');
        expect(assetStatusTone('constructor')).toBe('grey');
    });
});

describe('assetConditionLabel', () => {
    it('reads each condition in plain words', () => {
        expect(assetConditionLabel('new')).toBe('New');
        expect(assetConditionLabel('good')).toBe('Good');
        expect(assetConditionLabel('fair')).toBe('Fair');
        expect(assetConditionLabel('poor')).toBe('Poor');
        expect(assetConditionLabel('damaged')).toBe('Damaged');
    });

    it('falls back to the raw value for an unknown condition', () => {
        expect(assetConditionLabel('broken')).toBe('broken');
    });
});

describe('assetConditionOptions', () => {
    it('lists the five conditions, best first, with their labels', () => {
        expect(assetConditionOptions()).toEqual([
            { value: 'new', label: 'New' },
            { value: 'good', label: 'Good' },
            { value: 'fair', label: 'Fair' },
            { value: 'poor', label: 'Poor' },
            { value: 'damaged', label: 'Damaged' },
        ]);
        expect(assetConditionOptions()).toHaveLength(ASSET_CONDITIONS.length);
    });
});

describe('personLabel', () => {
    it('shows the name and the code', () => {
        expect(personLabel({ name: 'Sara Ahmed', code: 'EMP-0007' })).toBe(
            'Sara Ahmed (EMP-0007)',
        );
    });
});

describe('filterPeople', () => {
    const people = [
        { id: 1, name: 'Sara Ahmed', code: 'EMP-0007' },
        { id: 2, name: 'Ali Raza', code: 'EMP-0010' },
    ];

    it('keeps everyone for empty text', () => {
        expect(filterPeople(people, '  ')).toEqual(people);
    });

    it('matches part of a name or a code, ignoring case and extra spaces', () => {
        expect(filterPeople(people, 'sara  ahm').map((p) => p.id)).toEqual([1]);
        expect(filterPeople(people, 'emp-0010').map((p) => p.id)).toEqual([2]);
        expect(filterPeople(people, 'zzz')).toEqual([]);
    });
});

describe('returnStatusOptions', () => {
    it('offers available and in repair to HR', () => {
        expect(returnStatusOptions(false)).toEqual([
            { value: 'available', label: 'Available' },
            { value: 'in_repair', label: 'In repair' },
        ]);
    });

    it('adds retired for an Admin', () => {
        expect(returnStatusOptions(true).map((o) => o.value)).toEqual([
            'available',
            'in_repair',
            'retired',
        ]);
    });
});

describe('statusChoices', () => {
    it('labels the targets the server allowed, in order', () => {
        expect(statusChoices(['in_repair', 'retired'])).toEqual([
            { value: 'in_repair', label: 'In repair' },
            { value: 'retired', label: 'Retired' },
        ]);
        expect(statusChoices([])).toEqual([]);
    });
});

describe('costFieldText', () => {
    it('turns paisa into whole rupees for the field', () => {
        expect(costFieldText(15050000)).toBe('150500');
        expect(costFieldText(0)).toBe('0');
    });

    it('is empty without a cost and keeps paisa visible so the server can refuse them', () => {
        expect(costFieldText(null)).toBe('');
        expect(costFieldText(undefined)).toBe('');
        expect(costFieldText(150050)).toBe('1500.50');
    });
});

describe('firstError', () => {
    it('returns the first message found under the given keys', () => {
        expect(
            firstError({ assignment: 'Already returned.', note: 'Too long' }, [
                'asset',
                'assignment',
            ]),
        ).toBe('Already returned.');
    });

    it('is undefined when none of the keys has a message', () => {
        expect(firstError({ note: 'x' }, ['asset'])).toBeUndefined();
        expect(firstError({ asset: '' }, ['asset'])).toBeUndefined();
    });
});
