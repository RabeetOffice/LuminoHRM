import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import { balanceLineNote, balanceLineValue } from '@/lib/dashboard';
import type { LeaveBalanceLine } from '@/lib/dashboard';
import { cn } from '@/lib/utils';

/**
 * A person's balance lines as small cards: the type (with its colour), what is left ("9.5 left", or "Unlimited" for an
 * unpaid type) and the small print ("of 14 · 1 pending"). Used by the dashboard's "My leave" card and the profile's
 * Leave tab.
 */
export function LeaveBalanceList({
    lines,
    className,
}: {
    lines: LeaveBalanceLine[];
    className?: string;
}) {
    if (lines.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">No leave types yet.</p>
        );
    }

    return (
        <dl className={cn('grid grid-cols-2 gap-3 sm:grid-cols-3', className)}>
            {lines.map((line) => (
                <div key={line.type_id} className="rounded-lg border p-3">
                    <dt className="flex items-center gap-1.5 text-xs text-muted-foreground">
                        <LeaveColourDot colour={line.colour} />
                        <span className="truncate">{line.type_name}</span>
                    </dt>
                    <dd
                        className={cn(
                            'mt-0.5 text-lg font-semibold tabular-nums',
                            line.overdrawn &&
                                'text-rose-600 dark:text-rose-400',
                        )}
                    >
                        {balanceLineValue(line)}
                    </dd>
                    <dd className="text-xs text-muted-foreground">
                        {balanceLineNote(line)}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
