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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { leaveDateLabel, leaveRangeLabel } from '@/lib/leave';
import type { LeaveHalf } from '@/lib/leave';
import { cancelFromAllowed, MIN_REASON } from '@/lib/leave-requests';

export type CancelSubject = {
    id: number;
    employee: { name: string };
    type: { name: string };
    from: string;
    to: string;
    half: LeaveHalf | null;
    /** The days HR may cancel from ("Y-m-d"): min..max, and the suggested first day (today, kept inside). */
    cancel_range: { min: string; max: string; default: string } | null;
};

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    subject: CancelSubject | null;
};

/**
 * HR / Admin cancel approved leave from a chosen day on (default today). Days from that day go back to the balance and
 * the attendance days are worked out again; earlier days stay. A reason is required; the employee sees it.
 */
export function CancelApprovedDialog({ open, onOpenChange, subject }: Props) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onOpenChange(false)}
        >
            <DialogContent className="sm:max-w-lg">
                {subject?.cancel_range && (
                    <CancelForm
                        key={subject.id}
                        subject={subject}
                        range={subject.cancel_range}
                        onBusy={setBusy}
                        onClose={() => onOpenChange(false)}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

type FormProps = {
    subject: CancelSubject;
    range: { min: string; max: string; default: string };
    onBusy: (busy: boolean) => void;
    onClose: () => void;
};

function CancelForm({ subject, range, onBusy, onClose }: FormProps) {
    const [from, setFrom] = useState(range.default);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<{ from_date?: string; note?: string }>(
        {},
    );
    const [processing, setProcessing] = useState(false);
    const whole = from === range.min;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const local: { from_date?: string; note?: string } = {};
        if (!cancelFromAllowed(from, range)) {
            local.from_date = `Choose a day from ${leaveDateLabel(range.min)} to ${leaveDateLabel(range.max)}.`;
        }
        if (note.trim().length < MIN_REASON) {
            local.note = `Say why the leave is cancelled (at least ${MIN_REASON} characters).`;
        }
        if (local.from_date || local.note) {
            setErrors(local);

            return;
        }

        router.post(
            LeaveRequestController.cancelApproved(subject.id).url,
            { from_date: from, note },
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
                onError: (e) =>
                    setErrors({ from_date: e.from_date, note: e.note }),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>Cancel approved leave</DialogTitle>
                <DialogDescription>
                    {subject.employee.name} · {subject.type.name} ·{' '}
                    {leaveRangeLabel(subject.from, subject.to, subject.half)}.
                    Leave days from the chosen day go back to the balance and
                    attendance for them is worked out again; earlier days stay.
                    Days corrected by hand on the Daily screen are not changed.
                </DialogDescription>
            </DialogHeader>

            <Field
                id="cancel-from"
                label="Cancel from"
                hint={
                    whole
                        ? 'From the first day: the whole leave is cancelled.'
                        : `Days before ${leaveDateLabel(from)} stay as leave.`
                }
                error={errors.from_date}
            >
                <Input
                    {...fieldAria('cancel-from', errors.from_date, true)}
                    type="date"
                    value={from}
                    min={range.min}
                    max={range.max}
                    onChange={(e) => {
                        setFrom(e.target.value);
                        setErrors((x) => ({ ...x, from_date: undefined }));
                    }}
                    className="w-full sm:w-48"
                />
            </Field>

            <Field
                id="cancel-reason"
                label="Why is it cancelled?"
                error={errors.note}
            >
                <Textarea
                    {...fieldAria('cancel-reason', errors.note)}
                    value={note}
                    onChange={(e) => {
                        setNote(e.target.value);
                        setErrors((x) => ({ ...x, note: undefined }));
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
                    Keep it
                </Button>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {whole ? 'Cancel the leave' : 'Cancel from this day'}
                </Button>
            </DialogFooter>
        </form>
    );
}
