/**
 * Pure helpers for the manual correction dialog (components/attendance/correction-dialog.tsx).
 */

/** The status choice meaning "work the status out from the times" (no override). */
export const CALCULATE = 'calculate';

export type CorrectionStart = {
    status: string | null;
    is_manual: boolean;
    /** The manual row's status was chosen by hand (an override). */
    status_overridden?: boolean;
};

/**
 * The status the dialog starts on: the current status when the row is a
 * manual override (so re-saving keeps it), otherwise "work out from the times".
 */
export function initialCorrectionStatus(
    current: CorrectionStart,
    allowed: readonly string[],
): string {
    return current.is_manual &&
        current.status_overridden === true &&
        current.status !== null &&
        allowed.includes(current.status)
        ? current.status
        : CALCULATE;
}

export type CorrectionValues = {
    clock_in: string;
    clock_out: string;
    status: string;
    reason: string;
};

/** Whether the chosen status takes no times (absent clears them). */
export function statusTakesNoTimes(status: string): boolean {
    return status === 'absent';
}

/** What the dialog posts: empty times as null, "calculate" as no override, no times for absent. */
export function correctionPayload(values: CorrectionValues): {
    clock_in: string | null;
    clock_out: string | null;
    status: string | null;
    reason: string;
} {
    const noTimes = statusTakesNoTimes(values.status);

    return {
        clock_in: noTimes || values.clock_in === '' ? null : values.clock_in,
        clock_out: noTimes || values.clock_out === '' ? null : values.clock_out,
        status: values.status === CALCULATE ? null : values.status,
        reason: values.reason,
    };
}
