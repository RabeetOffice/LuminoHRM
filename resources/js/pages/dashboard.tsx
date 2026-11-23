import { Head, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/hrm/page-header';
import { useCan } from '@/hooks/use-can';
import {
    dashboardTiles,
    formatLongDate,
    greetingFor,
    hourIn,
    tileTones,
} from '@/lib/dashboard';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';

// Placeholder frame: real figures arrive with the attendance, leave, payroll
// and asset modules. Until then every tile shows a dash.
const cards = [
    {
        title: 'Attendance, last 7 days',
        note: 'Daily attendance appears here.',
    },
    { title: 'Leave overview', note: 'Leave requests by status appear here.' },
];

export default function Dashboard() {
    const { auth, app } = usePage().props;
    const can = useCan();
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? '';
    const today = formatLongDate(now, app.timezone);
    const tiles = dashboardTiles(can('payroll.view'));

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
                        {greetingFor(hourIn(now, app.timezone))}, {firstName}
                    </h2>
                    <p className="mt-1 text-sm text-banner-foreground/70">
                        {today}
                    </p>
                </section>

                <section
                    aria-label="Key figures"
                    className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-6"
                >
                    {tiles.map(({ label, icon: Icon, color }) => (
                        <div
                            key={label}
                            className={cn(
                                'rounded-xl border p-4 shadow-xs',
                                tileTones[color].tile,
                            )}
                        >
                            <div
                                className={cn(
                                    'flex size-10 items-center justify-center rounded-lg',
                                    tileTones[color].icon,
                                )}
                            >
                                <Icon className="size-5" aria-hidden="true" />
                            </div>
                            <p className="mt-3 text-sm text-muted-foreground">
                                {label}
                            </p>
                            <p className="mt-1 text-2xl font-semibold">
                                <span aria-label="No data yet">—</span>
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                No data yet
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
