import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Briefcase,
    Building2,
    Cake,
    CalendarDays,
    FileText,
    Hash,
    Mail,
    Pencil,
    Phone,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { ChangeStatusButton } from '@/components/employees/change-status-button';
import { DeleteEmployeeDialog } from '@/components/employees/delete-employee-dialog';
import {
    STATUS_LABELS,
    STATUS_TONE,
} from '@/components/employees/status-dialog';
import { Avatar } from '@/components/hrm/avatar';
import { FieldList } from '@/components/hrm/field-list';
import { PageHeader } from '@/components/hrm/page-header';
import { SegmentedTabs } from '@/components/hrm/segmented-tabs';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import {
    EMPTY_VALUE,
    fileSize,
    isEmptyValue,
    visibleTabs,
    wordFor,
} from '@/lib/employee-profile';
import type { ProfileTabKey } from '@/lib/employee-profile';
import { formatDate } from '@/lib/format-date';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { edit, index, show } from '@/routes/employees';

type Person = { id: number; name: string; code: string; designation: string };

type Employment = {
    department: string;
    designation: string;
    manager: { id: number | null; name: string } | null;
    joining_date: string;
    probation_end_date: string | null;
    employment_type: string;
    status: string;
    last_working_day: string | null;
    reports: Person[];
    /** Only for Admin and HR. */
    device_user_id?: string | null;
    role?: string | null;
    login?: 'active' | 'disabled' | 'none';
};

/** A group the viewer may not see is missing from the object, not empty. */
type Profile = {
    id: number;
    code: string;
    name: string;
    photo_url: string | null;
    basic?: {
        first_name: string;
        last_name: string;
        email: string;
        phone: string | null;
        gender: string | null;
    };
    identity?: { cnic: string | null; dob: string | null };
    employment?: Employment;
    contact?: {
        address: string | null;
        emergency_name: string | null;
        emergency_phone: string | null;
    };
    banking?: {
        bank_name: string | null;
        account_title: string | null;
        account_number: string | null;
    };
    documents?: {
        items: {
            id: number;
            type: string;
            title: string;
            expiry_date: string | null;
            size_bytes: number;
        }[];
    };
};

type Props = {
    employee: Profile;
    tabs: string[];
    statusOptions: string[];
    can: { edit: boolean; changeStatus: boolean; delete: boolean };
};

const ID_PREFIX = 'employee-profile';

/** One row of the left card: an icon, a small label and the value. Rows without a value are left out. */
function InfoRow({
    icon: Icon,
    label,
    children,
}: {
    icon: typeof Mail;
    label: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-start gap-3">
            <Icon
                aria-hidden="true"
                className="mt-0.5 size-4 shrink-0 text-muted-foreground"
            />
            <div className="min-w-0">
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="text-sm font-medium break-words">
                    {children}
                </div>
            </div>
        </li>
    );
}

function Card({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="rounded-xl border bg-card p-4 shadow-xs sm:p-6">
            <h2 className="mb-5 text-base font-semibold">{title}</h2>
            {children}
        </section>
    );
}

