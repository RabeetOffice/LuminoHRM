import { describe, expect, it } from 'vitest';
import {
    daysBetween,
    DOCUMENT_SIZE_MESSAGE,
    DOCUMENT_TYPE_MESSAGE,
    documentProblem,
    documentTypeLabel,
    expiryState,
    PHOTO_SIZE_MESSAGE,
    PHOTO_TYPE_MESSAGE,
    photoProblem,
    todayIso,
} from './employee-files';

describe('documentTypeLabel', () => {
    it('words the configured types and leaves unknown ones as stored', () => {
        expect(documentTypeLabel('cnic')).toBe('CNIC');
        expect(documentTypeLabel('offer_letter')).toBe('Offer letter');
        expect(documentTypeLabel('passport')).toBe('passport');
    });
});

describe('photoProblem', () => {
    it('accepts png, jpeg and webp up to 2 MB', () => {
        for (const type of ['image/png', 'image/jpeg', 'image/webp']) {
            expect(photoProblem({ type, size: 2 * 1024 * 1024 })).toBeNull();
        }
    });

    it('refuses other types and bigger files', () => {
        expect(photoProblem({ type: 'image/gif', size: 10 })).toBe(
            PHOTO_TYPE_MESSAGE,
        );
        expect(photoProblem({ type: 'image/svg+xml', size: 10 })).toBe(
            PHOTO_TYPE_MESSAGE,
        );
        expect(
            photoProblem({ type: 'image/png', size: 2 * 1024 * 1024 + 1 }),
        ).toBe(PHOTO_SIZE_MESSAGE);
    });
});

describe('documentProblem', () => {
    it('accepts the allowed extensions in any case up to 10 MB', () => {
        for (const name of ['a.pdf', 'b.DOCX', 'c.doc', 'd.JPEG', 'e.webp']) {
            expect(
                documentProblem({ name, size: 10 * 1024 * 1024 }),
            ).toBeNull();
        }
    });

    it('refuses other extensions, no extension and bigger files', () => {
        expect(documentProblem({ name: 'x.html', size: 1 })).toBe(
            DOCUMENT_TYPE_MESSAGE,
        );
        expect(documentProblem({ name: 'contract', size: 1 })).toBe(
            DOCUMENT_TYPE_MESSAGE,
        );
        expect(documentProblem({ name: 'x.pdf.exe', size: 1 })).toBe(
            DOCUMENT_TYPE_MESSAGE,
        );
        expect(
            documentProblem({ name: 'x.pdf', size: 10 * 1024 * 1024 + 1 }),
        ).toBe(DOCUMENT_SIZE_MESSAGE);
    });
});

describe('dates', () => {
    it('gives today as YYYY-MM-DD in local time', () => {
        expect(todayIso(new Date(2026, 0, 5, 23, 59))).toBe('2026-01-05');
    });

    it('counts whole days, across a month and a leap day', () => {
        expect(daysBetween('2026-01-31', '2026-02-01')).toBe(1);
        expect(daysBetween('2028-02-28', '2028-03-01')).toBe(2);
        expect(daysBetween('2026-03-10', '2026-03-01')).toBe(-9);
        expect(daysBetween('soon', '2026-03-01')).toBeNull();
    });
});

describe('expiryState', () => {
    const today = '2026-11-24';

    it('is nothing without an expiry date or far ahead', () => {
        expect(expiryState(null, today)).toBeNull();
        expect(expiryState('', today)).toBeNull();
        expect(expiryState('2026-12-25', today)).toBeNull();
        expect(expiryState('garbage', today)).toBeNull();
    });

    it('expires soon from 30 days before up to the day itself', () => {
        expect(expiryState('2026-12-24', today)).toBe('soon');
        expect(expiryState('2026-11-24', today)).toBe('soon');
    });

    it('has expired the day after', () => {
        expect(expiryState('2026-11-23', today)).toBe('expired');
    });
});
