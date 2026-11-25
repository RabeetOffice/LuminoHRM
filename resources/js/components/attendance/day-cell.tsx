import { statusMeta } from '@/components/attendance/status-symbol';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    FLAG_EARLY,
    FLAG_MANUAL,
    FLAG_OVERTIME,
    blankReason,
    cellDetails,
    cellLabel,
    cellStatus,
    hasFlag,
    shortDate,
} from '@/lib/monthly-grid';
import type { GridCell, GridDay } from '@/lib/monthly-grid';
import { cn } from '@/lib/utils';

type Props = {
    day: GridDay;
    cell: GridCell;
    /** The day is inside the person's employment window. */
    inWindow: boolean;
    /** Given only to people who may correct the day: the cell becomes a button. */
    onSelect?: () => void;
};

/**
 * One person-day on the monthly grid: the status symbol from the shared status
 * table (status-symbol.tsx), a dot for early departure (bottom left) and for
 * overtime (bottom right), and a ring for a day corrected by hand. Hover or
 * keyboard focus shows the times and minutes; the accessible name says it all
 * in words, so nothing depends on colour.
 */
export function DayCell({ day, cell, inWindow, onSelect }: Props) {
    const meta = statusMeta(cellStatus(cell));
    const label = cellLabel(day, cell, inWindow);
    const details = cellDetails(cell);
    const manual = hasFlag(cell, FLAG_MANUAL);

    const face = (
        <>
            <span aria-hidden="true">{cell === null ? '–' : meta.symbol}</span>
            {hasFlag(cell, FLAG_EARLY) && (
                <span
                    aria-hidden="true"
                    className="absolute bottom-0.5 left-0.5 size-1.5 rounded-full bg-orange-500"
                />
            )}
            {hasFlag(cell, FLAG_OVERTIME) && (
                <span
                    aria-hidden="true"
                    className="absolute right-0.5 bottom-0.5 size-1.5 rounded-full bg-sky-500"
                />
            )}
        </>
    );

    const className = cn(
        'relative inline-flex size-8 items-center justify-center rounded-md text-sm font-semibold tabular-nums outline-none focus-visible:ring-2 focus-visible:ring-ring',
        cell === null ? 'text-muted-foreground/50' : meta.symbolClass,
        manual && 'ring-2 ring-primary/70 ring-inset',
        onSelect && 'cursor-pointer hover:bg-muted',
    );

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {onSelect ? (
                    <button
                        type="button"
                        aria-label={`${label}. Correct this day`}
                        className={className}
                        onClick={onSelect}
                    >
                        {face}
                    </button>
                ) : (
                    <span
                        tabIndex={0}
                        role="img"
                        aria-label={label}
                        className={className}
                    >
                        {face}
                    </span>
                )}
            </TooltipTrigger>
            <TooltipContent side="top" className="grid gap-0.5 text-left">
                <span className="font-semibold">
                    {shortDate(day.date)} ·{' '}
                    {cell === null ? blankReason(day, inWindow) : meta.label}
                </span>
                {details.map((line) => (
                    <span key={line}>{line}</span>
                ))}
            </TooltipContent>
        </Tooltip>
    );
}
