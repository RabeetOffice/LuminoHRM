import { Head, Link } from '@inertiajs/react';
import {
    Building2,
    CalendarCheck,
    Clock,
    FileText,
    Mail,
    Palette,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { AttendancePanel } from '@/components/settings/attendance-panel';
import { BrandingPanel } from '@/components/settings/branding-panel';
import { CompanyPanel } from '@/components/settings/company-panel';
import { EmailPanel } from '@/components/settings/email-panel';
import { LeavePanel } from '@/components/settings/leave-panel';
import { LettersPanel } from '@/components/settings/letters-panel';
import { PayrollPanel } from '@/components/settings/payroll-panel';
import { PageHeader } from '@/components/hrm/page-header';
import { cn } from '@/lib/utils';
import { index } from '@/routes/hrm-settings';
import type {
    SettingsGroup,
    SettingsOptions,
    SettingsValues,
} from '@/types/hrm-settings';

type Props = {
    /** The groups this user may open, in display order. */
    groups: SettingsGroup[];
    active: SettingsGroup;
    values: SettingsValues;
    options: SettingsOptions;
};

const GROUPS: Record<
    SettingsGroup,
    { label: string; description: string; icon: LucideIcon }
> = {
    company: {
        label: 'Company',
        description: 'Name, address, currency, timezone and date format.',
        icon: Building2,
    },
    branding: {
        label: 'Branding',
        description: 'Accent colour and logos.',
        icon: Palette,
    },
    attendance: {
        label: 'Attendance',
        description: 'Shift, grace period, late and half-day rules, overtime.',
        icon: Clock,
    },
    leave: {
        label: 'Leave',
        description: 'Approval steps, carry-forward and pro-rating.',
        icon: CalendarCheck,
    },
    payroll: {
        label: 'Payroll',
        description: 'Day-pay basis, tax mode, notice period, payslip footer.',
        icon: Wallet,
    },
    email: {
        label: 'Email',
        description: 'SMTP server and sender, with a test email.',
        icon: Mail,
    },
    letters: {
        label: 'Letter templates',
        description: 'Experience letter text and placeholders.',
        icon: FileText,
    },
};

export default function HrmSettings({
    groups,
    active,
    values,
    options,
}: Props) {
    const meta = GROUPS[active];

    return (
        <>
            <Head title={`${meta.label} settings`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Settings"
                    subtitle="Company-wide rules. Each section saves on its own."
                />

                <div className="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <nav
                        aria-label="Setting groups"
                        className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:overflow-visible lg:px-0"
                    >
                        <ul className="flex gap-1 lg:flex-col">
                            {groups.map((group) => {
                                const item = GROUPS[group];
                                const current = group === active;

                                return (
                                    <li key={group} className="shrink-0">
                                        <Link
                                            href={index({ query: { group } })}
                                            preserveScroll
                                            aria-current={
                                                current ? 'page' : undefined
                                            }
                                            className={cn(
                                                'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                                'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                                current
                                                    ? 'bg-sidebar-accent text-sidebar-accent-foreground'
                                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                            )}
                                        >
                                            <item.icon
                                                className="size-4 shrink-0"
                                                aria-hidden
                                            />
                                            {item.label}
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </nav>

                    <div className="grid min-w-0 gap-4">
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                {meta.label}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {meta.description}
                            </p>
                        </div>

                        {/* Keyed by group so each panel starts with fresh form state. */}
                        {active === 'company' && values.company && (
                            <CompanyPanel
                                key="company"
                                values={values.company}
                                options={options}
                            />
                        )}
                        {active === 'branding' && values.branding && (
                            <BrandingPanel
                                key="branding"
                                values={values.branding}
                            />
                        )}
                        {active === 'attendance' && values.attendance && (
                            <AttendancePanel
                                key="attendance"
                                values={values.attendance}
                            />
                        )}
                        {active === 'leave' && values.leave && (
                            <LeavePanel key="leave" values={values.leave} />
                        )}
                        {active === 'payroll' && values.payroll && (
                            <PayrollPanel
                                key="payroll"
                                values={values.payroll}
                            />
                        )}
                        {active === 'email' && values.email && (
                            <EmailPanel key="email" values={values.email} />
                        )}
                        {active === 'letters' && values.letters && (
                            <LettersPanel
                                key="letters"
                                values={values.letters}
                                placeholders={options.placeholders}
                            />
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}

HrmSettings.layout = {
    breadcrumbs: [{ title: 'Settings', href: index() }],
};
