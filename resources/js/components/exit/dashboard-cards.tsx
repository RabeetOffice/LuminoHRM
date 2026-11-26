import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { index as exitIndex } from '@/routes/exit';

/** What the dashboard's "Exits waiting for you" card receives (App\Support\Exits\DashboardExits::waiting). */
export type ExitsWaiting = {
    count: number;
    items: { id: number; label: string; url: string }[];
};

/** What the "Upcoming leavers" card receives (DashboardExits::upcoming): names only, up to five. */
export type UpcomingLeavers = {
    count: number;
    names: string[];
    more: number;
};

const linkClass =
    'inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline';

/** The card: how many exits wait on the viewer and links to the first few. Nothing renders at zero. */
export function ExitsWaitingCard({
    waiting,
}: {
    waiting: ExitsWaiting | undefined;
}) {
    if (!waiting || waiting.count === 0) {
        return null;
    }

    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">
                    Exits waiting for you
                </h3>
                <Link href={exitIndex.url()} className={linkClass}>
                    Resignations
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
            <div className="p-5">
                <p className="text-3xl font-semibold tabular-nums">
                    {waiting.count}
                </p>
                <p className="text-xs text-muted-foreground">
                    {waiting.count === 1
                        ? 'exit needs your attention'
                        : 'exits need your attention'}
                </p>
                <ul role="list" className="mt-4 grid gap-1.5 text-sm">
                    {waiting.items.map((item) => (
                        <li key={item.id} className="truncate">
                            <Link href={item.url} className="hover:underline">
                                {item.label}
                            </Link>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/** The card: who is leaving in the next 30 days. Nothing renders when nobody is. */
export function UpcomingLeaversCard({
    leavers,
}: {
    leavers: UpcomingLeavers | undefined;
}) {
    if (!leavers || leavers.count === 0) {
        return null;
    }

    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">Upcoming leavers</h3>
                <Link
                    href={exitIndex.url({ query: { tab: 'open' } })}
                    className={linkClass}
                >
                    Open exits
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
            <div className="p-5">
                <p className="text-3xl font-semibold tabular-nums">
                    {leavers.count}
                </p>
                <p className="text-xs text-muted-foreground">
                    {leavers.count === 1 ? 'person leaves' : 'people leave'} in
                    the next 30 days
                </p>
                <ul role="list" className="mt-4 grid gap-1.5 text-sm">
                    {leavers.names.map((name, index) => (
                        <li key={`${index}-${name}`} className="truncate">
                            {name}
                        </li>
                    ))}
                    {leavers.more > 0 && (
                        <li className="text-muted-foreground">
                            and {leavers.more} more
                        </li>
                    )}
                </ul>
            </div>
        </div>
    );
}
