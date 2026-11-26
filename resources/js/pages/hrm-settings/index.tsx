import { Head } from '@inertiajs/react';
import { AttendancePanel } from '@/components/settings/attendance-panel';
import { BrandingPanel } from '@/components/settings/branding-panel';
import { CompanyPanel } from '@/components/settings/company-panel';
import { EmailPanel } from '@/components/settings/email-panel';
import { LeavePanel } from '@/components/settings/leave-panel';
import { LettersPanel } from '@/components/settings/letters-panel';
import { PayrollPanel } from '@/components/settings/payroll-panel';
import { PerformancePanel } from '@/components/settings/performance-panel';
import {
    SETTINGS_GROUPS,
    SettingsNav,
} from '@/components/settings/settings-nav';
import { PageHeader } from '@/components/hrm/page-header';
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

export default function HrmSettings({
    groups,
    active,
    values,
    options,
}: Props) {
    const meta = SETTINGS_GROUPS[active];

    return (
        <>
            <Head title={`${meta.label} settings`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Settings"
                    subtitle="Company-wide rules. Each section saves on its own."
                />

                <div className="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <SettingsNav groups={groups} active={active} />

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
                        {active === 'performance' && values.performance && (
                            <PerformancePanel
                                key="performance"
                                values={values.performance}
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
