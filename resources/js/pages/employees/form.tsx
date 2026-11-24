import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    StatusDialog,
    STATUS_LABELS,
} from '@/components/employees/status-dialog';
import { Avatar } from '@/components/hrm/avatar';
import { FormTabs } from '@/components/hrm/form-tabs';
import { ManagerPicker } from '@/components/hrm/manager-picker';
import type { ManagerOption } from '@/components/hrm/manager-picker';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useUnsavedChangesGuard } from '@/hooks/use-unsaved-changes';
import {
    designationsFor,
    firstTabWithError,
    formatCnic,
    tabsWithErrors,
} from '@/lib/employee-form';
import type { FormTabKey } from '@/lib/employee-form';
import { create, index, store, update } from '@/routes/employees';

type EmployeeRecord = {
    id: number;
    code: string;
    first_name: string;
    last_name: string;
    name: string;
    email: string;
    phone: string | null;
    dob: string | null;
    gender: string | null;
    cnic: string | null;
    department_id: number;
    designation_id: number;
    manager_id: number | null;
    joining_date: string;
    probation_end_date: string | null;
    employment_type: string;
    status: string;
    last_working_day: string | null;
    device_user_id: string | null;
    role: string;
    has_login: boolean;
    login_active: boolean | null;
    address: string | null;
    emergency_name: string | null;
    emergency_phone: string | null;
    bank_name: string | null;
    account_title: string | null;
    account_number: string | null;
};

type Props = {
    employee: EmployeeRecord | null;
    manager: ManagerOption | null;
    departments: { id: number; name: string }[];
    designations: { id: number; name: string; department_id: number }[];
    genders: string[];
    employmentTypes: string[];
    statusOptions: string[];
    roleOptions: { value: string; label: string }[];
    roleLocked: boolean;
    can: { editCode: boolean; edit: boolean };
};

const NONE = 'none';

const LABELS: Record<string, string> = {
    male: 'Male',
    female: 'Female',
    other: 'Other',
    full_time: 'Full time',
    part_time: 'Part time',
    contract: 'Contract',
    intern: 'Intern',
};

const label = (value: string) => LABELS[value] ?? value;

const STATUS_TONE: Record<string, StatusTone> = {
    active: 'green',
    probation: 'amber',
    inactive: 'grey',
    resigned: 'grey',
    terminated: 'red',
};

/** A two-column grid of fields (one column on a phone). */
function Grid({ children }: { children: ReactNode }) {
    return <div className="grid gap-5 sm:grid-cols-2">{children}</div>;
}

