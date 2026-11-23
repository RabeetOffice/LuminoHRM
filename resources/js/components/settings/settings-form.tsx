import type { InertiaFormProps } from '@inertiajs/react';
import type { FormEvent, ReactNode } from 'react';
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

/**
 * Saves one settings group from an Inertia form. Each card in the group has its own
 * "Save Changes" button, and any of them saves the whole group (the server validates the
 * group as one, for example shift end after shift start).
 *
 * `afterSave` values replace state that should not outlive a save (a typed password, a chosen file).
 */
export function useSettingsSubmit<T extends Record<string, any>>(
    group: SettingsGroup,
    form: InertiaFormProps<T>,
    options: { files?: boolean; afterSave?: Partial<T> } = {},
) {
    const submit = (event?: FormEvent) => {
        event?.preventDefault();

        const target = HrmSettingsController.update(group);
        const visit = {
            preserveScroll: true,
            onSuccess: () => {
                if (options.afterSave) {
                    const next = { ...form.data, ...options.afterSave };

                    form.setData(next);
                    form.setDefaults(next);
                } else {
                    form.setDefaults();
                }
            },
        };

        if (options.files) {
            // PHP does not read a multipart body on PUT, so send POST and spoof the method.
            form.transform((data) => ({ ...data, _method: 'put' }));
            form.post(target.url, { ...visit, forceFormData: true });
        } else {
            form.submit(target, visit);
        }
    };

    return {
        submit,
        state: {
            processing: form.processing,
            dirty: form.isDirty,
        } as FormState,
    };
}

type CardProps = {
    title: string;
    description?: string;
    state: FormState;
    children: ReactNode;
    /** Replaces the Save Changes button, for a card that does something else (test email). */
    footer?: ReactNode;
};

/** A titled card with its own Save Changes button. Lives inside the group's <form>. */
export function SettingsCard({
    title,
    description,
    state,
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
                                Unsaved changes in this section
                            </span>
                        )}
                        <Button type="submit" disabled={state.processing}>
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
