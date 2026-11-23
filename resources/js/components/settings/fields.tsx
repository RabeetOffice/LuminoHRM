import type { ReactNode } from 'react';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Field, fieldAria } from '@/components/settings/settings-form';

/** A whole-number input. The value stays a string while typing; the server casts it. */
export function NumberField({
    id,
    label,
    value,
    onChange,
    error,
    hint,
    min,
    max,
    suffix,
}: {
    id: string;
    label: string;
    value: number | string;
    onChange: (value: string) => void;
    error?: string;
    hint?: ReactNode;
    min: number;
    max: number;
    suffix?: string;
}) {
    return (
        <Field id={id} label={label} hint={hint} error={error}>
            <div className="flex items-center gap-2">
                <Input
                    {...fieldAria(id, error, Boolean(hint))}
                    type="number"
                    inputMode="numeric"
                    min={min}
                    max={max}
                    step={1}
                    value={value}
                    className="w-28"
                    onChange={(e) => onChange(e.target.value)}
                />
                {suffix && (
                    <span className="text-sm text-muted-foreground">
                        {suffix}
                    </span>
                )}
            </div>
        </Field>
    );
}

/** A labelled checkbox with an optional explanation underneath. */
export function CheckField({
    id,
    label,
    hint,
    checked,
    onChange,
    error,
}: {
    id: string;
    label: string;
    hint?: ReactNode;
    checked: boolean;
    onChange: (checked: boolean) => void;
    error?: string;
}) {
    return (
        <div className="grid gap-1.5">
            <div className="flex items-center gap-2">
                <Checkbox
                    id={id}
                    checked={checked}
                    onCheckedChange={(v) => onChange(v === true)}
                    aria-describedby={hint ? `${id}-hint` : undefined}
                />
                <Label htmlFor={id} className="font-medium">
                    {label}
                </Label>
            </div>
            {hint && (
                <p
                    id={`${id}-hint`}
                    className="pl-6 text-xs text-muted-foreground"
                >
                    {hint}
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="pl-6 text-sm text-red-600 dark:text-red-400"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
