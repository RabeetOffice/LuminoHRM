import { useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CorrectionController from '@/actions/App/Http/Controllers/Attendance/CorrectionController';
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
import { formatClockTime } from '@/lib/clock-time';
import { formatDate } from '@/lib/format-date';

/** The day as recorded now: "H:i" wall-clock times on that date, as the server sends them. */
export type RequestCorrectionCurrent = {
    clock_in: string | null;
    clock_out: string | null;
};

export type RequestCorrectionDialogProps = {
    open: boolean;
    onClose: () => void;
    /** "Y-m-d", never passed through Date. */
    date: string;
    current: RequestCorrectionCurrent;
};

/**
 * An employee asks for a correction of one of their own past days. It submits
 * itself (attendance.corrections.store, preserveScroll) and calls `onClose`
 * on success; the page reloads its props. Mount it once and toggle `open`; the
 * form starts fresh each time it opens. A time left empty keeps the recorded
 * one. Errors, including why the day cannot be requested, show inside it.
 */
export function RequestCorrectionDialog({
    open,
    onClose,
    date,
    current,
}: RequestCorrectionDialogProps) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onClose()}
        >
            <DialogContent className="sm:max-w-lg">
                {/* Content unmounts when closed, so the form starts fresh each time it opens. */}
                <RequestForm
                    date={date}
                    current={current}
                    onClose={onClose}
                    onBusy={setBusy}
                />
            </DialogContent>
        </Dialog>
    );
}

type FormProps = Omit<RequestCorrectionDialogProps, 'open'> & {
    onBusy: (busy: boolean) => void;
};

const recorded = (value: string | null) =>
    value ? formatClockTime(value) : 'none';

function RequestForm({ date, current, onClose, onBusy }: FormProps) {
    const dateFormat = usePage().props.app.dateFormat;
    const form = useForm({
        date,
        clock_in: current.clock_in ?? '',
        clock_out: current.clock_out ?? '',
        reason: '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.submit(CorrectionController.store(), {
            preserveScroll: true,
            onStart: () => onBusy(true),
            onFinish: () => onBusy(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>Request a correction</DialogTitle>
                <DialogDescription>
                    {formatDate(date, dateFormat)}. Recorded:{' '}
                    {recorded(current.clock_in)} in,{' '}
                    {recorded(current.clock_out)} out. Your manager and then HR
                    review the request.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-5 sm:grid-cols-2">
                <Field
                    id="request-in"
                    label="Correct clock-in"
                    error={errors.clock_in}
                >
                    <Input
                        {...fieldAria('request-in', errors.clock_in)}
                        type="time"
                        value={data.clock_in}
                        onChange={(e) =>
                            form.setData('clock_in', e.target.value)
                        }
                    />
                </Field>
                <Field
                    id="request-out"
                    label="Correct clock-out"
                    error={errors.clock_out}
                    hint="After midnight? Enter the time; it counts as the next morning when before the day cutoff."
                >
                    <Input
                        {...fieldAria('request-out', errors.clock_out, true)}
                        type="time"
                        value={data.clock_out}
                        onChange={(e) =>
                            form.setData('clock_out', e.target.value)
                        }
                    />
                </Field>
            </div>

            <Field id="request-reason" label="Reason" error={errors.reason}>
                <Textarea
                    {...fieldAria('request-reason', errors.reason)}
                    value={data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={255}
                    rows={3}
                    placeholder="For example: the machine did not read my finger when I left."
                />
            </Field>

            {errors.date && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.date}
                </p>
            )}

            <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={form.processing}
                >
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    Send request
                </Button>
            </DialogFooter>
        </form>
    );
}
