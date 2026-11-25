import { memo } from 'react';
import { statusMeta } from '@/components/attendance/status-symbol';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    FLAG_ATTENTION,
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
    /** Position on the grid; also written to data-row / data-col for the keyboard handler. */
    rowIndex?: number;
    dayIndex?: number;
    day: GridDay;
    cell: GridCell;
    /** The day is inside the person's employment window. */
    inWindow: boolean;
    /** The viewer may correct this day: the cell is a button (needs onActivate). */
    editable?: boolean;
    /**
     * On the monthly grid, true only for its one tab stop (roving tabindex);
     * every other cell gets -1. Defaults to true for a cell used on its own.
     */
    tabbable?: boolean;
    /** One stable callback for the whole grid, called for an editable cell. */
    onActivate?: (rowIndex: number, dayIndex: number) => void;
};

/**
 * One person-day on the monthly grid: the status symbol from the shared status
 * table (status-symbol.tsx), a dot for early departure (bottom left) and for
 * overtime (bottom right), a "!" for a day that needs attention (top right),
 * and a ring for a day corrected by hand. Hover or keyboard focus shows the
 * times and minutes; the accessible name says it all in words, so nothing
 * depends on colour.
 *
 * Memoised: the props are plain values plus one stable callback, so opening
 * the correction dialog or moving the focus re-renders only the cells whose
 * props changed.
 */
export const DayCell = memo(function DayCell({
    rowIndex = 0,
    dayIndex = 0,
    day,
    cell,
    inWindow,
    editable = false,
    tabbable = true,
    onActivate,
}: Props) {
    const button = editable && onActivate !== undefined;
    const meta = statusMeta(cellStatus(cell));
    const label = cellLabel(day, cell, inWindow);
    const details = cellDetails(cell);
    const manual = hasFlag(cell, FLAG_MANUAL);

    const face = (
        <>
            <span aria-hidden="true">{cell === null ? '–' : meta.symbol}</span>
            {hasFlag(cell, FLAG_ATTENTION) && (
                <span
                    aria-hidden="true"
                    className="absolute -top-0.5 right-0.5 text-[10px] leading-none font-bold text-amber-600 dark:text-amber-400"
                >
                    !
                </span>
            )}
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
        button && 'cursor-pointer hover:bg-muted',
    );

    const shared = {
        tabIndex: tabbable ? 0 : -1,
        className,
        'data-row': rowIndex,
        'data-col': dayIndex,
    };

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                {button ? (
                    // A native button: Enter and Space open the correction.
                    <button
                        type="button"
                        aria-label={`${label}. Correct this day`}
                        onClick={() => onActivate(rowIndex, dayIndex)}
                        {...shared}
                    >
                        {face}
                    </button>
                ) : (
                    <span role="img" aria-label={label} {...shared}>
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
});
