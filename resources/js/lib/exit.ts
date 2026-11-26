/**
 * Exit helpers shared by the Resignations list, the exit page, My Resignation, the profile tab and the dashboard: the words
 * people read for a stage, the pill colour of each, the notice sentence and how a settlement's net is shown. The values are
 * the ones in config('hrm.exit') and App\Support\Exits\ExitStates; an unknown value falls back to the raw text with a grey
 * tone, so a new state never breaks a screen. Money is integer paisa; dates are "Y-m-d" strings the page formats.
 */
import type { StatusTone } from '@/components/hrm/status-pill';
import { formatMoney } from '@/lib/payroll';

export type ExitType = 'resignation' | 'termination';

export type ExitStatus =
    | 'pending'
    | 'manager_approved'
    | 'approved'
    | 'rejected'
    | 'withdrawn'
    | 'cancelled';

export type SettlementStatus = 'draft' | 'approved' | 'paid';

export type Notice = {
    given: number;
    required: number;
    short: number;
    label: string;
};

/** One exit as a list row / request card (App\Support\Exits\ExitView::row). */
export type ExitRow = {
    id: number;
    employee: {
        id: number;
        code: string;
        name: string;
        department: string | null;
        designation: string | null;
        manager: string | null;
        status: string;
    };
    type: ExitType;
    status: ExitStatus;
    /** "With manager", "With HR", "Approved", "Rejected", "Withdrawn", "Cancelled". */
    stage: string;
    /** The step it waits at: "manager" | "final" | null. */
    waiting_at: 'manager' | 'final' | null;
    /** The finer stage of an approved exit ("Clearance", "Settlement draft", ...), only for people who see settlements. */
    detail: string | null;
    submitted_on: string;
    last_working_day: string;
    reason: string;
    notice: Notice;
    recorded_by_hr: boolean;
    decision_note: string | null;
    cancel_reason: string | null;
    url: string;
    /** Present only with exit.clearance + payroll.view. */
    settlement?: SettlementStatus | null;
    waiting_for_me?: boolean;
    /** Profile tab only: the settlement's signed net (paisa), with payroll.view. */
    net?: number;
};

export type FlowState =
    | 'done'
    | 'current'
    | 'skipped'
    | 'pending'
    | 'rejected'
    | 'withdrawn'
    | 'cancelled';

export type FlowStep = {
    key: string;
    label: string;
    state: FlowState;
    who: string | null;
    /** "Y-m-d". */
    on: string | null;
    note?: string | null;
};

const TYPE_LABELS: Record<ExitType, string> = {
    resignation: 'Resignation',
    termination: 'Termination',
};

/** "Resignation" / "Termination"; unknown values come back as given. */
export function exitTypeLabel(type: string): string {
    return TYPE_LABELS[type as ExitType] ?? type;
}

/** A termination is red (HR's decision, not the person's), a resignation grey. */
export function exitTypeTone(type: string): StatusTone {
    return type === 'termination' ? 'red' : 'grey';
}

// Waiting on the manager is amber, on HR violet, approved green, ended grey (rejected red).
const STATUS_TONES: Record<ExitStatus, StatusTone> = {
    pending: 'amber',
    manager_approved: 'violet',
    approved: 'green',
    rejected: 'red',
    withdrawn: 'grey',
    cancelled: 'grey',
};

const STATUS_LABELS: Record<ExitStatus, string> = {
    pending: 'With manager',
    manager_approved: 'With HR',
    approved: 'Approved',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    cancelled: 'Cancelled',
};

/** The words for a status; unknown values come back as given. */
export function exitStatusLabel(status: string): string {
    return Object.hasOwn(STATUS_LABELS, status)
        ? STATUS_LABELS[status as ExitStatus]
        : status;
}

/** The pill colour for an exit. After approval the finer stage ("Paid", "Left") changes the colour of the pill. */
export function exitStageTone(
    status: string,
    detail?: string | null,
): StatusTone {
    if (status === 'approved' && detail) {
        if (detail === 'Left') {
            return 'grey';
        }

        if (detail === 'Paid' || detail === 'Settlement approved') {
            return 'green';
        }

        if (detail === 'Settlement draft') {
            return 'blue';
        }

        return 'amber';
    }

    return STATUS_TONES[status as ExitStatus] ?? 'grey';
}

