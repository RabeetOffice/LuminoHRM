import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import {
    balanceCardFigures,
    formatDayAmount,
    formatLeaveDays,
} from '@/lib/leave';
import { cn } from '@/lib/utils';

/** One balance line as the server sends it (BalanceLine::toArray()). Unpaid: unlimited, with nulls. */
export type BalanceLineData = {
    type_id: number;
    type_name: string;
    colour: string;
    year: number;
    is_paid: boolean;
    unlimited: boolean;
    allocated: number;
    carried: number;
    adjustment: number;
    opening_used: number;
    used: number;
    pending: number;
    remaining: number | null;
    available: number | null;
    entitlement: number | null;
    overdrawn: boolean;
    projected: boolean;
    label: string;
};

/** A card per leave type: available (or "No limit" for unpaid), remaining before pending, used and pending. */
export function BalanceCards({ lines }: { lines: BalanceLineData[] }) {
    if (lines.length === 0) {
        return (
            <p className="rounded-xl border bg-card px-4 py-6 text-center text-sm text-muted-foreground shadow-xs">
                No leave types are set up yet.
            </p>
        );
    }

    return (
        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {lines.map((line) => (
                <li
                    key={line.type_id}
                    className="flex flex-col gap-3 rounded-xl border bg-card p-4 shadow-xs"
                >
                    <div className="flex items-center gap-2 text-sm font-medium">
                        <LeaveColourDot colour={line.colour} />
                        {line.type_name}
                    </div>
                    {line.unlimited || line.remaining === null ? (
                        <div>
                            <p className="text-2xl font-semibold">No limit</p>
                            <p className="text-xs text-muted-foreground">
                                No balance needed
                            </p>
                        </div>
                    ) : (
                        <PaidFigures line={line} remaining={line.remaining} />
                    )}
                    <dl className="grid grid-cols-2 gap-2 border-t pt-3 text-xs">
                        <div>
                            <dt className="text-muted-foreground">Used</dt>
                            <dd className="font-medium">
                                {formatLeaveDays(line.used)}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Pending</dt>
                            <dd className="font-medium">
                                {formatLeaveDays(line.pending)}
                            </dd>
                        </div>
                        {!line.unlimited && line.carried > 0 && (
                            <div className="col-span-2">
                                <dt className="sr-only">Carried</dt>
                                <dd className="text-muted-foreground">
                                    Includes {formatLeaveDays(line.carried)}{' '}
                                    carried from last year
                                </dd>
                            </div>
                        )}
                    </dl>
                </li>
            ))}
        </ul>
    );
}

/**
 * Available (remaining − pending) as the main number, matching the apply dialog's "Left after this"; the remaining
 * before pending and the entitlement underneath.
 */
function PaidFigures({
    line,
    remaining,
}: {
    line: BalanceLineData;
    remaining: number;
}) {
    const available = line.available ?? remaining;
    const figures = balanceCardFigures(remaining, available, line.pending);

    return (
        <div className="grid gap-0.5">
            <p
                className={cn(
                    'text-2xl font-semibold',
                    available < 0 && 'text-destructive',
                )}
            >
                {figures.main}
            </p>
            <p className="text-xs text-muted-foreground">
                {figures.secondary ? `${figures.secondary} · ` : ''}
                of {formatDayAmount(line.entitlement ?? 0)} this year
            </p>
            {line.overdrawn && (
                <p className="text-xs text-destructive">
                    Overdrawn: HR will sort this out.
                </p>
            )}
        </div>
    );
}
