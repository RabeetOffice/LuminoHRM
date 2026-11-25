import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import DailyController from '@/actions/App/Http/Controllers/Attendance/DailyController';
import {
    ATTENDANCE_STATUSES,
    STATUS_META,
} from '@/components/attendance/status-symbol';
import type {
    AttendanceStatus,
    AttendanceStatusValue,
} from '@/components/attendance/status-symbol';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format-date';
import {
    CALCULATE,
    correctionPayload,
    initialCorrectionStatus,
    statusTakesNoTimes,
} from '@/lib/manual-correction';

/**
 * The statuses a person may set by hand (On Leave comes only from approved
 * leave). A fallback only: pages pass the server's list as `overrideStatuses`.
 */
export const OVERRIDE_STATUSES: AttendanceStatus[] = ATTENDANCE_STATUSES.filter(
    (status) => status !== 'on_leave',
);

/** The day as it is now. Times are "H:i" wall-clock times on that date, as the server sends them. */
export type CorrectionCurrent = {
    clock_in: string | null;
    clock_out: string | null;
    status: AttendanceStatusValue;
    is_manual: boolean;
    /** The manual row's status was chosen by hand: the dialog starts on it. */
    status_overridden?: boolean;
};

export type CorrectionDialogProps = {
    open: boolean;
    onClose: () => void;
    employee: { id: number; name: string };
    /** "Y-m-d", never passed through Date. */
    date: string;
    current: CorrectionCurrent;
    /** The statuses that may be chosen, from the server (ApplyManualCorrection::OVERRIDE_STATUSES). */
    overrideStatuses?: readonly string[];
    /**
     * Where the focus goes when the dialog has closed. The dialog is opened
     * without a Radix trigger, so a page that wants the focus back on the
     * control that opened it (the monthly grid's cell) handles it here: call
     * event.preventDefault() and focus that control.
     */
    onCloseAutoFocus?: (event: Event) => void;
    /** The current attendance day ("Y-m-d"): a clock-in entered alone on it gets a note about later punches. */
    today?: string;
};

/**
 * Correct one employee-day by hand, or remove an earlier correction. It
 * submits itself (attendance.daily.update / .reset) and closes on success; the
 * page reloads with the new values. Mount it once and toggle `open`.
 */
export function CorrectionDialog({
    open,
    onClose,
    employee,
    date,
    current,
    overrideStatuses,
    onCloseAutoFocus,
    today,
}: CorrectionDialogProps) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onClose()}
        >
            <DialogContent
                className="sm:max-w-lg"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                {/* Content unmounts when closed, so the form starts fresh each time it opens. */}
                <CorrectionForm
                    employee={employee}
                    date={date}
                    current={current}
                    overrideStatuses={overrideStatuses}
                    today={today}
                    onClose={onClose}
                    onBusy={setBusy}
                />
            </DialogContent>
        </Dialog>
    );
}

type FormProps = Omit<CorrectionDialogProps, 'open'> & {
    onBusy: (busy: boolean) => void;
};

