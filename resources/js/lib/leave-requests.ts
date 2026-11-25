/**
 * Shaping for Leave → Requests and the request detail page (Task 6). Builds on the shared labels in `@/lib/leave`.
 * Every date is the server's "Y-m-d" text and is compared as text, never passed through `new Date()`.
 */
import {
    leaveStageLabel,
    leaveStatusLabel,
    leaveStatusTone,
} from '@/lib/leave';
import type { LeaveStatus } from '@/lib/leave';

export type LeaveStage = 'manager' | 'final' | null;

export type RequestPill = {
    tone: 'amber' | 'green' | 'red' | 'grey';
    label: string;
    /** A second line under the pill: who it waits for, or "Partly cancelled from …". Null when there is nothing. */
    note: string | null;
};

/**
 * The status pill of a request: amber Pending with "Waiting for {manager}" / "Waiting for HR", green Approved (with
 * "Partly cancelled" when days were cancelled from a date on), red Rejected, grey Cancelled.
 */
export function requestPill(
    status: LeaveStatus,
    stage: LeaveStage,
    managerFirstName: string | null,
    partlyCancelled: boolean,
): RequestPill {
    const note =
        status === 'approved' && partlyCancelled
            ? 'Partly cancelled'
            : leaveStageLabel(stage, managerFirstName);

    return {
        tone: leaveStatusTone(status),
        label: leaveStatusLabel(status),
        note,
    };
}

/** The shortest reason the server accepts for a rejection or a cancellation. */
export const MIN_REASON = 5;

/** Whether a decision may be sent: an approval always; a rejection only with a reason of 5+ characters. */
export function canSendDecision(
    decision: 'approve' | 'reject',
    note: string,
): boolean {
    return decision === 'approve' || note.trim().length >= MIN_REASON;
}

/** Whether a cancel-from date is inside the allowed range ("Y-m-d" compared as text). */
export function cancelFromAllowed(
    from: string,
    range: { min: string; max: string },
): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(from) &&
        from >= range.min &&
        from <= range.max
    );
}

/** The half of a counted day in words: "Full day", "First half", "Second half". */
export function dayHalfLabel(half: 'full' | 'am' | 'pm'): string {
    return half === 'am'
        ? 'First half'
        : half === 'pm'
          ? 'Second half'
          : 'Full day';
}
