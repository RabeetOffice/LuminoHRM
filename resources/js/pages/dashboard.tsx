import { Head, Link, usePage } from '@inertiajs/react';
import { Avatar } from '@/components/hrm/avatar';
import { PageHeader } from '@/components/hrm/page-header';
import { useCan } from '@/hooks/use-can';
import {
    dashboardTiles,
    formatLongDate,
    greetingFor,
    hourIn,
    probationNote,
    tileTones,
} from '@/lib/dashboard';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show } from '@/routes/employees';

/** Sent only to people who may see employees (Admin, HR: the company; Manager: their team). */
type EmployeeCount = {
    scope: 'company' | 'team';
    total: number;
    probation: number;
};

/** Sent only to Admin and HR. Never a date: only the fact that today is their birthday. */
type Birthday = {
    id: number;
    name: string;
    designation: string | null;
    photo_url: string | null;
};

type Props = {
    employeeCount?: EmployeeCount;
    birthdays?: Birthday[];
};

// The employee tile is real; the other figures arrive with the attendance, leave,
// payroll and asset modules. Until then those tiles show a dash.
const cards = [
    {
        title: 'Attendance, last 7 days',
        note: 'Daily attendance appears here.',
    },
    { title: 'Leave overview', note: 'Leave requests by status appear here.' },
];

export default function Dashboard({ employeeCount, birthdays }: Props) {
    const { auth, app } = usePage().props;
    const can = useCan();
    const now = new Date();
    const firstName = auth.user.name.trim().split(/\s+/)[0] ?? '';
    const today = formatLongDate(now, app.timezone);
    const tiles = dashboardTiles(
        can('payroll.view'),
        employeeCount?.scope ?? null,
    );

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
                    className={cn(
                        'grid grid-cols-2 gap-4 md:grid-cols-3',
                        tiles.length === 6
                            ? 'xl:grid-cols-6'
                            : 'xl:grid-cols-5',
                    )}
                >
                    {tiles.map(({ key, label, icon: Icon, color }) => (
                        <div
                            key={key}
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
                            {key === 'employees' && employeeCount ? (
                                <>
                                    <p className="mt-1 text-2xl font-semibold tabular-nums">
                                        {employeeCount.total}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {probationNote(employeeCount.probation)}
                                    </p>
                                </>
                            ) : (
                                <>
                                    <p className="mt-1 text-2xl font-semibold">
                                        <span aria-label="No data yet">—</span>
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        No data yet
                                    </p>
                                </>
                            )}
                        </div>
                    ))}
                </section>

                <section
                    className={cn(
                        'grid gap-4 lg:grid-cols-2',
                        birthdays && 'xl:grid-cols-3',
                    )}
                >
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
                    {birthdays && <BirthdaysCard people={birthdays} />}
                </section>
            </div>
        </>
    );
}

/** Admin and HR only: who has a birthday today (in the company's time zone, decided on the server). */
function BirthdaysCard({ people }: { people: Birthday[] }) {
    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <h3 className="border-b px-5 py-4 text-base font-semibold">
                Today's Birthdays
            </h3>
            {people.length === 0 ? (
                <div className="flex flex-1 items-center justify-center p-6 text-sm text-muted-foreground">
                    No birthdays today.
                </div>
            ) : (
                <ul role="list" className="divide-y overflow-y-auto">
                    {people.map((person) => (
                        <li
                            key={person.id}
                            className="flex items-center gap-3 px-5 py-3"
                        >
                            <Avatar
                                src={person.photo_url}
                                name={person.name}
                                size="md"
                            />
                            <div className="min-w-0">
                                <Link
                                    href={show(person.id)}
                                    className="block truncate text-sm font-medium hover:underline"
                                >
                                    {person.name}
                                </Link>
                                {person.designation && (
                                    <div className="truncate text-xs text-muted-foreground">
                                        {person.designation}
                                    </div>
                                )}
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </div>
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
