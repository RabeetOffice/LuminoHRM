import { router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import LeaveApplicationController from '@/actions/App/Http/Controllers/Leave/LeaveApplicationController';
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
import { leaveRangeLabel } from '@/lib/leave';
import type { LeaveHalf } from '@/lib/leave';
import { MIN_REASON } from '@/lib/leave-requests';

export type WithdrawSubject = {
    id: number;
    employee: { name: string };
    from: string;
    to: string;
    half: LeaveHalf | null;
    /** The viewer's own request: no reason asked. Someone else's (HR / Admin): the employee is mailed the reason. */
    is_own: boolean;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subject: WithdrawSubject | null;
};

/**
 * Withdraws a request nobody has decided yet. The requester just confirms; HR / Admin withdrawing someone else's
 * request say why (the employee is mailed that reason).
 */
export function WithdrawRequestDialog({ open, onOpenChange, subject }: Props) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onOpenChange(false)}
        >
            <DialogContent className="sm:max-w-md">
                {subject && (
                    <WithdrawForm
                        key={subject.id}
                        subject={subject}
                        onBusy={setBusy}
                        onClose={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    subject: WithdrawSubject;
    onBusy: (busy: boolean) => void;
    onClose: () => void;
};

function WithdrawForm({ subject, onBusy, onClose }: FormProps) {
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const range = leaveRangeLabel(subject.from, subject.to, subject.half);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!subject.is_own && note.trim().length < MIN_REASON) {
            setError(
                `Say why the request is withdrawn (at least ${MIN_REASON} characters).`,
            );

            return;
        }

        router.post(
            LeaveApplicationController.cancel(subject.id).url,
            subject.is_own ? {} : { note },
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
                onError: (e) => setError(e.note),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>Cancel this leave request?</DialogTitle>
                <DialogDescription>
                    {subject.is_own
                        ? `Your request for ${range} will be withdrawn.`
                        : `The request of ${subject.employee.name} for ${range} will be withdrawn. They are emailed the reason.`}
                </DialogDescription>
            </DialogHeader>

            {!subject.is_own && (
                <Field
                    id="withdraw-reason"
                    label="Why is it withdrawn?"
                    error={error}
                >
                    <Textarea
                        {...fieldAria('withdraw-reason', error)}
                        value={note}
                        onChange={(e) => {
                            setNote(e.target.value);
                            setError(undefined);
                        }}
                        maxLength={500}
                        rows={3}
                    />
                </Field>
            )}

            <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={processing}
                >
                    Keep it
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    Cancel request
                </Button>
            </DialogFooter>
        </form>
    );
}
