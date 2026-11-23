import { describe, expect, it } from 'vitest';
import { PER_PAGE_OPTIONS, nextTableUrl, pageItems } from '@/lib/table-query';

describe('nextTableUrl', () => {
    it('keeps the path and the other parameters', () => {
        expect(
            nextTableUrl('/organization?tab=designations&desig_page=3', {
                dept_page: 2,
            }),
        ).toBe('/organization?tab=designations&desig_page=3&dept_page=2');
    });

    it('replaces an existing value', () => {
        expect(nextTableUrl('/x?search=a&dept_page=4', { search: 'b' })).toBe(
            '/x?search=b&dept_page=4',
        );
    });

    it('removes parameters set to null, undefined or an empty string', () => {
        expect(
            nextTableUrl('/x?search=a&dept_page=4&per_page=25', {
                search: '',
                dept_page: null,
                per_page: undefined,
            }),
        ).toBe('/x');
    });

    it('works from a url without a query string', () => {
        expect(nextTableUrl('/x', { per_page: 25 })).toBe('/x?per_page=25');
    });
});

describe('pageItems', () => {
    it('lists every page when there are few', () => {
        expect(pageItems(1, 3)).toEqual([1, 2, 3]);
    });

    it('collapses a long range around the current page', () => {
        expect(pageItems(1, 10)).toEqual([1, 2, 'gap', 10]);
        expect(pageItems(5, 10)).toEqual([1, 'gap', 4, 5, 6, 'gap', 10]);
        expect(pageItems(10, 10)).toEqual([1, 'gap', 9, 10]);
    });

    it('does not collapse a single hidden page', () => {
        expect(pageItems(3, 5)).toEqual([1, 2, 3, 4, 5]);
    });

    it('returns nothing for an empty list', () => {
        expect(pageItems(1, 0)).toEqual([]);
    });
});

describe('PER_PAGE_OPTIONS', () => {
    it('offers 10, 25 and 50', () => {
        expect(PER_PAGE_OPTIONS).toEqual([10, 25, 50]);
    });
});
