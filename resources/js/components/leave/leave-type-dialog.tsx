import { useForm } from '@inertiajs/react';
import { Check } from 'lucide-react';
import type { FormEvent, KeyboardEvent, ReactNode } from 'react';
import LeaveTypeController from '@/actions/App/Http/Controllers/Leave/LeaveTypeController';
import {
    leaveColourDot,
    leaveColourLabel,
} from '@/components/leave/leave-type-badge';
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
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

export type LeaveTypeRow = {
    id: number;
    name: string;
    /** Days a year, a multiple of 0.5; 0 for an unpaid type. */
    yearly_quota: number;
    is_paid: boolean;
    carry_forward: boolean;
    /** Days, a multiple of 0.5; 0 without carry forward. */
    carry_forward_cap: number;
    is_active: boolean;
    /** The seeded Unpaid type: never deleted, never made paid. */
    is_system: boolean;
    /** A name from config('hrm.leave.colours'). */
    colour: string;
    /** Any request of this type, whatever its status. */
    has_requests: boolean;
    /** Any request or balance row: the paid flag is fixed, and the type can be made inactive, not deleted. */
    has_history: boolean;
};

type Props = {
    /** The type being edited, or null to add a new one. */
    type: LeaveTypeRow | null;
    /** The palette, config('hrm.leave.colours'). */
    colours: string[];
    onClose: () => void;
};

/** Carried days are derived when read, never frozen (plan open decision 6, owner default). */
export const CARRY_FORWARD_NOTE =
    'Changes carried days for all years, including past ones shown on the balances screen.';

