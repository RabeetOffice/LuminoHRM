import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/** What the dashboard's "Reviews waiting for you" card receives. */
export type WaitingReviews = {
    /** The person's own reviews waiting for their self-review. */
    self: number;
    /** Reviews they rate. */
    rate: number;
    /** Reviews ready to close (performance.manage only). */
    close: number;
    items: { id: number; label: string; url: string }[];
};

/** Whether anything waits on the viewer (the card shows only then). */
export function hasWaitingReviews(waiting: WaitingReviews | undefined) {
    return !!waiting && waiting.self + waiting.rate + waiting.close > 0;
}

const linkClass =
    'inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline';

function Count({ value, label }: { value: number; label: string }) {
    if (value === 0) {
        return null;
    }

    return (
        <div>
            <p className="text-3xl font-semibold tabular-nums">{value}</p>
            <p className="text-xs text-muted-foreground">{label}</p>
        </div>
    );
}

/** The dashboard card: counts of reviews waiting on the viewer and links to the first few. Nothing renders at zero. */
export function WaitingReviewsCard({
    waiting,
    myReviewsHref,
}: {
    waiting: WaitingReviews;
    myReviewsHref: string;
}) {
    if (!hasWaitingReviews(waiting)) {
        return null;
    }

    return (
        <div className="flex min-h-64 flex-col rounded-xl border bg-card shadow-xs">
            <div className="flex items-center justify-between gap-3 border-b px-5 py-4">
                <h3 className="text-base font-semibold">
                    Reviews waiting for you
                </h3>
                <Link href={myReviewsHref} className={linkClass}>
                    My Reviews
                    <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
            <div className="p-5">
                <div className="flex flex-wrap gap-x-8 gap-y-3">
                    <Count value={waiting.self} label="To write yourself" />
                    <Count value={waiting.rate} label="To rate" />
                    <Count value={waiting.close} label="To close" />
                </div>
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
