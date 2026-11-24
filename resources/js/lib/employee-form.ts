export type FormTabKey =
    | 'basic'
    | 'employment'
    | 'contact'
    | 'banking'
    | 'documents';

export const FORM_TABS: { key: FormTabKey; label: string }[] = [
    { key: 'basic', label: 'Basic' },
    { key: 'employment', label: 'Employment' },
    { key: 'contact', label: 'Contact' },
    { key: 'banking', label: 'Banking' },
    { key: 'documents', label: 'Documents' },
];

/** Which tab shows which field, so an error on a field can open the right tab. */
const FIELD_TAB: Record<string, FormTabKey> = {
    first_name: 'basic',
    last_name: 'basic',
    employee_code: 'basic',
    email: 'basic',
    phone: 'basic',
    dob: 'basic',
    gender: 'basic',
    cnic: 'basic',
    photo: 'basic',
    department_id: 'employment',
    designation_id: 'employment',
    manager_id: 'employment',
    joining_date: 'employment',
    probation_end_date: 'employment',
    employment_type: 'employment',
    status: 'employment',
    device_user_id: 'employment',
    shift_id: 'employment',
    attendance_exempt: 'employment',
    role: 'employment',
    address: 'contact',
    emergency_name: 'contact',
    emergency_phone: 'contact',
    bank_name: 'banking',
    account_title: 'banking',
    account_number: 'banking',
};

export function tabForField(field: string): FormTabKey | null {
    return FIELD_TAB[field] ?? null;
}

/** The tabs that hold at least one error message, in tab order. */
export function tabsWithErrors(
    errors: Record<string, string | undefined>,
): FormTabKey[] {
    const withErrors = new Set<FormTabKey>();

    for (const [field, message] of Object.entries(errors)) {
        const tab = message ? tabForField(field) : null;

        if (tab) {
            withErrors.add(tab);
        }
    }

    return FORM_TABS.map((t) => t.key).filter((key) => withErrors.has(key));
}

export function firstTabWithError(
    errors: Record<string, string | undefined>,
): FormTabKey | null {
    return tabsWithErrors(errors)[0] ?? null;
}

/** 13 digits as 12345-1234567-1, while typing: anything that is not a digit is dropped. */
export function formatCnic(value: string): string {
    const digits = value.replace(/\D/g, '').slice(0, 13);
    const parts = [
        digits.slice(0, 5),
        digits.slice(5, 12),
        digits.slice(12, 13),
    ].filter((part) => part !== '');

    return parts.join('-');
}

/** The designations of one department (none until a department is chosen). */
export function designationsFor<T extends { department_id: number }>(
    designations: T[],
    departmentId: string,
): T[] {
    return departmentId === ''
        ? []
        : designations.filter((d) => String(d.department_id) === departmentId);
}

/**
 * The shifts to list under "Company default (...)" in the employee form's Shift select: every shift but the default,
 * which that first entry already stands for. A person pinned to the default shift (their own shift_id is the default's
 * id) keeps it listed, so the stored value still shows and is not lost on save.
 */
export function explicitShiftChoices<
    T extends { id: number; is_default: boolean },
>(shifts: T[], pinnedShiftId: number | null | undefined): T[] {
    return shifts.filter(
        (shift) => !shift.is_default || shift.id === pinnedShiftId,
    );
}
