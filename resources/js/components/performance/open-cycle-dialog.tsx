import { useForm, usePage } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CycleController from '@/actions/App/Http/Controllers/Performance/CycleController';
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
import { formatDate } from '@/lib/format-date';

export type PeriodOption = {
    key: string;
    label: string;
    start: string;
    end: string;
    /** The default due date: the period end plus the usual grace days. */
    due_on: string;
};

export type OpenOptions = {
    type: 'quarterly' | 'monthly';
    periods: PeriodOption[];
};

type Props = {
    options: OpenOptions;
    onClose: () => void;
};

/**
 * Opens a review cycle (performance.cycles.store): a period from the list the server offers and a due date that follows the
 * period until it is edited. The server's refusals (a period that already has a cycle or overlaps one, a date before the
 * period ends, nobody to review) show under the field they belong to; the dialog stays open on a refusal. Mount it only
 * while open.
 */
export function OpenCycleDialog({ options, onClose }: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const first = options.periods[0];
    const form = useForm({
        period: first?.key ?? '',
        due_on: first?.due_on ?? '',
    });
    const { data, errors } = form;
    const chosen = options.periods.find((p) => p.key === data.period);
    const other = Object.entries(errors)
        .filter(([key]) => key !== 'period' && key !== 'due_on')
        .map(([, message]) => message);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(CycleController.store(), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Open a review cycle</DialogTitle>
                        <DialogDescription>
                            Everyone who was active in the period and has KPIs
                            gets a review, with the KPIs they have today. People
                            without KPIs are listed so you can add them later.
                        </DialogDescription>
                    </DialogHeader>

                    {options.periods.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Every period you can choose already has a cycle.
                        </p>
                    ) : (
                        <>
                            <Field
                                id="cycle-period"
                                label={
                                    options.type === 'monthly'
                                        ? 'Month'
                                        : 'Quarter'
                                }
                                error={errors.period}
                                hint={
                                    chosen
                                        ? `Covers ${formatDate(chosen.start, dateFormat)} to ${formatDate(chosen.end, dateFormat)}.`
                                        : undefined
                                }
                            >
                                <Select
                                    value={data.period}
                                    onValueChange={(key) => {
                                        const next = options.periods.find(
                                            (p) => p.key === key,
                                        );

                                        form.setData({
                                            period: key,
                                            due_on: next?.due_on ?? data.due_on,
                                        });
                                        form.clearErrors();
                                    }}
                                >
                                    <SelectTrigger
                                        {...fieldAria(
                                            'cycle-period',
                                            errors.period,
                                            chosen !== undefined,
                                        )}
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Choose a period" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.periods.map((period) => (
                                            <SelectItem
                                                key={period.key}
                                                value={period.key}
                                            >
                                                {period.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>

                            <Field
                                id="cycle-due"
                                label="Due date"
                                error={errors.due_on}
                                hint="Reviews should be finished by this day. It cannot be before the period ends."
                            >
                                <Input
                                    {...fieldAria(
                                        'cycle-due',
                                        errors.due_on,
                                        true,
                                    )}
                                    type="date"
                                    className="w-full sm:w-48"
                                    value={data.due_on}
                                    onChange={(e) =>
                                        form.setData('due_on', e.target.value)
                                    }
                                />
                            </Field>
                        </>
                    )}

                    {other.map((message) => (
                        <p
                            key={message}
                            role="alert"
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {message}
                        </p>
                    ))}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                options.periods.length === 0 ||
                                data.period === ''
                            }
                        >
                            {form.processing && <Spinner />}
                            Open cycle
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