export default function EmployeeProfile({
    employee,
    tabs,
    statusOptions,
    can,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const date = (value: string | null | undefined) =>
        isEmptyValue(value) ? null : formatDate(value, dateFormat);

    const available = visibleTabs(tabs);
    const [tab, setTab] = useState<ProfileTabKey>(available[0]?.key ?? 'basic');
    const [deleting, setDeleting] = useState(false);

    const { basic, identity, employment, contact, banking, documents } =
        employee;

    const activeTab =
        available.find((item) => item.key === tab) ?? available[0];

    const statusLabel = employment
        ? (STATUS_LABELS[employment.status] ?? employment.status)
        : null;

    const panels: Record<ProfileTabKey, ReactNode> = {
        basic: basic && (
            <FieldList
                items={[
                    { label: 'Employee ID', value: employee.code },
                    { label: 'First name', value: basic.first_name },
                    { label: 'Last name', value: basic.last_name },
                    { label: 'Work email', value: basic.email },
                    { label: 'Phone', value: basic.phone },
                    { label: 'Gender', value: wordFor(basic.gender) },
                    ...(identity
                        ? [
                              { label: 'CNIC', value: identity.cnic },
                              {
                                  label: 'Date of birth',
                                  value: date(identity.dob),
                              },
                          ]
                        : []),
                ]}
            />
        ),
        employment: employment && (
            <div className="grid gap-8">
                <FieldList
                    items={[
                        { label: 'Department', value: employment.department },
                        {
                            label: 'Designation',
                            value: employment.designation,
                        },
                        {
                            label: 'Reporting manager',
                            value: employment.manager ? (
                                employment.manager.id !== null ? (
                                    <Link
                                        href={show(employment.manager.id)}
                                        className="text-brand-ink hover:underline"
                                    >
                                        {employment.manager.name}
                                    </Link>
                                ) : (
                                    employment.manager.name
                                )
                            ) : null,
                        },
                        {
                            label: 'Joining date',
                            value: date(employment.joining_date),
                        },
                        {
                            label: 'Probation end date',
                            value: date(employment.probation_end_date),
                        },
                        {
                            label: 'Employment type',
                            value: wordFor(employment.employment_type),
                        },
                        {
                            label: 'Status',
                            value: (
                                <StatusPill
                                    tone={
                                        STATUS_TONE[employment.status] ?? 'grey'
                                    }
                                >
                                    {statusLabel}
                                </StatusPill>
                            ),
                        },
                        ...(employment.last_working_day
                            ? [
                                  {
                                      label: 'Last working day',
                                      value: date(employment.last_working_day),
                                  },
                              ]
                            : []),
                        ...('device_user_id' in employment
                            ? [
                                  {
                                      label: 'Machine user ID',
                                      value: employment.device_user_id,
                                  },
                              ]
                            : []),
                        ...('role' in employment
                            ? [
                                  {
                                      label: 'Role',
                                      value: wordFor(employment.role),
                                  },
                                  {
                                      label: 'Login',
                                      value: wordFor(employment.login),
                                  },
                              ]
                            : []),
                    ]}
                />
                <div>
                    <h3 className="mb-3 text-sm font-semibold">
                        Direct reports
                    </h3>
                    {employment.reports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No direct reports.
                        </p>
                    ) : (
                        <ul role="list" className="divide-y rounded-lg border">
                            {employment.reports.map((person) => (
                                <li
                                    key={person.id}
                                    className="flex items-center gap-3 px-3 py-2.5"
                                >
                                    <Avatar name={person.name} />
                                    <div className="min-w-0 flex-1">
                                        <Link
                                            href={show(person.id)}
                                            className="block truncate text-sm font-medium hover:underline"
                                        >
                                            {person.name}
                                        </Link>
                                        <div className="truncate text-xs text-muted-foreground">
                                            {person.designation}
                                        </div>
                                    </div>
                                    <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                                        {person.code}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        ),
        contact: contact && (
            <FieldList
                items={[
                    { label: 'Address', value: contact.address, wide: true },
                    {
                        label: 'Emergency contact name',
                        value: contact.emergency_name,
                    },
                    {
                        label: 'Emergency contact phone',
                        value: contact.emergency_phone,
                    },
                ]}
            />
        ),
        banking: banking && (
            <FieldList
                items={[
                    { label: 'Bank name', value: banking.bank_name },
                    { label: 'Account title', value: banking.account_title },
                    {
                        label: 'Account number / IBAN',
                        value: banking.account_number,
                        wide: true,
                    },
                ]}
            />
        ),
        documents: documents && (
            <>
                {documents.items.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No documents yet.
                    </p>
                ) : (
                    <ul role="list" className="divide-y rounded-lg border">
                        {documents.items.map((item) => (
                            <li
                                key={item.id}
                                className="flex items-center gap-3 px-3 py-2.5"
                            >
                                <FileText
                                    aria-hidden="true"
                                    className="size-4 shrink-0 text-muted-foreground"
                                />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">
                                        {item.title}
                                    </div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {wordFor(item.type)} ·{' '}
                                        {fileSize(item.size_bytes)}
                                        {item.expiry_date &&
                                            ` · Expires ${date(item.expiry_date)}`}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </>
        ),
    };

    return (
        <>
            <Head title={employee.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={employee.name}
                    subtitle="View detailed information and activity for employee."
                    actions={
                        <>
                            <Button variant="outline" asChild>
                                <Link href={index()}>
                                    <ArrowLeft />
                                    Back
                                </Link>
                            </Button>
                            {can.changeStatus && employment && (
                                <ChangeStatusButton
                                    size="default"
                                    employee={{
                                        id: employee.id,
                                        name: employee.name,
                                        status: employment.status,
                                        last_working_day:
                                            employment.last_working_day,
                                        joining_date: employment.joining_date,
                                    }}
                                    statuses={statusOptions}
                                />
                            )}
                            {can.delete && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="text-destructive"
                                    onClick={() => setDeleting(true)}
                                >
                                    <Trash2 />
                                    Delete
                                </Button>
                            )}
                            {can.edit && (
                                <Button asChild>
                                    <Link href={edit(employee.id)}>
                                        <Pencil />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="rounded-xl border bg-card p-4 shadow-xs sm:p-6">
                    <div className="grid gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
                        <aside className="flex flex-col items-center self-start rounded-xl border p-6 text-center">
                            <Avatar
                                src={employee.photo_url}
                                name={employee.name}
                                className="size-32 text-4xl"
                            />
                            <h2 className="mt-4 w-full text-lg font-bold break-words">
                                {employee.name}
                            </h2>
                            {employment && (
                                <>
                                    <p className="mt-0.5 text-sm text-muted-foreground">
                                        {employment.designation}
                                    </p>
                                    <StatusPill
                                        tone={
                                            STATUS_TONE[employment.status] ??
                                            'grey'
                                        }
                                        className="mt-3"
                                    >
                                        {statusLabel}
                                    </StatusPill>
                                </>
                            )}
                            <ul
                                role="list"
                                className="mt-6 grid w-full gap-4 border-t pt-6 text-left"
                            >
                                <InfoRow icon={Hash} label="Employee ID">
                                    {employee.code}
                                </InfoRow>
                                {basic && (
                                    <>
                                        <InfoRow icon={Mail} label="Email">
                                            {basic.email}
                                        </InfoRow>
                                        <InfoRow icon={Phone} label="Phone">
                                            {isEmptyValue(basic.phone)
                                                ? EMPTY_VALUE
                                                : basic.phone}
                                        </InfoRow>
                                    </>
                                )}
                                {employment && (
                                    <>
                                        <InfoRow
                                            icon={CalendarDays}
                                            label="Joined"
                                        >
                                            {date(employment.joining_date) ||
                                                EMPTY_VALUE}
                                        </InfoRow>
                                        <InfoRow
                                            icon={Building2}
                                            label="Department"
                                        >
                                            {employment.department}
                                        </InfoRow>
                                        <InfoRow
                                            icon={Briefcase}
                                            label="Employment type"
                                        >
                                            {wordFor(
                                                employment.employment_type,
                                            ) ?? EMPTY_VALUE}
                                        </InfoRow>
                                    </>
                                )}
                                {identity && (
                                    <InfoRow icon={Cake} label="Date of birth">
                                        {date(identity.dob) || EMPTY_VALUE}
                                    </InfoRow>
                                )}
                            </ul>
                        </aside>

                        <div className="grid min-w-0 content-start gap-4">
                            <SegmentedTabs
                                label="Employee information"
                                idPrefix={ID_PREFIX}
                                value={activeTab?.key ?? ''}
                                onChange={(key) => setTab(key as ProfileTabKey)}
                                tabs={available.map(({ key, label }) => ({
                                    key,
                                    label,
                                }))}
                            />
                            {activeTab && (
                                <div
                                    id={statusPanelId(ID_PREFIX)}
                                    role="tabpanel"
                                    aria-labelledby={statusTabId(
                                        ID_PREFIX,
                                        activeTab.key,
                                    )}
                                >
                                    <Card title={activeTab.title}>
                                        {panels[activeTab.key]}
                                    </Card>
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            <DeleteEmployeeDialog
                employee={
                    deleting ? { id: employee.id, name: employee.name } : null
                }
                onClose={() => setDeleting(false)}
            />
        </>
    );
}

EmployeeProfile.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Employees', href: index() },
        { title: props.employee.name, href: show(props.employee.id) },
    ],
});
