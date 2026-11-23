import { Head, usePage } from '@inertiajs/react';
import {
    CalendarOff,
    Clock,
    ClipboardCheck,
    Laptop,
    UserCheck,
    Users,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { PageHeader } from '@/components/hrm/page-header';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

// Placeholder frame: real figures arrive with the attendance, leave, payroll
// and asset modules. Until then every tile shows a dash.
const tiles: { label: string; icon: LucideIcon; tone: string }[] = [
    {
        label: 'Employees',
        icon: Users,
        tone: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    },
    {
        label: 'Present today',
        icon: UserCheck,
        tone: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
    },
    {
        label: 'On leave',
        icon: CalendarOff,
        tone: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    },
    {
        label: 'Late arrivals',
        icon: Clock,
        tone: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    },
    {
        label: 'Pending approvals',
        icon: ClipboardCheck,
        tone: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    },
    {
        label: 'Assets assigned',
        icon: Laptop,
        tone: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300',
    },
];

const cards = [
    {
        title: 'Attendance, last 7 days',
        note: 'Daily attendance appears here.',
    },
    { title: 'Leave overview', note: 'Leave requests by status appear here.' },
];

function greeting(hour: number): string {
    if (hour < 12) {
        return 'Good morning';
    }

    return hour < 17 ? 'Good afternoon' : 'Good evening';
}

export default function Dashboard() {
    const { auth } = usePage().props;
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? '';
    const today = now.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Dashboard"
                    subtitle="A summary of your workforce today."
                />

                <section className="rounded-xl bg-banner px-6 py-7 text-banner-foreground shadow-xs">
                    <h2 className="text-2xl font-semibold tracking-tight">
                        {greeting(now.getHours())}, {firstName}
                    </h2>
                    <p className="mt-1 text-sm text-banner-foreground/70">
                        {today}
                    </p>
                </section>

                <section
                    aria-label="Key figures"
                    className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
                >
                    {tiles.map(({ label, icon: Icon, tone }) => (
                        <div
                            key={label}
                            className="rounded-xl border bg-card p-4 shadow-xs"
                        >
                            <div
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-lg',
                                    tone,
                                )}
                            >
                                <Icon className="size-5" aria-hidden="true" />
                            </div>
                            <p className="mt-3 text-sm text-muted-foreground">
                                {label}
                            </p>
                            <p
                                className="mt-1 text-2xl font-semibold"
                                aria-label="No data yet"
                            >
                                —
                            </p>
                        </div>
                    ))}
                </section>

                <section className="grid gap-4 lg:grid-cols-2">
                    {cards.map(({ title, note }) => (
                        <div
                            key={title}
                            className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs"
                        >
                            <h3 className="border-b px-5 py-4 text-base font-semibold">
                                {title}
                            </h3>
                            <div className="flex flex-1 items-center justify-center p-6 text-sm text-muted-foreground">
                                {note}
                            </div>
                        </div>
                    ))}
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
