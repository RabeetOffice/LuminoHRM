import { describe, expect, it } from 'vitest';
import {
    FORM_TABS,
    designationsFor,
    explicitShiftChoices,
    firstTabWithError,
    formatCnic,
    tabForField,
    tabsWithErrors,
} from '@/lib/employee-form';

describe('tabForField', () => {
    it('maps every field to the tab that shows it', () => {
        expect(tabForField('first_name')).toBe('basic');
        expect(tabForField('email')).toBe('basic');
        expect(tabForField('cnic')).toBe('basic');
        expect(tabForField('employee_code')).toBe('basic');
        expect(tabForField('department_id')).toBe('employment');
        expect(tabForField('manager_id')).toBe('employment');
        expect(tabForField('role')).toBe('employment');
        expect(tabForField('device_user_id')).toBe('employment');
        expect(tabForField('shift_id')).toBe('employment');
        expect(tabForField('attendance_exempt')).toBe('employment');
        expect(tabForField('address')).toBe('contact');
        expect(tabForField('emergency_phone')).toBe('contact');
        expect(tabForField('account_number')).toBe('banking');
    });

    it('returns null for a field no tab owns', () => {
        expect(tabForField('nonsense')).toBeNull();
        expect(tabForField('')).toBeNull();
    });
});

describe('tabsWithErrors', () => {
    it('lists the tabs that hold an error, in tab order', () => {
        expect(
            tabsWithErrors({
                bank_name: 'Too long',
                first_name: 'Required',
                joining_date: 'Required',
            }),
        ).toEqual(['basic', 'employment', 'banking']);
    });

    it('ignores empty messages and unknown fields', () => {
        expect(tabsWithErrors({ first_name: '', other: 'x' })).toEqual([]);
        expect(tabsWithErrors({})).toEqual([]);
    });
});

describe('firstTabWithError', () => {
    it('opens the first tab that has an error, whatever order the errors arrived in', () => {
        expect(firstTabWithError({ account_number: 'x', address: 'y' })).toBe(
            'contact',
        );
    });

    it('returns null when there is no error', () => {
        expect(firstTabWithError({})).toBeNull();
    });
});

describe('FORM_TABS', () => {
    it('has the five tabs in the agreed order', () => {
        expect(FORM_TABS.map((t) => t.label)).toEqual([
            'Basic',
            'Employment',
            'Contact',
            'Banking',
            'Documents',
        ]);
    });
});

describe('formatCnic', () => {
    it('groups digits 5-7-1 as they are typed', () => {
        expect(formatCnic('4220112345671')).toBe('42201-1234567-1');
        expect(formatCnic('42201')).toBe('42201');
        expect(formatCnic('422011')).toBe('42201-1');
        expect(formatCnic('422011234567')).toBe('42201-1234567');
    });

    it('drops anything that is not a digit and anything past 13 digits', () => {
        expect(formatCnic('42201-1234567-1')).toBe('42201-1234567-1');
        expect(formatCnic('abc42201 1234567 1999')).toBe('42201-1234567-1');
        expect(formatCnic('')).toBe('');
    });
});

describe('designationsFor', () => {
    const all = [
        { id: 1, name: 'Engineer', department_id: 10 },
        { id: 2, name: 'Rep', department_id: 20 },
        { id: 3, name: 'Lead', department_id: 10 },
    ];

    it('keeps only the chosen department', () => {
        expect(designationsFor(all, '10').map((d) => d.id)).toEqual([1, 3]);
    });

    it('is empty until a department is chosen', () => {
        expect(designationsFor(all, '')).toEqual([]);
    });
});

describe('explicitShiftChoices', () => {
    const shifts = [
        { id: 1, name: 'General', is_default: true },
        { id: 2, name: 'Early', is_default: false },
        { id: 3, name: 'Late', is_default: false },
    ];

    it('leaves out the default shift, which "Company default" stands for', () => {
        expect(explicitShiftChoices(shifts, null).map((s) => s.id)).toEqual([
            2, 3,
        ]);
        expect(
            explicitShiftChoices(shifts, undefined).map((s) => s.id),
        ).toEqual([2, 3]);
        expect(explicitShiftChoices(shifts, 3).map((s) => s.id)).toEqual([
            2, 3,
        ]);
    });

    it('keeps the default shift for a person already pinned to it', () => {
        expect(explicitShiftChoices(shifts, 1).map((s) => s.id)).toEqual([
            1, 2, 3,
        ]);
    });
});
