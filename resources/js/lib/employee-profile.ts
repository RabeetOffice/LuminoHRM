/** Shown for a field that has no value. */
export const EMPTY_VALUE = '—';

/** The profile's tabs, in display order, with the label on the tab and the title of the card under it. */
export const PROFILE_TABS = [
    { key: 'basic', label: 'Basic Info', title: 'Basic Information' },
    { key: 'employment', label: 'Employment', title: 'Employment' },
    { key: 'contact', label: 'Contact', title: 'Contact' },
    { key: 'banking', label: 'Banking', title: 'Banking' },
    { key: 'documents', label: 'Documents', title: 'Documents' },
    { key: 'attendance', label: 'Attendance', title: 'Attendance this month' },
    { key: 'leave', label: 'Leave', title: 'Leave' },
    { key: 'salary', label: 'Salary', title: 'Salary' },
] as const;

export type ProfileTabKey = (typeof PROFILE_TABS)[number]['key'];

/** Whether a value counts as "nothing to show" (null, undefined, empty or blank text). */
export function isEmptyValue(value: unknown): boolean {
    return (
        value === null ||
        value === undefined ||
        value === false ||
        (typeof value === 'string' && value.trim() === '')
    );
}

/** The tabs the server allowed, in display order. Unknown keys from the server are ignored. */
export function visibleTabs(
    allowed: string[],
): (typeof PROFILE_TABS)[number][] {
    return PROFILE_TABS.filter((tab) => allowed.includes(tab.key));
}

const WORDS: Record<string, string> = {
    male: 'Male',
    female: 'Female',
    other: 'Other',
    full_time: 'Full time',
    part_time: 'Part time',
    contract: 'Contract',
    intern: 'Intern',
    admin: 'Admin',
    hr: 'HR',
    manager: 'Manager',
    employee: 'Employee',
    active: 'Active',
    disabled: 'Disabled',
    none: 'No account',
};

/** A stored word ("full_time") in the words people read ("Full time"). Unknown values come back as stored. */
export function wordFor(value: string | null | undefined): string | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }

    return WORDS[value] ?? value;
}

/** A file size as people read it: "850 B", "12 KB", "1.5 MB". */
export function fileSize(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
