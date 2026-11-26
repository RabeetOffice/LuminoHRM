import { describe, expect, it } from 'vitest';
import {
    ASSET_CONDITIONS,
    ASSET_STATUSES,
    assetConditionLabel,
    assetConditionOptions,
    assetStatusLabel,
    assetStatusTone,
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