/** What the stage pill says: the finer stage of an approved exit when known, else the status. */
export function exitStageText(row: {
    status: string;
    stage: string;
    detail?: string | null;
}): string {
    return row.status === 'approved' && row.detail ? row.detail : row.stage;
}

/** Whether the exit is still in progress (waiting or approved and not yet cancelled). */
export function isOpenExit(status: string): boolean {
    return (
        status === 'pending' ||
        status === 'manager_approved' ||
        status === 'approved'
    );
}

/**
 * "30 days notice" or "12 days short of notice (30 required)". Same sentence as the server's ExitNotice::label ("1 day" in
 * the singular). `given` and `required` are whole calendar days.
 */
export function noticeLabel(given: number, required: number): string {
    const days = (n: number) => `${n} ${n === 1 ? 'day' : 'days'}`;
    const short = Math.max(0, required - given);

    return short === 0
        ? `${days(given)} notice`
        : `${days(short)} short of notice (${required} required)`;
}

/** The pill colour of a notice: green when enough, amber when short. */
export function noticeTone(notice: Pick<Notice, 'short'>): StatusTone {
    return notice.short > 0 ? 'amber' : 'green';
}

const STEP_TONES: Record<FlowState, StatusTone> = {
    done: 'green',
    current: 'amber',
    skipped: 'grey',
    pending: 'grey',
    rejected: 'red',
    withdrawn: 'grey',
    cancelled: 'grey',
};

const STEP_TEXT: Record<FlowState, string> = {
    done: 'Done',
    current: 'Waiting',
    skipped: 'Skipped',
    pending: 'Not reached',
    rejected: 'Rejected',
    withdrawn: 'Withdrawn',
    cancelled: 'Cancelled',
};

export function flowTone(state: string): StatusTone {
    return STEP_TONES[state as FlowState] ?? 'grey';
}

export function flowText(state: string): string {
    return STEP_TEXT[state as FlowState] ?? state;
}

const SETTLEMENT_LABELS: Record<SettlementStatus, string> = {
    draft: 'Draft',
    approved: 'Approved',
    paid: 'Paid',
};

export function settlementStatusLabel(status: string): string {
    return SETTLEMENT_LABELS[status as SettlementStatus] ?? status;
}

export function settlementStatusTone(status: string): StatusTone {
    return status === 'draft'
        ? 'grey'
        : status === 'approved'
          ? 'green'
          : status === 'paid'
            ? 'blue'
            : 'grey';
}

/** "Rs 49,845", or "Owed by employee: Rs 4,200" when the net is below zero (the settlement pays nothing then). */
export function settlementNetText(net: number): string {
    return net < 0
        ? `Owed by employee: ${formatMoney(-net)}`
        : formatMoney(net);
}

/** What the main action button of a settled net says: "Mark paid", or "Mark settled" when nothing is paid out. */
export function paidButtonLabel(net: number): string {
    return net > 0 ? 'Mark paid' : 'Mark settled';
}

/** Every message of a validation-error bag as one list (the keys differ per action; the screen shows them all). */
export function errorMessages(
    errors: Record<string, string | undefined>,
): string[] {
    return Object.values(errors).filter(
        (message): message is string =>
            typeof message === 'string' && message !== '',
    );
}

/** "Name (CODE)" for the person pickers and the profile. */
export function personText(person: { name: string; code: string }): string {
    return `${person.name} (${person.code})`;
}

/** The messages of a validation-error bag whose key is not one of the dialog's own fields (they show under the form). */
export function generalErrors(
    errors: Record<string, string | undefined>,
    fieldKeys: string[],
): string[] {
    return errorMessages(
        Object.fromEntries(
            Object.entries(errors).filter(([key]) => !fieldKeys.includes(key)),
        ),
    );
}

/**
 * Calendar days of notice from "Y-m-d" to "Y-m-d", both days counted (the same day gives 1). Read as text, never as a
 * moment in time, so a day cannot move with the time zone. 0 when either is not a date or the order is wrong.
 */
export function noticeGiven(submittedOn: string, lastDay: string): number {
    const parse = (value: string): number | null => {
        const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);

        return match
            ? Date.UTC(
                  Number(match[1]),
                  Number(match[2]) - 1,
                  Number(match[3]),
              ) / 86_400_000
            : null;
    };
    const from = parse(submittedOn);
    const to = parse(lastDay);

    return from === null || to === null || to < from ? 0 : to - from + 1;
}
