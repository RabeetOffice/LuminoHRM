/**
 * Small rules for employee photos and documents on the client. The server checks everything again (type from the
 * file's content, size, dimensions, the 50-document cap); these only save a pointless upload and explain why.
 */

/** What the file picker offers for a photo. */
export const PHOTO_ACCEPT = 'image/png,image/jpeg,image/webp';

/** What the file picker offers for a document. */
export const DOCUMENT_ACCEPT =
    '.pdf,.doc,.docx,.png,.jpg,.jpeg,.webp,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document,image/png,image/jpeg,image/webp';

export const PHOTO_MAX_BYTES = 2 * 1024 * 1024;

export const DOCUMENT_MAX_BYTES = 10 * 1024 * 1024;

export const MAX_DOCUMENTS = 50;

/** Days before the expiry date from which a document "expires soon". */
export const EXPIRES_SOON_DAYS = 30;

// The server's wording, so the person reads the same sentence whichever side refuses the file.
export const PHOTO_TYPE_MESSAGE = 'The photo must be a PNG, JPG or WebP image.';
export const PHOTO_SIZE_MESSAGE = 'The photo may not be larger than 2 MB.';
export const DOCUMENT_TYPE_MESSAGE =
    'The file must be a PDF, a Word document (DOC or DOCX) or a PNG, JPG or WebP image.';
export const DOCUMENT_SIZE_MESSAGE = 'The file may not be larger than 10 MB.';

const PHOTO_TYPES = ['image/png', 'image/jpeg', 'image/webp'];
const DOCUMENT_EXTENSIONS = [
    'pdf',
    'doc',
    'docx',
    'png',
    'jpg',
    'jpeg',
    'webp',
];

const TYPE_LABELS: Record<string, string> = {
    cnic: 'CNIC',
    contract: 'Contract',
    offer_letter: 'Offer letter',
    certificate: 'Certificate',
    other: 'Other',
};

/** A stored document type ("offer_letter") as people read it ("Offer letter"). Unknown types come back as stored. */
export function documentTypeLabel(type: string): string {
    return TYPE_LABELS[type] ?? type;
}

/** Why a chosen photo will be refused, or null when it looks fine. */
export function photoProblem(file: Pick<File, 'type' | 'size'>): string | null {
    if (!PHOTO_TYPES.includes(file.type)) {
        return PHOTO_TYPE_MESSAGE;
    }

    return file.size > PHOTO_MAX_BYTES ? PHOTO_SIZE_MESSAGE : null;
}

/** Why a chosen document will be refused, or null when it looks fine (the server reads the content). */
export function documentProblem(
    file: Pick<File, 'name' | 'size'>,
): string | null {
    const dot = file.name.lastIndexOf('.');
    const extension = dot === -1 ? '' : file.name.slice(dot + 1).toLowerCase();

    if (!DOCUMENT_EXTENSIONS.includes(extension)) {
        return DOCUMENT_TYPE_MESSAGE;
    }

    return file.size > DOCUMENT_MAX_BYTES ? DOCUMENT_SIZE_MESSAGE : null;
}

/** Today's calendar date in the browser, as YYYY-MM-DD. */
export function todayIso(now: Date = new Date()): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

/** Whole days from one YYYY-MM-DD date to another (negative when `to` is earlier). Null for anything else. */
export function daysBetween(from: string, to: string): number | null {
    const pattern = /^\d{4}-\d{2}-\d{2}$/;

    if (!pattern.test(from) || !pattern.test(to)) {
        return null;
    }

    const a = Date.parse(`${from}T00:00:00Z`);
    const b = Date.parse(`${to}T00:00:00Z`);

    return Number.isNaN(a) || Number.isNaN(b)
        ? null
        : Math.round((b - a) / 86_400_000);
}

export type ExpiryState = 'expired' | 'soon' | null;

/** "expired" after the expiry date, "soon" from 30 days before it up to the day itself, otherwise null. */
export function expiryState(
    expiry: string | null | undefined,
    today: string,
): ExpiryState {
    if (!expiry) {
        return null;
    }

    const days = daysBetween(today, expiry);

    if (days === null) {
        return null;
    }

    if (days < 0) {
        return 'expired';
    }

    return days <= EXPIRES_SOON_DAYS ? 'soon' : null;
}
