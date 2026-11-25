import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import LeaveApplicationController from '@/actions/App/Http/Controllers/Leave/LeaveApplicationController';
import { LeaveColourDot } from '@/components/leave/leave-type-badge';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    formatLeaveDays,
    halfLabel,
    leaveDateLabel,
    leftAfterLabel,
} from '@/lib/leave';
import type { LeaveHalf } from '@/lib/leave';

/** An active leave type the person may apply for (the server sends only active ones). */
export type ApplyLeaveType = {
    id: number;
    name: string;
    colour: string;
    is_paid: boolean;
};

export type ApplyLeaveDialogProps = {
    open: boolean;
    onClose: () => void;
    types: ApplyLeaveType[];
    /** The dates that may be asked for, "Y-m-d" (30 days back up to 31 Dec next year). */
    window: { min: string; max: string };
    /**
     * Applying on someone's behalf (HR / Admin on Leave → Requests): that employee's id. Omitted or null: the
     * signed-in person's own record.
     */
    employeeId?: number | null;
    /** The person applied for, shown in the description when applying on their behalf. */
    employeeName?: string | null;
};

/** What GET leave.requests.preview answers. */
export type LeavePreview = {
    days: {
        date: string;
        label: string;
        amount: number;
        half: 'full' | 'am' | 'pm';
    }[];
    total: number;
    /** Per leave year touched; available is null for an unpaid type (no limit). */
    years: { year: number; asked: number; available: number | null }[];
    refusal: string | null;
    overlap: string | null;
};

const PREVIEW_DELAY_MS = 300;

/**
 * Apply for leave. It submits itself (leave.requests.store, preserveScroll, multipart when a file is attached) and
 * calls `onClose` on success; the page reloads its props. Mount it once and toggle `open`; the form starts fresh each
 * time it opens. While the dates are filled in it asks the server (leave.requests.preview, 300 ms after the last
 * change) which days count and what is left, and shows the refusal instead when the request cannot be made; Submit
 * stays disabled until the preview allows it. Every rule is checked again on submit.
 */
export function ApplyLeaveDialog({
    open,
    onClose,
    ...rest
}: ApplyLeaveDialogProps) {
    const [busy, setBusy] = useState(false);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => !next && !busy && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                {/* Content unmounts when closed, so the form starts fresh each time it opens. */}
                <ApplyForm {...rest} onClose={onClose} onBusy={setBusy} />
            </DialogContent>
        </Dialog>
    );
}

type FormProps = Omit<ApplyLeaveDialogProps, 'open'> & {
    onBusy: (busy: boolean) => void;
};

type FormData = {
    employee_id: number | null;
    leave_type_id: string;
    from_date: string;
    to_date: string;
    half: LeaveHalf | null;
    reason: string;
    attachment: File | null;
};

