import { Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Briefcase,
    Building2,
    Cake,
    CalendarDays,
    Hash,
    Mail,
    Phone,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { AssetsPanel } from '@/components/assets/assets-panel';
import type { ProfileAssets } from '@/components/assets/assets-panel';
import { DocumentsPanel } from '@/components/employees/documents-panel';
import type { EmployeeDocumentItem } from '@/components/employees/documents-panel';
import { LeavePanel } from '@/components/employees/leave-panel';
import type { ProfileLeave } from '@/components/employees/leave-panel';
import {
    STATUS_LABELS,
    STATUS_TONE,
} from '@/components/employees/status-dialog';
import { Avatar } from '@/components/hrm/avatar';
import { FieldList } from '@/components/hrm/field-list';
import { SegmentedTabs } from '@/components/hrm/segmented-tabs';
import { StatusPill } from '@/components/hrm/status-pill';
import {
    EMPTY_VALUE,
    isEmptyValue,
    visibleTabs,
    wordFor,
} from '@/lib/employee-profile';
import type { ProfileTabKey } from '@/lib/employee-profile';
import { formatDate } from '@/lib/format-date';
import { formatMinutes } from '@/lib/minutes';
import { formatMoney, monthLabel } from '@/lib/payroll';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { monthly } from '@/routes/attendance';
import { show } from '@/routes/employees';
import { attendance as myAttendance } from '@/routes/my';
import { show as salaryShow } from '@/routes/payroll/salary-profiles';

type Person = { id: number; name: string; code: string; designation: string };

export type EmployeeEmployment = {
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

/**
 * An employee as EmployeeProfileResource sends it. A group the viewer may not see is missing from the object, not
 * empty.
 */
export type EmployeeProfile = {
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
    employment?: EmployeeEmployment;
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
    documents?: { items: EmployeeDocumentItem[] };
    /** Only for viewers allowed to see this person's attendance (the person, view-all, view-team for a report). */
    attendance?: ProfileAttendance;
    /** Only for the person, leave.approve-all, and leave.approve-team for a direct report. */
    leave?: ProfileLeave;
    /** Only for payroll.view viewers (Admin, HR); never a Manager or an Employee, not even on their own record. */
    salary?: ProfileSalary;
    /** What the person holds and the last returned; cost only with assets.assign. */
    assets?: ProfileAssets;
};

/** One salary profile on the Salary tab. Money in paisa. */
export type ProfileSalaryProfile = {
    id: number;
    effective_from: string;
    basic: number;
    gross: number;
    taxable_gross: number;
    deductions: number;
    /** "5% of gross taxable", "Rs 2,000 fixed", "None". */
    tax: string;
    note: string | null;
    upcoming?: boolean;
};

/** The Salary tab (Admin and HR only). Money in paisa. */
export type ProfileSalary = {
    code: string;
    current: ProfileSalaryProfile | null;
    history: ProfileSalaryProfile[];
    loans: {
        id: number;
        type: string;
        amount: number;
        installment: number;
        start_month: string;
        remaining: number;
    }[];
    payslips: { id: number; month: string; label: string; net: number }[];
};

/** The current month's attendance summary (MonthGrid totals; pending days never counted, half day = 0.5). */
export type ProfileAttendance = {
    month: string;
    label: string;
    /** The viewer's own record: link to My Attendance instead of the monthly grid. */
    own: boolean;
    /** What the monthly grid link searches for. */
    code: string;
    totals: {
        present: number;
        late: number;
        half_day: number;
        absent: number;
        on_leave: number;
        off: number;
        worked_minutes: number;
        late_minutes: number;
        overtime_minutes: number;
        equivalent: number;
        working_days: number;
    };
};

type Props = {
    employee: EmployeeProfile;
    /** The tab keys the server allows, in order. */
    tabs: string[];
    /** Types for the Documents tab's "Add document" dialog; only used when `canManageDocuments`. */
    documentTypes?: string[];
    /** Upload and delete on the Documents tab. False gives a read-only list (download only). */
    canManageDocuments: boolean;
    /** Prefix for the tab ids (one per page). */
    idPrefix: string;
};

/** One row of the left card: an icon, a small label and the value. */
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

/** The Attendance tab: this month so far, and a link to the full month (My Attendance for one's own record). */
function AttendancePanel({ attendance }: { attendance: ProfileAttendance }) {
    const { totals } = attendance;
    const items: [string, string][] = [
        ['Present', String(totals.present)],
        ['Late', String(totals.late)],
        ['Half day', String(totals.half_day)],
        ['Absent', String(totals.absent)],
        ['Worked', formatMinutes(totals.worked_minutes) || '0h'],
        ['Late minutes', formatMinutes(totals.late_minutes) || '0m'],
    ];
    const href = attendance.own
        ? myAttendance()
        : monthly({
              query: { month: attendance.month, search: attendance.code },
          });

    return (
        <div className="grid gap-5">
            <p className="text-sm text-muted-foreground">
                {attendance.label}:{' '}
                <span className="font-medium text-foreground tabular-nums">
                    {Number.isInteger(totals.equivalent)
                        ? totals.equivalent
                        : totals.equivalent.toFixed(1)}{' '}
                    / {totals.working_days}
                </span>{' '}
                working days present (a half day counts as half).
            </p>
            <dl className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                {items.map(([label, value]) => (
                    <div key={label} className="rounded-lg border p-3">
                        <dt className="text-xs text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="mt-0.5 text-lg font-semibold tabular-nums">
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
            <div>
                <Link
                    href={href}
                    className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
                >
                    {attendance.own
                        ? 'Open My Attendance'
                        : 'Open the monthly attendance'}
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
        </div>
    );
}

/** A small list of label / value lines in a bordered box. */
function LineList({ lines }: { lines: [number, ReactNode, ReactNode][] }) {
    return (
        <ul role="list" className="divide-y rounded-lg border">
            {lines.map(([key, label, value]) => (
                <li
                    key={key}
                    className="flex flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-3 py-2 text-sm"
                >
                    <span>{label}</span>
                    <span className="text-muted-foreground tabular-nums">
                        {value}
                    </span>
                </li>
            ))}
        </ul>
    );
}

/** The Salary tab (Admin and HR only): current salary, recent history, open loans and the last payslips. */
function SalaryPanel({
    employeeId,
    salary,
    date,
}: {
    employeeId: number;
    salary: ProfileSalary;
    date: (value: string) => string | null;
}) {
    const { current } = salary;
    const linkClass =
        'inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline';
    const figures: [string, number][] = current
        ? [
              ['Basic', current.basic],
              ['Gross', current.gross],
              ['Taxable', current.taxable_gross],
              ['Fixed deductions', current.deductions],
          ]
        : [];

    return (
        <div className="grid gap-6">
            {current === null ? (
                <p className="text-sm text-muted-foreground">
                    No salary yet, so payroll cannot pay this person.
                </p>
            ) : (
                <div className="grid gap-3">
                    <p className="text-sm text-muted-foreground">
                        {current.upcoming ? 'Starts' : 'In force since'}{' '}
                        {date(current.effective_from)} · Tax: {current.tax}
                    </p>
                    <dl className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        {figures.map(([label, value]) => (
                            <div key={label} className="rounded-lg border p-3">
                                <dt className="text-xs text-muted-foreground">
                                    {label}
                                </dt>
                                <dd className="mt-0.5 font-semibold tabular-nums">
                                    {formatMoney(value)}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </div>
            )}

            {salary.history.length > 1 && (
                <div>
                    <h3 className="mb-2 text-sm font-semibold">
                        Salary history
                    </h3>
                    <LineList
                        lines={salary.history.map((row) => [
                            row.id,
                            `From ${date(row.effective_from)}`,
                            `Gross ${formatMoney(row.gross)}`,
                        ])}
                    />
                </div>
            )}

            <div>
                <h3 className="mb-2 text-sm font-semibold">
                    Loans and advances
                </h3>
                {salary.loans.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        Nothing outstanding.
                    </p>
                ) : (
                    <LineList
                        lines={salary.loans.map((loan) => [
                            loan.id,
                            `${loan.type === 'advance' ? 'Advance' : 'Loan'} of ${formatMoney(loan.amount)} from ${monthLabel(loan.start_month)}`,
                            `${formatMoney(loan.remaining)} left · ${formatMoney(loan.installment)} a month`,
                        ])}
                    />
                )}
            </div>

            <div>
                <h3 className="mb-2 text-sm font-semibold">Recent payslips</h3>
                {salary.payslips.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No approved payroll yet.
                    </p>
                ) : (
                    <LineList
                        lines={salary.payslips.map((slip) => [
                            slip.id,
                            slip.label,
                            `Net ${formatMoney(slip.net)}`,
                        ])}
                    />
                )}
            </div>

            <div className="flex flex-wrap gap-x-6 gap-y-2">
                <Link href={salaryShow(employeeId)} className={linkClass}>
                    Open salary profiles
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
                <Link
                    href={`/payroll/loans?search=${encodeURIComponent(salary.code)}`}
                    className={linkClass}
                >
                    Open loans and advances
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
        </div>
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

/**
 * The body of an employee profile: the left card (photo, name, status, key facts) and the tabbed field groups.
 * Used by the profile page (employees/show) and, read-only, by My Profile (my/profile). Page header and actions stay
 * with the page.
 */
export function EmployeeProfileView({
    employee,
    tabs,
    documentTypes = [],
    canManageDocuments,
    idPrefix,
}: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;
    const date = (value: string | null | undefined) =>
        isEmptyValue(value) ? null : formatDate(value, dateFormat);

    const available = visibleTabs(tabs);
    const [tab, setTab] = useState<ProfileTabKey>(available[0]?.key ?? 'basic');

    const {
        basic,
        identity,
        employment,
        contact,
        banking,
        documents,
        attendance,
        leave,
        salary,
        assets,
    } = employee;

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
            <DocumentsPanel
                employeeId={employee.id}
                documents={documents.items}
                types={documentTypes}
                canManage={canManageDocuments}
            />
        ),
        attendance: attendance && <AttendancePanel attendance={attendance} />,
        leave: leave && <LeavePanel leave={leave} />,
        salary: salary && (
            <SalaryPanel employeeId={employee.id} salary={salary} date={date} />
        ),
        assets: assets && <AssetsPanel assets={assets} date={date} />,
    };

    return (
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
                                tone={STATUS_TONE[employment.status] ?? 'grey'}
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
                                <InfoRow icon={CalendarDays} label="Joined">
                                    {date(employment.joining_date) ||
                                        EMPTY_VALUE}
                                </InfoRow>
                                <InfoRow icon={Building2} label="Department">
                                    {employment.department}
                                </InfoRow>
                                <InfoRow
                                    icon={Briefcase}
                                    label="Employment type"
                                >
                                    {wordFor(employment.employment_type) ??
                                        EMPTY_VALUE}
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
                        idPrefix={idPrefix}
                        value={activeTab?.key ?? ''}
                        onChange={(key) => setTab(key as ProfileTabKey)}
                        tabs={available.map(({ key, label }) => ({
                            key,
                            label,
                        }))}
                    />
                    {activeTab && (
                        <div
                            id={statusPanelId(idPrefix)}
                            role="tabpanel"
                            aria-labelledby={statusTabId(
                                idPrefix,
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
    );
}