/** The swatch index an arrow / Home / End key moves to, or null for any other key. Wraps at both ends. */
export function swatchIndexForKey(
    key: string,
    current: number,
    count: number,
): number | null {
    if (count === 0) {
        return null;
    }

    switch (key) {
        case 'ArrowRight':
        case 'ArrowDown':
            return (current + 1) % count;
        case 'ArrowLeft':
        case 'ArrowUp':
            return (current - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/** A number of days as typed back into the form: 14 → "14", 7.5 → "7.5". */
const daysValue = (value: number) => String(value);

/** Add / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function LeaveTypeDialog({ type, colours, onClose }: Props) {
    const form = useForm({
        name: type?.name ?? '',
        yearly_quota: type ? daysValue(type.yearly_quota) : '10',
        is_paid: type?.is_paid ?? true,
        carry_forward: type?.carry_forward ?? false,
        carry_forward_cap: type ? daysValue(type.carry_forward_cap) : '0',
        is_active: type?.is_active ?? true,
        colour: type?.colour ?? colours[0] ?? 'slate',
    });
    const { data, errors } = form;

    const paidLocked = Boolean(type && (type.is_system || type.has_history));
    const paidHint = type?.is_system
        ? 'This is the type staff use when their paid leave runs out. It is always unpaid and cannot be deleted.'
        : type?.has_history
          ? 'This type has leave history, so it cannot change between paid and unpaid.'
          : 'Paid types have a yearly quota and a balance. Unpaid leave has no limit and is deducted from pay.';

    // Roving tabindex: the radiogroup is one tab stop (the selected swatch, else the first); arrows move and select.
    const selectedIndex = colours.indexOf(data.colour);
    const tabStop = selectedIndex >= 0 ? selectedIndex : 0;

    const onSwatchKeyDown = (
        event: KeyboardEvent<HTMLButtonElement>,
        index: number,
    ) => {
        const next = swatchIndexForKey(event.key, index, colours.length);

        if (next === null) {
            return;
        }

        event.preventDefault();
        form.setData('colour', colours[next]);
        event.currentTarget.parentElement
            ?.querySelectorAll<HTMLButtonElement>('[role="radio"]')
            [next]?.focus();
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (type) {
            form.submit(LeaveTypeController.update(type.id), options);
        } else {
            form.submit(LeaveTypeController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {type ? 'Edit leave type' : 'Add leave type'}
                        </DialogTitle>
                        <DialogDescription>
                            The yearly allowance, carry forward and calendar
                            colour of this kind of leave.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="leave-type-name"
                        label="Name"
                        error={errors.name}
                    >
                        <Input
                            {...fieldAria('leave-type-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={60}
                            autoComplete="off"
                        />
                    </Field>

                    <ToggleRow
                        id="leave-type-paid"
                        label="Paid leave"
                        hint={paidHint}
                        checked={data.is_paid}
                        disabled={paidLocked}
                        error={errors.is_paid}
                        onChange={(v) => form.setData('is_paid', v)}
                    />

                    {data.is_paid ? (
                        <>
                            <DaysField
                                id="leave-type-quota"
                                label="Yearly quota"
                                hint="Days each person gets a year. Half days allowed."
                                value={data.yearly_quota}
                                error={errors.yearly_quota}
                                onChange={(v) =>
                                    form.setData('yearly_quota', v)
                                }
                            />

                            <div className="grid gap-3">
                                <ToggleRow
                                    id="leave-type-carry"
                                    label="Carry forward unused days"
                                    hint={CARRY_FORWARD_NOTE}
                                    checked={data.carry_forward}
                                    error={errors.carry_forward}
                                    onChange={(v) =>
                                        form.setData('carry_forward', v)
                                    }
                                />
                                {data.carry_forward && (
                                    <div className="pl-6">
                                        <DaysField
                                            id="leave-type-cap"
                                            label="Carry-forward cap"
                                            hint="The most unused days that move into next year."
                                            value={data.carry_forward_cap}
                                            error={errors.carry_forward_cap}
                                            onChange={(v) =>
                                                form.setData(
                                                    'carry_forward_cap',
                                                    v,
                                                )
                                            }
                                        />
                                    </div>
                                )}
                            </div>
                        </>
                    ) : (
                        <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                            Unpaid leave has no yearly quota, no balance and no
                            carry forward.
                        </p>
                    )}

                    <ToggleRow
                        id="leave-type-active"
                        label="Active"
                        hint={
                            type?.is_system
                                ? 'The Unpaid type is always active: staff use it when their paid leave runs out.'
                                : 'Inactive types are not offered when applying. Their history stays.'
                        }
                        checked={data.is_active}
                        disabled={Boolean(type?.is_system)}
                        error={errors.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />

                    <div
                        role="radiogroup"
                        aria-labelledby="leave-type-colour-label"
                        aria-describedby={
                            errors.colour
                                ? 'leave-type-colour-error'
                                : undefined
                        }
                        className="grid gap-3"
                    >
                        <span
                            id="leave-type-colour-label"
                            className="text-sm leading-none font-medium"
                        >
                            Calendar colour
                        </span>
                        <div className="flex flex-wrap gap-2">
                            {colours.map((colour, index) => {
                                const selected = data.colour === colour;

                                return (
                                    <button
                                        key={colour}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        tabIndex={index === tabStop ? 0 : -1}
                                        onKeyDown={(e) =>
                                            onSwatchKeyDown(e, index)
                                        }
                                        aria-label={leaveColourLabel(colour)}
                                        title={leaveColourLabel(colour)}
                                        onClick={() =>
                                            form.setData('colour', colour)
                                        }
                                        className={cn(
                                            'flex size-8 items-center justify-center rounded-full ring-offset-2 ring-offset-background transition outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            leaveColourDot(colour),
                                            selected &&
                                                'ring-2 ring-foreground/70',
                                        )}
                                    >
                                        {selected && (
                                            <Check
                                                className="size-4 text-white"
                                                aria-hidden="true"
                                            />
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                        {errors.colour && (
                            <p
                                id="leave-type-colour-error"
                                role="alert"
                                className="text-sm text-destructive"
                            >
                                {errors.colour}
                            </p>
                        )}
                    </div>

                    <DialogFooter>
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
                            {type ? 'Save changes' : 'Add leave type'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/** A number of days, 0 to 365 in steps of 0.5. The value stays a string while typing; the server checks it. */
function DaysField({
    id,
    label,
    hint,
    value,
    error,
    onChange,
}: {
    id: string;
    label: string;
    hint: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
}) {
    return (
        <Field id={id} label={label} hint={hint} error={error}>
            <div className="flex items-center gap-2">
                <Input
                    {...fieldAria(id, error, true)}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    max={365}
                    step={0.5}
                    value={value}
                    className="w-28"
                    onChange={(e) => onChange(e.target.value)}
                />
                <span className="text-sm text-muted-foreground">days</span>
            </div>
        </Field>
    );
}

/** A labelled on/off checkbox with an explanation underneath; can be disabled. */
function ToggleRow({
    id,
    label,
    hint,
    checked,
    disabled = false,
    error,
    onChange,
}: {
    id: string;
    label: string;
    hint: ReactNode;
    checked: boolean;
    disabled?: boolean;
    error?: string;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={disabled}
                    onCheckedChange={(v) => onChange(v === true)}
                    aria-describedby={[
                        `${id}-hint`,
                        error ? `${id}-error` : null,
                    ]
                        .filter(Boolean)
                        .join(' ')}
                    aria-invalid={error ? true : undefined}
                />
                <Label
                    htmlFor={id}
                    className={cn(
                        'font-medium',
                        disabled && 'text-muted-foreground',
                    )}
                >
                    {label}
                </Label>
            </div>
            <p id={`${id}-hint`} className="pl-6 text-xs text-muted-foreground">
                {hint}
            </p>
            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="pl-6 text-sm text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
