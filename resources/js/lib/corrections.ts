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
