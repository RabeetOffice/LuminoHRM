import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { cn } from '@/lib/utils';

/** The stored attendance statuses (config('hrm.attendance.statuses')). */
export const ATTENDANCE_STATUSES = [
    'present',
    'late',
    'half_day',
    'absent',
    'on_leave',
    'holiday',
    'weekend',
] as const;

export type AttendanceStatus = (typeof ATTENDANCE_STATUSES)[number];

/**
 * A status as a screen receives it: null means the row is waiting to be
 * calculated (pending). It is never shown as present and never counted.
 */
export type AttendanceStatusValue = AttendanceStatus | null;

export type StatusMeta = {
    /** Short words for pills, legends and screen readers. */
    label: string;
    /** One character for grid cells and legends. */
    symbol: string;
    /** StatusPill colour. */
    tone: StatusTone;
    /** Text colour for the symbol, light and dark. */
    symbolClass: string;
};

/** The key used for a null (pending) status in STATUS_META. */
export const PENDING = 'pending' as const;

/**
 * The one table of status symbols, labels and colours. The daily list, the
 * monthly grid and its legend, and My Attendance all read it, so a status
 * looks the same everywhere.
 */
export const STATUS_META: Record<
    AttendanceStatus | typeof PENDING,
    StatusMeta
> = {
    present: {
        label: 'Present',
        symbol: '✓',
        tone: 'green',
        symbolClass: 'text-emerald-600 dark:text-emerald-400',
    },
    late: {
        label: 'Late',
        symbol: 'L',
        tone: 'amber',
        symbolClass: 'text-amber-600 dark:text-amber-400',
    },
    half_day: {
        label: 'Half Day',
        symbol: '½',
        tone: 'amber',
        symbolClass: 'text-orange-600 dark:text-orange-400',
    },
    absent: {
        label: 'Absent',
        symbol: '✕',
        tone: 'red',
        symbolClass: 'text-red-600 dark:text-red-400',
    },
    on_leave: {
        label: 'On Leave',
        symbol: '⚑',
        tone: 'blue',
        symbolClass: 'text-blue-600 dark:text-blue-400',
    },
    holiday: {
        label: 'Holiday',
        symbol: '★',
        tone: 'blue',
        symbolClass: 'text-violet-600 dark:text-violet-400',
    },
    weekend: {
        label: 'Weekend',
        symbol: '⊘',
        tone: 'grey',
        symbolClass: 'text-neutral-500 dark:text-neutral-400',
    },
    pending: {
        label: 'Pending',
        symbol: '–',
        tone: 'grey',
        symbolClass: 'text-muted-foreground',
    },
};

/** The metadata for a status; null (pending) and unknown values give "Pending". */
export function statusMeta(status: string | null | undefined): StatusMeta {
    return status !== null &&
        status !== undefined &&
        (ATTENDANCE_STATUSES as readonly string[]).includes(status)
        ? STATUS_META[status as AttendanceStatus]
        : STATUS_META.pending;
}

type SymbolProps = {
    status: AttendanceStatusValue;
    className?: string;
};

/** The status as its one-character symbol, with the label for screen readers and as a tooltip. */
export function StatusSymbol({ status, className }: SymbolProps) {
    const meta = statusMeta(status);

    return (
        <span
            title={meta.label}
            className={cn(
                'inline-flex items-center justify-center font-semibold',
                meta.symbolClass,
                className,
            )}
        >
            <span aria-hidden="true">{meta.symbol}</span>
            <span className="sr-only">{meta.label}</span>
        </span>
    );
}

type PillProps = {
    status: AttendanceStatusValue;
    /** Replaces the label, e.g. "Not in yet" for a person with no record on an open day. */
    label?: string;
    className?: string;
};

/** The status as a coloured pill ("Present", "Late" ... or "Pending"). */
export function AttendanceStatusPill({ status, label, className }: PillProps) {
    const meta = statusMeta(status);

    return (
        <StatusPill tone={meta.tone} className={className}>
            {label ?? meta.label}
        </StatusPill>
    );
}