function ApplyForm({
    types,
    window,
    employeeId,
    employeeName,
    onClose,
    onBusy,
}: FormProps) {
    const form = useForm<FormData>({
        employee_id: employeeId ?? null,
        leave_type_id: types[0] ? String(types[0].id) : '',
        from_date: '',
        to_date: '',
        half: null,
        reason: '',
        attachment: null,
    });
    const { data, errors } = form;
    const halfDay = data.half !== null;
    const to = halfDay ? data.from_date : data.to_date;
    const preview = usePreview({
        employee: employeeId ?? null,
        type: data.leave_type_id,
        from: data.from_date,
        to,
        half: data.half,
    });

    const short = preview.data?.years.some(
        (y) => y.available !== null && y.asked > y.available,
    );
    const blocked =
        !preview.data ||
        preview.loading ||
        preview.data.refusal !== null ||
        preview.data.overlap !== null ||
        Boolean(short);

    const submit = (event: FormEvent) => {
        event.preventDefault();

        form.transform((values) => ({
            ...values,
            to_date: values.half ? values.from_date : values.to_date,
            employee_id: values.employee_id ?? undefined,
            attachment: values.attachment ?? undefined,
        }));
        form.submit(LeaveApplicationController.store(), {
            preserveScroll: true,
            forceFormData: data.attachment !== null,
            onStart: () => onBusy(true),
            onFinish: () => onBusy(false),
            onSuccess: () => onClose(),
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>Apply for leave</DialogTitle>
                <DialogDescription>
                    {employeeName
                        ? `On behalf of ${employeeName}. `
                        : ''}
                    Weekly offs and holidays are not counted. The request is
                    reviewed before it is approved.
                </DialogDescription>
            </DialogHeader>

            <Field id="leave-type" label="Leave type" error={errors.leave_type_id}>
                <Select
                    value={data.leave_type_id}
                    onValueChange={(value) =>
                        form.setData('leave_type_id', value)
                    }
                >
                    <SelectTrigger
                        {...fieldAria('leave-type', errors.leave_type_id)}
                        className="w-full"
                    >
                        <SelectValue placeholder="Choose a type" />
                    </SelectTrigger>
                    <SelectContent>
                        {types.map((type) => (
                            <SelectItem key={type.id} value={String(type.id)}>
                                <LeaveColourDot colour={type.colour} />
                                {type.is_paid
                                    ? type.name
                                    : `${type.name} — no balance needed`}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>

            <div className="flex items-center gap-2">
                <Checkbox
                    id="leave-half-day"
                    checked={halfDay}
                    onCheckedChange={(checked) =>
                        form.setData('half', checked === true ? 'am' : null)
                    }
                />
                <Label htmlFor="leave-half-day">Half day</Label>
            </div>

            {halfDay ? (
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field id="leave-date" label="Date" error={errors.from_date}>
                        <Input
                            {...fieldAria('leave-date', errors.from_date)}
                            type="date"
                            min={window.min}
                            max={window.max}
                            value={data.from_date}
                            onChange={(e) =>
                                form.setData('from_date', e.target.value)
                            }
                        />
                    </Field>
                    <div className="grid content-start gap-2">
                        <Label id="leave-half-label">Which half</Label>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            aria-labelledby="leave-half-label"
                            value={data.half ?? 'am'}
                            onValueChange={(value) =>
                                value &&
                                form.setData('half', value as LeaveHalf)
                            }
                        >
                            <ToggleGroupItem value="am" className="flex-1 px-3">
                                {halfLabel('am')}
                            </ToggleGroupItem>
                            <ToggleGroupItem value="pm" className="flex-1 px-3">
                                {halfLabel('pm')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                </div>
            ) : (
                <div className="grid gap-5 sm:grid-cols-2">
                    <Field id="leave-from" label="From" error={errors.from_date}>
                        <Input
                            {...fieldAria('leave-from', errors.from_date)}
                            type="date"
                            min={window.min}
                            max={window.max}
                            value={data.from_date}
                            onChange={(e) => {
                                const value = e.target.value;
                                form.setData((current) => ({
                                    ...current,
                                    from_date: value,
                                    // A blank or earlier end follows the start.
                                    to_date:
                                        current.to_date === '' ||
                                        current.to_date < value
                                            ? value
                                            : current.to_date,
                                }));
                            }}
                        />
                    </Field>
                    <Field id="leave-to" label="To" error={errors.to_date}>
                        <Input
                            {...fieldAria('leave-to', errors.to_date)}
                            type="date"
                            min={data.from_date || window.min}
                            max={window.max}
                            value={data.to_date}
                            onChange={(e) =>
                                form.setData('to_date', e.target.value)
                            }
                        />
                    </Field>
                </div>
            )}

            <PreviewPanel preview={preview} />

            <Field id="leave-reason" label="Reason" error={errors.reason}>
                <Textarea
                    {...fieldAria('leave-reason', errors.reason)}
                    value={data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={500}
                    rows={3}
                    placeholder="For example: family wedding out of town."
                />
            </Field>

            <Field
                id="leave-attachment"
                label="Attachment (optional)"
                error={errors.attachment}
                hint="A PDF, PNG or JPG up to 5 MB, for example a medical note."
            >
                <Input
                    {...fieldAria('leave-attachment', errors.attachment, true)}
                    type="file"
                    accept=".pdf,.png,.jpg,.jpeg,application/pdf,image/png,image/jpeg"
                    onChange={(e) =>
                        form.setData('attachment', e.target.files?.[0] ?? null)
                    }
                />
            </Field>

            {(errors.days || errors.employee_id || errors.half) && (
                <p role="alert" className="text-sm text-destructive">
                    {errors.days ?? errors.employee_id ?? errors.half}
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
                <Button type="submit" disabled={form.processing || blocked}>
                    {form.processing && <Spinner />}
                    Send request
                </Button>
            </DialogFooter>
        </form>
    );
}

type PreviewState = {
    data: LeavePreview | null;
    loading: boolean;
    failed: boolean;
};

type PreviewAsk = {
    employee: number | null;
    type: string;
    from: string;
    to: string;
    half: LeaveHalf | null;
};

/** Asks the server which days count, 300 ms after the last change; nothing until a type and both dates are set. */
function usePreview(ask: PreviewAsk): PreviewState {
    const [state, setState] = useState<PreviewState>({
        data: null,
        loading: false,
        failed: false,
    });
    const ready = ask.type !== '' && ask.from !== '' && ask.to !== '';

    useEffect(() => {
        if (!ready) {
            setState({ data: null, loading: false, failed: false });

            return;
        }

        const controller = new AbortController();
        setState((current) => ({ ...current, loading: true, failed: false }));

        const timer = window.setTimeout(() => {
            const url = LeaveApplicationController.preview.url({
                query: {
                    type: ask.type,
                    from: ask.from,
                    to: ask.to,
                    ...(ask.half ? { half: ask.half } : {}),
                    ...(ask.employee ? { employee: ask.employee } : {}),
                },
            });

            fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(String(response.status));
                    }

                    return response.json() as Promise<LeavePreview>;
                })
                .then((body) =>
                    setState({ data: body, loading: false, failed: false }),
                )
                .catch((cause: unknown) => {
                    if ((cause as Error).name !== 'AbortError') {
                        setState({ data: null, loading: false, failed: true });
                    }
                });
        }, PREVIEW_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [ready, ask.employee, ask.type, ask.from, ask.to, ask.half]);

    return state;
}

function PreviewPanel({ preview }: { preview: PreviewState }) {
    const { data, loading, failed } = preview;

    if (failed) {
        return (
            <p role="alert" className="text-sm text-destructive">
                The days could not be counted. Try again in a moment.
            </p>
        );
    }

    if (!data) {
        return loading ? (
            <p className="flex items-center gap-2 text-sm text-muted-foreground">
                <Spinner /> Counting days…
            </p>
        ) : null;
    }

    const problem = data.refusal ?? data.overlap;

    if (problem) {
        return (
            <p
                role="alert"
                className="rounded-[10px] border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive"
            >
                {problem}
            </p>
        );
    }

    return (
        <div
            aria-live="polite"
            className="grid gap-3 rounded-[10px] border bg-muted/30 p-3 text-sm"
        >
            <div className="flex items-baseline justify-between gap-2">
                <span className="font-medium">Counted days</span>
                <span className="font-semibold">
                    {formatLeaveDays(data.total)}
                </span>
            </div>
            <ul className="grid max-h-36 gap-1 overflow-y-auto text-muted-foreground">
                {data.days.map((day) => (
                    <li
                        key={`${day.date}-${day.half}`}
                        className="flex justify-between gap-2"
                    >
                        <span>{day.label || leaveDateLabel(day.date)}</span>
                        <span>
                            {day.half === 'full'
                                ? formatLeaveDays(day.amount)
                                : halfLabel(day.half)}
                        </span>
                    </li>
                ))}
            </ul>
            {data.years.map((year) =>
                year.available === null ? null : (
                    <p
                        key={year.year}
                        className={
                            year.asked > year.available
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        }
                    >
                        {data.years.length > 1 ? `${year.year}: ` : ''}
                        {year.asked > year.available
                            ? `Not enough leave: ${formatLeaveDays(year.available)} left. Apply for the extra days as Unpaid leave.`
                            : leftAfterLabel(year.available, year.asked)}
                    </p>
                ),
            )}
            {loading && (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Spinner /> Updating…
                </p>
            )}
        </div>
    );
}