function CorrectionForm({
    employee,
    date,
    current,
    overrideStatuses,
    onClose,
    onBusy,
    today,
}: FormProps) {
    const dateFormat = usePage().props.app.dateFormat;
    const statuses: AttendanceStatus[] = (
        overrideStatuses ?? OVERRIDE_STATUSES
    ).filter((status): status is AttendanceStatus =>
        (ATTENDANCE_STATUSES as readonly string[]).includes(status),
    );
    const form = useForm({
        clock_in: current.clock_in ?? '',
        clock_out: current.clock_out ?? '',
        status: initialCorrectionStatus(current, statuses),
        reason: '',
    });
    const { data, errors } = form;
    const [confirmReset, setConfirmReset] = useState(false);
    const [resetting, setResetting] = useState(false);
    const processing = form.processing || resetting;
    const dateError = (errors as Record<string, string | undefined>).date;
    const target = { employee: employee.id, date };
    const noTimes = statusTakesNoTimes(data.status);
    // Only a clock-in, on the open day: a later machine punch will not be added to a corrected day.
    const inOnlyToday =
        !noTimes &&
        today !== undefined &&
        date === today &&
        data.clock_in !== '' &&
        data.clock_out === '';

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((values) => correctionPayload(values));
        form.submit(DailyController.update(target), {
            preserveScroll: true,
            onStart: () => onBusy(true),
            onFinish: () => onBusy(false),
            onSuccess: () => onClose(),
        });
    };

    const reset = () => {
        router.delete(DailyController.reset(target).url, {
            preserveScroll: true,
            onStart: () => {
                setResetting(true);
                onBusy(true);
            },
            onFinish: () => {
                setResetting(false);
                onBusy(false);
            },
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>Correct attendance</DialogTitle>
                <DialogDescription>
                    {employee.name} · {formatDate(date, dateFormat)}. A
                    corrected day is kept as entered: new punches no longer
                    change it.
                </DialogDescription>
            </DialogHeader>

            <div className="grid gap-5 sm:grid-cols-2">
                <Field
                    id="correction-in"
                    label="Clock-in"
                    error={errors.clock_in}
                    hint={
                        inOnlyToday
                            ? 'A corrected day is kept as entered; a later machine punch will not be added. Enter the clock-out too, or correct again later.'
                            : undefined
                    }
                >
                    <Input
                        {...fieldAria(
                            'correction-in',
                            errors.clock_in,
                            inOnlyToday,
                        )}
                        type="time"
                        disabled={noTimes}
                        value={noTimes ? '' : data.clock_in}
                        onChange={(e) =>
                            form.setData('clock_in', e.target.value)
                        }
                    />
                </Field>
                <Field
                    id="correction-out"
                    label="Clock-out"
                    error={errors.clock_out}
                    hint="After midnight? Enter the time; it counts as the next morning when before the day cutoff."
                >
                    <Input
                        {...fieldAria('correction-out', errors.clock_out, true)}
                        type="time"
                        disabled={noTimes}
                        value={noTimes ? '' : data.clock_out}
                        onChange={(e) =>
                            form.setData('clock_out', e.target.value)
                        }
                    />
                </Field>
            </div>

            <Field
                id="correction-status"
                label="Status"
                error={errors.status}
                hint={
                    noTimes
                        ? 'An absent day has no times: the clock-in and clock-out are cleared.'
                        : 'Leave on “Work out from the times” unless the times cannot show the right status. A chosen status changes only the status; the minutes still come from the times.'
                }
            >
                <Select
                    value={data.status}
                    onValueChange={(value) => form.setData('status', value)}
                >
                    <SelectTrigger
                        {...fieldAria('correction-status', errors.status, true)}
                        className="w-full"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={CALCULATE}>
                            Work out from the times
                        </SelectItem>
                        {statuses.map((status) => (
                            <SelectItem key={status} value={status}>
                                {STATUS_META[status].label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <Field id="correction-reason" label="Reason" error={errors.reason}>
                <Textarea
                    {...fieldAria('correction-reason', errors.reason)}
                    value={data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={255}
                    rows={3}
                    placeholder="For example: forgot to punch out, confirmed by the manager."
                />
            </Field>

            {dateError && (
                <p role="alert" className="text-sm text-destructive">
                    {dateError}
                </p>
            )}

            <DialogFooter className="gap-2 sm:justify-between">
                {current.is_manual ? (
                    <Button
                        type="button"
                        variant={confirmReset ? 'destructive' : 'ghost'}
                        disabled={processing}
                        onClick={() =>
                            confirmReset ? reset() : setConfirmReset(true)
                        }
                    >
                        {resetting && <Spinner />}
                        {confirmReset
                            ? 'Confirm: use the punches again'
                            : 'Remove correction'}
                    </Button>
                ) : (
                    <span aria-hidden="true" />
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {form.processing && <Spinner />}
                        Save correction
                    </Button>
                </div>
            </DialogFooter>
        </form>
    );
}
