import type { InertiaFormProps } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import HrmSettingsController from '@/actions/App/Http/Controllers/Settings/HrmSettingsController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { SettingsGroup } from '@/types/hrm-settings';

/** What every card in a group's form needs to draw its footer. */
export type FormState = {
    processing: boolean;
    dirty: boolean;
};

/** Form data as typed in inputs: a number field holds text while the user types. */
export type Editable<T> = {
    [K in keyof T]: T[K] extends number ? number | string : T[K];
};

/** Typed text and numbers compare by their text; files by identity. */
function sameValue(a: unknown, b: unknown): boolean {
    if (a instanceof File || b instanceof File) {
        return a === b;
    }

    return JSON.stringify(a) === JSON.stringify(b)
        ? true
        : typeof a !== 'object' &&
              typeof b !== 'object' &&
              primitiveText(a) === primitiveText(b);
}

/** Text of a typed value (string, number, boolean); empty for null, undefined and anything else. */
function primitiveText(value: unknown): string {
    return typeof value === 'string' ||
        typeof value === 'number' ||
        typeof value === 'boolean'
        ? String(value)
        : '';
}

/**
 * Saves one settings group card by card. `card(fields)` gives a card its own Save Changes
 * handler (sends only those fields; the server validates them against the stored values of
 * the rest of the group) and its own unsaved-changes flag. Other cards' unsaved edits stay in
 * the form and stay unsaved.
 *
 * `afterSave` values replace state that should not outlive a save (a typed password, a chosen file).
 */
export function useSettingsSubmit<T extends Record<string, any>>(
    group: SettingsGroup,
    form: InertiaFormProps<T>,
    options: { files?: boolean; afterSave?: Partial<T> } = {},
) {
    const saved = useRef<Record<string, unknown>>({ ...form.data });
    const [, refresh] = useState(0);
    const [saving, setSaving] = useState<string[]>([]);

    const dirty = (fields: string[]) =>
        fields.some((f) => !sameValue(form.data[f], saved.current[f]));

    const save = (fields: string[]) => {
        const target = HrmSettingsController.update(group);
        const sent = Object.fromEntries(fields.map((f) => [f, form.data[f]]));
        const visit = {
            preserveScroll: true,
            onStart: () => setSaving(fields),
            onFinish: () => setSaving([]),
            onSuccess: () => {
                const reset = Object.fromEntries(
                    fields
                        .filter(
                            (f) => options.afterSave && f in options.afterSave,
                        )
                        .map((f) => [
                            f,
                            (options.afterSave as Record<string, unknown>)[f],
                        ]),
                );

                for (const [key, value] of Object.entries(reset)) {
                    form.setData(key as never, value as never);
                }

                saved.current = { ...saved.current, ...sent, ...reset };
                refresh((n) => n + 1);
            },
        };

        form.transform((data) => ({
            ...Object.fromEntries(fields.map((f) => [f, data[f]])),
            // PHP does not read a multipart body on PUT, so a file upload goes as POST with the method spoofed.
            ...(options.files ? { _method: 'put' } : {}),
        }));

        if (options.files) {
            form.post(target.url, { ...visit, forceFormData: true });
        } else {
            form.submit(target, visit);
        }
    };

    const card = (fields: string[]) => ({
        state: {
            processing:
                form.processing && fields.some((f) => saving.includes(f)),
            dirty: dirty(fields),
        } as FormState,
        onSave: () => save(fields),
    });

    return {
        card,
        /** Any unsaved change anywhere in the group. */
        state: {
            processing: form.processing,
            dirty: dirty(Object.keys(saved.current)),
        } as FormState,
    };
}

type CardProps = {
    title: string;
    description?: string;
    state: FormState;
    /** Saves this card's fields. Omitted on a card with its own footer. */
    onSave?: () => void;
    children: ReactNode;
    /** Replaces the Save Changes button, for a card that does something else (test email). */
    footer?: ReactNode;
};

/** A titled card with its own Save Changes button. Lives inside the group's <form>. */
export function SettingsCard({
    title,
    description,
    state,
    onSave,
    children,
    footer,
}: CardProps) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <div className="border-b px-5 py-4">
                <h2 className="text-base font-semibold">{title}</h2>
                {description && (
                    <p className="mt-0.5 text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            <div className="grid gap-5 px-5 py-5">{children}</div>
            <div className="flex items-center justify-end gap-3 border-t bg-muted/40 px-5 py-3">
                {footer ?? (
                    <>
                        {state.dirty && !state.processing && (
                            <span className="mr-auto text-sm text-muted-foreground">
                                Unsaved changes
                            </span>
                        )}
                        <Button
                            type="button"
                            onClick={onSave}
                            disabled={state.processing}
                        >
                            {state.processing && <Spinner />}
                            Save Changes
                        </Button>
                    </>
                )}
            </div>
        </section>
    );
}

type FieldProps = {
    id: string;
    label: string;
    hint?: ReactNode;
    error?: string;
    children: ReactNode;
    className?: string;
};

/** Label, control, hint and error text, wired together for assistive technology. */
export function Field({
    id,
    label,
    hint,
    error,
    children,
    className,
}: FieldProps) {
    return (
        <div className={className ?? 'grid content-start gap-2'}>
            <Label htmlFor={id}>{label}</Label>
            {children}
            {hint && (
                <p id={`${id}-hint`} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            )}
            <InputError id={`${id}-error`} role="alert" message={error} />
        </div>
    );
}

/** aria props for a control inside <Field>. */
export function fieldAria(
    id: string,
    error: string | undefined,
    hasHint = false,
) {
    const described = [
        hasHint ? `${id}-hint` : null,
        error ? `${id}-error` : null,
    ]
        .filter(Boolean)
        .join(' ');

    return {
        id,
        'aria-invalid': error ? true : undefined,
        'aria-describedby': described || undefined,
    } as const;
}