function EmployeeFormPage({
    employee,
    manager: initialManager,
    departments,
    designations,
    genders,
    employmentTypes,
    statusOptions,
    roleOptions,
    roleLocked,
    can,
}: Props) {
    const editing = employee !== null;

    const form = useForm({
        first_name: employee?.first_name ?? '',
        last_name: employee?.last_name ?? '',
        employee_code: employee?.code ?? '',
        email: employee?.email ?? '',
        phone: employee?.phone ?? '',
        dob: employee?.dob ?? '',
        gender: employee?.gender ?? '',
        cnic: formatCnic(employee?.cnic ?? ''),
        department_id: employee ? String(employee.department_id) : '',
        designation_id: employee ? String(employee.designation_id) : '',
        manager_id: employee?.manager_id ? String(employee.manager_id) : '',
        joining_date: employee?.joining_date ?? '',
        probation_end_date: employee?.probation_end_date ?? '',
        employment_type: employee?.employment_type ?? 'full_time',
        status: employee?.status ?? 'active',
        device_user_id: employee?.device_user_id ?? '',
        role: employee?.role ?? 'employee',
        address: employee?.address ?? '',
        emergency_name: employee?.emergency_name ?? '',
        emergency_phone: employee?.emergency_phone ?? '',
        bank_name: employee?.bank_name ?? '',
        account_title: employee?.account_title ?? '',
        account_number: employee?.account_number ?? '',
    });

    const [tab, setTab] = useState<FormTabKey>('basic');
    const [manager, setManager] = useState<ManagerOption | null>(
        initialManager,
    );
    const [changingStatus, setChangingStatus] = useState(false);
    const { data, errors } = form;

    useUnsavedChangesGuard(form.isDirty);

    const set = (key: keyof typeof data, value: string) =>
        form.setData((current) => ({ ...current, [key]: value }));

    const text = (key: keyof typeof data, hint?: boolean) => ({
        ...fieldAria(`employee-${key}`, errors[key], hint),
        value: data[key],
        onChange: (e: { target: { value: string } }) =>
            set(key, e.target.value),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((values) => {
            const body: Record<string, string> = { ...values };

            // These are read-only here: the status has its own control, the code is Admin only, and a locked role is
            // never sent (the server would answer 403 to a change; sending nothing means "keep it").
            if (editing) {
                delete body.status;
            }
            if (!can.editCode) {
                delete body.employee_code;
            }
            if (roleLocked) {
                delete body.role;
            }

            return body;
        });

        const options = {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
            onError: (formErrors: Record<string, string>) => {
                const target = firstTabWithError(formErrors);

                if (target) {
                    setTab(target);
                }
            },
        };

        if (editing) {
            form.submit(update(employee.id), options);
        } else {
            form.submit(store(), options);
        }
    };

    const departmentDesignations = designationsFor(
        designations,
        data.department_id,
    );

    const withErrors = tabsWithErrors(errors);

    const fullName = `${data.first_name} ${data.last_name}`.trim();

    const basic = (
        <div className="grid gap-5">
            <div className="flex items-center gap-4">
                <Avatar name={fullName} size="xl" />
                <p className="text-sm text-muted-foreground">
                    The photo shows the initials until photo upload is added in
                    a later update.
                </p>
            </div>
            <Grid>
                <Field
                    id="employee-first_name"
                    label="First name"
                    error={errors.first_name}
                >
                    <Input
                        {...text('first_name')}
                        maxLength={100}
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="employee-last_name"
                    label="Last name"
                    error={errors.last_name}
                >
                    <Input
                        {...text('last_name')}
                        maxLength={100}
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="employee-employee_code"
                    label="Employee ID"
                    error={errors.employee_code}
                    hint={
                        can.editCode
                            ? editing
                                ? 'Format LD-0001. Changing it affects reports.'
                                : 'Leave empty to assign the next one automatically.'
                            : editing
                              ? 'Assigned automatically. Only an Admin can change it.'
                              : 'Assigned automatically when you save.'
                    }
                >
                    {can.editCode ? (
                        <Input
                            {...fieldAria(
                                'employee-employee_code',
                                errors.employee_code,
                                true,
                            )}
                            value={data.employee_code}
                            placeholder={editing ? undefined : 'LD-0001'}
                            onChange={(e) =>
                                set('employee_code', e.target.value)
                            }
                            maxLength={20}
                            autoComplete="off"
                        />
                    ) : (
                        <Input
                            {...fieldAria(
                                'employee-employee_code',
                                errors.employee_code,
                                true,
                            )}
                            value={employee?.code ?? ''}
                            placeholder="Automatic"
                            readOnly
                        />
                    )}
                </Field>
                <Field
                    id="employee-email"
                    label="Work email"
                    error={errors.email}
                    hint="This is also the login. A link to set a password is emailed when the account is created."
                >
                    <Input
                        {...fieldAria('employee-email', errors.email, true)}
                        type="email"
                        value={data.email}
                        onChange={(e) => set('email', e.target.value)}
                        maxLength={255}
                        autoComplete="off"
                    />
                </Field>
                <Field id="employee-phone" label="Phone" error={errors.phone}>
                    <Input
                        {...text('phone')}
                        type="tel"
                        maxLength={30}
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="employee-dob"
                    label="Date of birth"
                    error={errors.dob}
                >
                    <Input {...text('dob')} type="date" />
                </Field>
                <Field
                    id="employee-gender"
                    label="Gender"
                    error={errors.gender}
                >
                    <Select
                        value={data.gender === '' ? NONE : data.gender}
                        onValueChange={(value) =>
                            set('gender', value === NONE ? '' : value)
                        }
                    >
                        <SelectTrigger
                            {...fieldAria('employee-gender', errors.gender)}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={NONE}>Not specified</SelectItem>
                            {genders.map((gender) => (
                                <SelectItem key={gender} value={gender}>
                                    {label(gender)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    id="employee-cnic"
                    label="CNIC"
                    error={errors.cnic}
                    hint="13 digits. Dashes are added for you."
                >
                    <Input
                        {...fieldAria('employee-cnic', errors.cnic, true)}
                        inputMode="numeric"
                        value={data.cnic}
                        onChange={(e) =>
                            set('cnic', formatCnic(e.target.value))
                        }
                        maxLength={15}
                        placeholder="12345-1234567-1"
                        autoComplete="off"
                    />
                </Field>
            </Grid>
        </div>
    );

    const employment = (
        <Grid>
            <Field
                id="employee-department_id"
                label="Department"
                error={errors.department_id}
            >
                <Select
                    value={data.department_id}
                    onValueChange={(value) => {
                        form.setData((current) => ({
                            ...current,
                            department_id: value,
                            // A designation belongs to one department.
                            designation_id: designationsFor(
                                designations,
                                value,
                            ).some(
                                (d) => String(d.id) === current.designation_id,
                            )
                                ? current.designation_id
                                : '',
                        }));
                    }}
                >
                    <SelectTrigger
                        {...fieldAria(
                            'employee-department_id',
                            errors.department_id,
                        )}
                        className="w-full"
                    >
                        <SelectValue placeholder="Select a department" />
                    </SelectTrigger>
                    <SelectContent>
                        {departments.map((department) => (
                            <SelectItem
                                key={department.id}
                                value={String(department.id)}
                            >
                                {department.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                id="employee-designation_id"
                label="Designation"
                error={errors.designation_id}
                hint={
                    data.department_id === ''
                        ? 'Choose a department first.'
                        : undefined
                }
            >
                <Select
                    value={data.designation_id}
                    onValueChange={(value) => set('designation_id', value)}
                    disabled={data.department_id === ''}
                >
                    <SelectTrigger
                        {...fieldAria(
                            'employee-designation_id',
                            errors.designation_id,
                            data.department_id === '',
                        )}
                        className="w-full"
                    >
                        <SelectValue placeholder="Select a designation" />
                    </SelectTrigger>
                    <SelectContent>
                        {departmentDesignations.map((designation) => (
                            <SelectItem
                                key={designation.id}
                                value={String(designation.id)}
                            >
                                {designation.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                id="employee-manager_id"
                label="Reports to"
                error={errors.manager_id}
                hint="Type to search. People below this employee are not offered."
            >
                <ManagerPicker
                    id="employee-manager_id"
                    value={manager}
                    excludeId={employee?.id ?? null}
                    error={errors.manager_id}
                    aria-describedby={
                        errors.manager_id
                            ? 'employee-manager_id-hint employee-manager_id-error'
                            : 'employee-manager_id-hint'
                    }
                    onChange={(picked) => {
                        setManager(picked);
                        set('manager_id', picked ? String(picked.id) : '');
                    }}
                />
            </Field>
            <Field
                id="employee-employment_type"
                label="Employment type"
                error={errors.employment_type}
            >
                <Select
                    value={data.employment_type}
                    onValueChange={(value) => set('employment_type', value)}
                >
                    <SelectTrigger
                        {...fieldAria(
                            'employee-employment_type',
                            errors.employment_type,
                        )}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {employmentTypes.map((type) => (
                            <SelectItem key={type} value={type}>
                                {label(type)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field
                id="employee-joining_date"
                label="Joining date"
                error={errors.joining_date}
            >
                <Input {...text('joining_date')} type="date" />
            </Field>
            <Field
                id="employee-probation_end_date"
                label="Probation end date"
                error={errors.probation_end_date}
            >
                <Input
                    {...text('probation_end_date')}
                    type="date"
                    min={data.joining_date || undefined}
                />
            </Field>
            {editing ? (
                <div className="grid content-start gap-2">
                    <span className="text-sm leading-none font-medium">
                        Status
                    </span>
                    <div className="flex flex-wrap items-center gap-3">
                        <StatusPill
                            tone={STATUS_TONE[employee.status] ?? 'grey'}
                        >
                            {STATUS_LABELS[employee.status] ?? employee.status}
                        </StatusPill>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setChangingStatus(true)}
                        >
                            Change status
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {employee.has_login
                            ? employee.login_active
                                ? 'Login is on.'
                                : 'Login is off.'
                            : 'No login account yet. Saving this form creates one.'}
                        {employee.last_working_day &&
                            ` Last working day ${employee.last_working_day}.`}
                    </p>
                </div>
            ) : (
                <Field
                    id="employee-status"
                    label="Status"
                    error={errors.status}
                >
                    <Select
                        value={data.status}
                        onValueChange={(value) => set('status', value)}
                    >
                        <SelectTrigger
                            {...fieldAria('employee-status', errors.status)}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {statusOptions.map((status) => (
                                <SelectItem key={status} value={status}>
                                    {STATUS_LABELS[status] ?? status}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
            )}
            <Field
                id="employee-device_user_id"
                label="Machine user ID"
                error={errors.device_user_id}
                hint="The ID the attendance machine knows this person by."
            >
                <Input
                    {...text('device_user_id', true)}
                    maxLength={20}
                    autoComplete="off"
                />
            </Field>
            <Field
                id="employee-role"
                label="Role"
                error={errors.role}
                hint={
                    roleLocked
                        ? 'This role cannot be changed here: only an Admin can change an Admin or HR role, and nobody changes their own.'
                        : 'What this person can do after signing in.'
                }
            >
                <Select
                    value={data.role}
                    onValueChange={(value) => set('role', value)}
                    disabled={roleLocked}
                >
                    <SelectTrigger
                        {...fieldAria('employee-role', errors.role, true)}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {roleOptions.map((role) => (
                            <SelectItem key={role.value} value={role.value}>
                                {role.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
        </Grid>
    );

    const contact = (
        <Grid>
            <Field
                id="employee-address"
                label="Address"
                error={errors.address}
                className="grid content-start gap-2 sm:col-span-2"
            >
                <Textarea {...text('address')} maxLength={1000} rows={3} />
            </Field>
            <Field
                id="employee-emergency_name"
                label="Emergency contact name"
                error={errors.emergency_name}
            >
                <Input
                    {...text('emergency_name')}
                    maxLength={100}
                    autoComplete="off"
                />
            </Field>
            <Field
                id="employee-emergency_phone"
                label="Emergency contact phone"
                error={errors.emergency_phone}
            >
                <Input
                    {...text('emergency_phone')}
                    type="tel"
                    maxLength={30}
                    autoComplete="off"
                />
            </Field>
        </Grid>
    );

    const banking = (
        <Grid>
            <Field
                id="employee-bank_name"
                label="Bank name"
                error={errors.bank_name}
            >
                <Input
                    {...text('bank_name')}
                    maxLength={100}
                    autoComplete="off"
                />
            </Field>
            <Field
                id="employee-account_title"
                label="Account title"
                error={errors.account_title}
            >
                <Input
                    {...text('account_title')}
                    maxLength={100}
                    autoComplete="off"
                />
            </Field>
            <Field
                id="employee-account_number"
                label="Account number / IBAN"
                error={errors.account_number}
                className="grid content-start gap-2 sm:col-span-2"
            >
                <Input
                    {...text('account_number')}
                    maxLength={50}
                    autoComplete="off"
                />
            </Field>
        </Grid>
    );

    const documents = (
        <p className="text-sm text-muted-foreground">
            {editing
                ? 'Documents are added in the next update.'
                : 'You can add documents after the employee is saved.'}
        </p>
    );

    return (
        <>
            <Head title={editing ? 'Edit Employee' : 'Add Employee'} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={editing ? employee.name : 'Add Employee'}
                    subtitle={
                        editing
                            ? `Employee ID ${employee.code}`
                            : 'Fill in the tabs, then save once. The new employee gets a login.'
                    }
                />

                {!can.edit && (
                    <div
                        role="note"
                        className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200"
                    >
                        This person has an Admin or HR login, so only an Admin
                        can edit this record. That includes your own record if
                        you are HR.
                    </div>
                )}

                <form
                    onSubmit={submit}
                    noValidate
                    className="overflow-hidden rounded-xl border bg-card shadow-xs"
                >
                    <FormTabs
                        label="Employee details"
                        idPrefix="employee-form"
                        value={tab}
                        onChange={(key) => setTab(key as FormTabKey)}
                        tabs={[
                            {
                                key: 'basic',
                                label: 'Basic',
                                hasError: withErrors.includes('basic'),
                                content: basic,
                            },
                            {
                                key: 'employment',
                                label: 'Employment',
                                hasError: withErrors.includes('employment'),
                                content: employment,
                            },
                            {
                                key: 'contact',
                                label: 'Contact',
                                hasError: withErrors.includes('contact'),
                                content: contact,
                            },
                            {
                                key: 'banking',
                                label: 'Banking',
                                hasError: withErrors.includes('banking'),
                                content: banking,
                            },
                            {
                                key: 'documents',
                                label: 'Documents',
                                content: documents,
                            },
                        ]}
                    />
                    <div className="flex items-center justify-end gap-3 border-t bg-muted/40 px-5 py-3">
                        {form.isDirty && !form.processing && (
                            <span className="mr-auto text-sm text-muted-foreground">
                                Unsaved changes
                            </span>
                        )}
                        <Button variant="outline" asChild>
                            <Link href={index()}>Cancel</Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !can.edit}
                        >
                            {form.processing && <Spinner />}
                            {editing ? 'Save Changes' : 'Add Employee'}
                        </Button>
                    </div>
                </form>
            </div>

            {editing && changingStatus && (
                <StatusDialog
                    employee={employee}
                    statuses={statusOptions}
                    onClose={() => setChangingStatus(false)}
                />
            )}
        </>
    );
}

/** Remounts when the page moves from "add" to the saved employee's edit page, so the form starts from the saved record. */
export default function EmployeeForm(props: Props) {
    return <EmployeeFormPage key={props.employee?.id ?? 'new'} {...props} />;
}

EmployeeForm.layout = {
    breadcrumbs: [
        { title: 'Employees', href: index() },
        { title: 'Employee details', href: create() },
    ],
};
