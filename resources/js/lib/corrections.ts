import type { StatusTone } from '@/components/hrm/status-pill';
import { formatClockTime } from '@/lib/clock-time';

export type CorrectionStatus =
    | 'pending'
    | 'manager_approved'
    | 'approved'
    | 'rejected'
    | 'cancelled';

export type CorrectionStage = 'manager' | 'hr' | null;

/** The pill for a request: its open step, or its final outcome. */
export function correctionState(
    status: CorrectionStatus,
    stage: CorrectionStage,
): { label: string; tone: StatusTone } {
    if (stage === 'manager') {
        return { label: 'Waiting for manager', tone: 'amber' };
    }

    if (stage === 'hr') {
        return { label: 'Waiting for HR', tone: 'blue' };
    }

    switch (status) {
        case 'approved':
            return { label: 'Approved', tone: 'green' };
        case 'rejected':
            return { label: 'Rejected', tone: 'red' };
        case 'cancelled':
            return { label: 'Cancelled', tone: 'grey' };
        default:
            return { label: 'Pending', tone: 'amber' };
    }
}

/**
 * "9:00 AM – 6:00 PM" for a clock-in / clock-out pair ("H:i" or null), with
 * "–" for a missing side and "No record" when both are missing. A clock-out
 * earlier than the clock-in is after midnight and gets a "(+1)" marker.
 */
export function timePair(
    clockIn: string | null,
    clockOut: string | null,
): string {
    if (!clockIn && !clockOut) {
        return 'No record';
    }

    const out = clockOut
        ? `${formatClockTime(clockOut)}${clockIn && clockOut < clockIn ? ' (+1)' : ''}`
        : '–';

    return `${clockIn ? formatClockTime(clockIn) : '–'} – ${out}`;
}

export type CorrectionTimes = {
    clock_in: string | null;
    clock_out: string | null;
};

/** True when a clock-out ("H:i") is earlier than the clock-in: after midnight, the next morning. */
export function nextDayOut(
    clockIn: string | null | undefined,
    clockOut: string | null | undefined,
): boolean {
    return Boolean(clockIn && clockOut && clockOut < clockIn);
}

export type RequestedSide = {
    label: 'In' | 'Out';
    text: string;
    changed: boolean;
};

/**
 * The two sides of a request for display. A side the employee asked to
 * change shows its time and `changed: true`; a side left empty (null) keeps
 * whatever is recorded when the request is approved: "keeps recorded". A
 * requested clock-out before the clock-in it will be paired with (requested,
 * or the recorded one) gets "(+1)".
 */
export function requestedSides(
    requested: CorrectionTimes,
    current: CorrectionTimes,
): [RequestedSide, RequestedSide] {
    const pairedIn = requested.clock_in ?? current.clock_in;

    return [
        {
            label: 'In',
            text: requested.clock_in
                ? formatClockTime(requested.clock_in)
                : 'keeps recorded',
            changed: requested.clock_in !== null,
        },
        {
            label: 'Out',
            text: requested.clock_out
                ? `${formatClockTime(requested.clock_out)}${nextDayOut(pairedIn, requested.clock_out) ? ' (+1)' : ''}`
                : 'keeps recorded',
            changed: requested.clock_out !== null,
        },
    ];
}

/**
 * What the request form sends: a side the employee did not change from the
 * recorded time is sent empty, meaning "keep what is recorded" (resolved when
 * the request is approved, so a punch that arrives meanwhile is kept).
 */
export function changedSides(
    entered: { clock_in: string; clock_out: string },
    current: CorrectionTimes,
): { clock_in: string; clock_out: string } {
    return {
        clock_in:
            entered.clock_in === (current.clock_in ?? '')
                ? ''
                : entered.clock_in,
        clock_out:
            entered.clock_out === (current.clock_out ?? '')
                ? ''
                : entered.clock_out,
    };
}
