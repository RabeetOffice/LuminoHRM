import { cn } from '@/lib/utils';

/**
 * The leave type palette: the names in config('hrm.leave.colours'), mapped to Tailwind classes. Class names are
 * written out in full so Tailwind keeps them. An unknown name (should never happen: the model refuses it) falls back
 * to slate.
 */
const swatches: Record<string, { dot: string; badge: string }> = {
    emerald: {
        dot: 'bg-emerald-500',
        badge: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    },
    sky: {
        dot: 'bg-sky-500',
        badge: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    },
    amber: {
        dot: 'bg-amber-500',
        badge: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    },
    rose: {
        dot: 'bg-rose-500',
        badge: 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    },
    violet: {
        dot: 'bg-violet-500',
        badge: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    },
    teal: {
        dot: 'bg-teal-500',
        badge: 'bg-teal-50 text-teal-700 dark:bg-teal-500/15 dark:text-teal-300',
    },
    orange: {
        dot: 'bg-orange-500',
        badge: 'bg-orange-50 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300',
    },
    slate: {
        dot: 'bg-slate-500',
        badge: 'bg-slate-100 text-slate-700 dark:bg-slate-500/20 dark:text-slate-300',
    },
};

const swatch = (colour: string) => swatches[colour] ?? swatches.slate;

/** The solid dot class of a palette colour (calendar cells, legends, swatches). */
export const leaveColourDot = (colour: string) => swatch(colour).dot;

/** The display name of a palette colour ("emerald" → "Emerald"). */
export const leaveColourLabel = (colour: string) =>
    colour.charAt(0).toUpperCase() + colour.slice(1);

/** A small coloured dot for a leave type. Decorative: the type's name is shown next to it. */
export function LeaveColourDot({
    colour,
    className,
}: {
    colour: string;
    className?: string;
}) {
    return (
        <span
            aria-hidden="true"
            className={cn(
                'inline-block size-2.5 shrink-0 rounded-full',
                swatch(colour).dot,
                className,
            )}
        />
    );
}

/** A leave type's name as a tinted pill in its calendar colour. */
export function LeaveTypeBadge({
    name,
    colour,
    className,
}: {
    name: string;
    colour: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                swatch(colour).badge,
                className,
            )}
        >
            <LeaveColourDot colour={colour} className="size-1.5" />
            {name}
        </span>
    );
}
