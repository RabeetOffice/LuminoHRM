import {
    ATTENDANCE_STATUSES,
    STATUS_META,
} from '@/components/attendance/status-symbol';
import { cn } from '@/lib/utils';

/**
 * The key to the monthly grid: every status symbol from the shared status
 * table, the blank mark, and the markers drawn on a cell (early departure and
 * overtime dots, the ring of a day corrected by hand).
 */
export function AttendanceLegend({ className }: { className?: string }) {
    return (
        <ul
            aria-label="Legend"
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground',
                className,
            )}
        >
            {ATTENDANCE_STATUSES.map((status) => (
                <li key={status} className="flex items-center gap-1.5">
                    <span
                        aria-hidden="true"
                        className={cn(
                            'w-3 text-center text-sm font-semibold',
                            STATUS_META[status].symbolClass,
                        )}
                    >
                        {STATUS_META[status].symbol}
                    </span>
                    {STATUS_META[status].label}
                </li>
            ))}
            <li className="flex items-center gap-1.5">
                <span
                    aria-hidden="true"
                    className="w-3 text-center text-sm font-semibold text-muted-foreground"
                >
                    –
                </span>
                Not yet / pending / no record
            </li>
            <li className="flex items-center gap-1.5">
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-orange-500"
                />
                Left early
            </li>
            <li className="flex items-center gap-1.5">
                <span
                    aria-hidden="true"
                    className="size-1.5 rounded-full bg-sky-500"
                />
                Overtime
            </li>
            <li className="flex items-center gap-1.5">
                <span
                    aria-hidden="true"
                    className="size-3.5 rounded-sm ring-2 ring-primary/70 ring-inset"
                />
                Corrected by hand
            </li>
        </ul>
    );
}
