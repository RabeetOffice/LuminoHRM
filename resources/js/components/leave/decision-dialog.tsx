import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import LeaveRequestController from '@/actions/App/Http/Controllers/Leave/LeaveRequestController';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatLeaveDays, leaveRangeLabel } from '@/lib/leave';
import type { LeaveHalf } from '@/lib/leave';
import { canSendDecision, MIN_REASON } from '@/lib/leave-requests';

/** What the dialog needs to know about the request (a row of Leave → Requests or the detail page). */
export type DecisionSubject = {
    id: number;
    employee: { name: string };
    type: { name: string };
    from: string;
    to: string;
    half: LeaveHalf | null;
    days: number;
    reason?: string | null;
    manager_name: string | null;
    /** The manager's approval is final (HR step off and the requester is not Admin / HR). */
    manager_approval_final: boolean;
    can: {
        decide: 'manager' | 'final' | null;
        /** Deciding now as HR skips the manager, who has not answered yet. */
        skips_manager: boolean;
    };
};

/** Null: the form offers both (a decision as HR that skips the manager). */
export type DecisionChoice = 'approve' | 'reject' | null;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The last request asked about stays set while the dialog animates closed. */
    subject: DecisionSubject | null;
    decision: DecisionChoice;
};

/**
 * Approve or reject a leave request at the step this user may decide (manager or final). A rejection needs a reason
 * (5+ characters, checked here and on the server); the employee sees it. A stale decision (someone else decided
 * first, the employee cancelled) comes back as a toast and the page reloads.
 */
export function DecisionDialog({
    open,
    onOpenChange,
    subject,
    decision,
}: Props) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onOpenChange(false)}
        >
            <DialogContent className="sm:max-w-lg">
                {subject && (
                    <DecisionForm
                        key={`${subject.id}-${decision ?? 'either'}`}
                        subject={subject}
                        decision={decision}
                        onBusy={setBusy}
                        onClose={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    subject: DecisionSubject;
    decision: DecisionChoice;
    onBusy: (busy: boolean) => void;
    onClose: () => void;
};

function DecisionForm({ subject, decision, onBusy, onClose }: FormProps) {
    const either = decision === null;
    const approve = decision === 'approve';
    const final = subject.can.decide === 'final';
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const rejectable = canSendDecision('reject', note);

    const send = (choice: 'approve' | 'reject') => {
        if (!canSendDecision(choice, note)) {
            setError(
                `Say why the request is rejected (at least ${MIN_REASON} characters).`,
            );

            return;
        }

        const target = final
            ? LeaveRequestController.finalDecide(subject.id)
            : LeaveRequestController.managerDecide(subject.id);

        router.put(
            target.url,
            { decision: choice, note },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    onBusy(true);
                },
                onFinish: () => {
                    setProcessing(false);
                    onBusy(false);
                },
                onError: (errors) => setError(errors.note ?? errors.decision),
                onSuccess: () => onClose(),
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!either) {
            send(approve ? 'approve' : 'reject');
        }
    };

    const description = either
        ? `The manager${subject.manager_name ? ` (${subject.manager_name})` : ''} has not decided yet. Your decision is final and skips the manager. A rejection needs a reason, which the employee will see.`
        : approve
          ? final || subject.manager_approval_final
              ? 'The leave days are recorded and the balance goes down. The days are counted again now, in case holidays or shifts changed.'
              : 'The request then goes to HR for the final decision.'
          : 'The employee will see your reason. A rejection is final.';

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {either
                        ? 'Decide as HR'
                        : approve
                          ? 'Approve leave'
                          : 'Reject leave'}
                </DialogTitle>
                <DialogDescription>
                    {subject.employee.name}. {description}
                </DialogDescription>
            </DialogHeader>

            <dl className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
                <dt className="text-muted-foreground">Type</dt>
                <dd>{subject.type.name}</dd>
                <dt className="text-muted-foreground">Dates</dt>
                <dd>
                    {leaveRangeLabel(subject.from, subject.to, subject.half)}
                </dd>
                <dt className="text-muted-foreground">Days</dt>
                <dd className="tabular-nums">
                    {formatLeaveDays(subject.days)}
                </dd>
                {subject.reason && (
                    <>
                        <dt className="text-muted-foreground">Reason</dt>
                        <dd className="break-words">{subject.reason}</dd>
                    </>
                )}
            </dl>

            <Field
                id="leave-decision-note"
                label={
                    either
                        ? 'Note (required to reject)'
                        : approve
                          ? 'Note (optional)'
                          : 'Why is it rejected?'
                }
                error={error}
            >
                <Textarea
                    {...fieldAria('leave-decision-note', error)}
                    value={note}
                    onChange={(e) => {
                        setNote(e.target.value);
                        setError(undefined);
                    }}
                    maxLength={500}
                    rows={3}
                />
            </Field>

            <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={processing}
                >
                    Back
                </Button>
                {either ? (
                    <>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing || !rejectable}
                            onClick={() => send('reject')}
                        >
                            Reject
                        </Button>
                        <Button
                            type="button"
                            disabled={processing}
                            onClick={() => send('approve')}
                        >
                            {processing && <Spinner />}
                            Approve
                        </Button>
                    </>
                ) : (
                    <Button
                        type="submit"
                        variant={approve ? 'default' : 'destructive'}
                        disabled={processing || (!approve && !rejectable)}
                    >
                        {processing && <Spinner />}
                        {approve ? 'Approve' : 'Reject'}
                    </Button>
                )}
            </DialogFooter>
        </form>
    );
}
