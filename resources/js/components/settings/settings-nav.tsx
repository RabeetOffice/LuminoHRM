import { Link } from '@inertiajs/react';
import {
    Building2,
    CalendarCheck,
    Clock,
    FileText,
    Fingerprint,
    Mail,
    Palette,
    Wallet,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useCan } from '@/hooks/use-can';
import { cn } from '@/lib/utils';
import { index } from '@/routes/hrm-settings';
import { index as devicesIndex } from '@/routes/hrm-settings/devices';
import type { SettingsGroup } from '@/types/hrm-settings';

export const SETTINGS_GROUPS: Record<
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
        description:
            'Start date, repeat punches, day cutoff, late rule and overtime.',
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

/** The ZKTeco Device page: its own screen, listed with the groups (Admin only). */
export const DEVICES_ENTRY = {
    label: 'ZKTeco Device',
    description:
        'Attendance machines, their status, unknown user IDs and the sync log.',
    icon: Fingerprint,
};

const linkClass = (current: boolean) =>
    cn(
        'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium whitespace-nowrap transition-colors',
        'focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
        current
            ? 'bg-sidebar-accent text-sidebar-accent-foreground'
            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
    );

/**
 * The left-hand list of the Settings screen: the groups this user may open, then ZKTeco Device for those who manage
 * settings. `active` is the open group, or 'devices'.
 */
export function SettingsNav({
    groups,
    active,
}: {
    groups: SettingsGroup[];
    active: SettingsGroup | 'devices';
}) {
    const canManageDevices = useCan()('settings.manage');

    return (
        <nav
            aria-label="Setting groups"
            className="-mx-4 overflow-x-auto px-4 lg:mx-0 lg:overflow-visible lg:px-0"
        >
            <ul className="flex gap-1 lg:flex-col">
                {groups.map((group) => {
                    const item = SETTINGS_GROUPS[group];
                    const current = group === active;

                    return (
                        <li key={group} className="shrink-0">
                            <Link
                                href={index({ query: { group } })}
                                preserveScroll
                                aria-current={current ? 'page' : undefined}
                                className={linkClass(current)}
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
                {canManageDevices && (
                    <li className="shrink-0">
                        <Link
                            href={devicesIndex()}
                            preserveScroll
                            aria-current={
                                active === 'devices' ? 'page' : undefined
                            }
                            className={linkClass(active === 'devices')}
                        >
                            <DEVICES_ENTRY.icon
                                className="size-4 shrink-0"
                                aria-hidden
                            />
                            {DEVICES_ENTRY.label}
                        </Link>
                    </li>
                )}
            </ul>
        </nav>
    );
}
