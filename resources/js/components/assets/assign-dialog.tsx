import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import AssetController from '@/actions/App/Http/Controllers/Assets/AssetController';
import AssignmentController from '@/actions/App/Http/Controllers/Assets/AssignmentController';
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
import { filterPeople, firstError, personLabel } from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';
import { cn } from '@/lib/utils';

type Props = {
    asset: { id: number; asset_code: string; name: string };
    /** Active and probation people (the first 500). */
    employees: AssetPerson[];
    /** True when there are more people than `employees` holds: the picker then searches on the server. */
    truncated: boolean;
    /** The attendance day "Y-m-d": the default hand-over date and the latest one the server accepts. */
    today: string;
    onClose: () => void;
};

const SEARCH_DELAY_MS = 250;

/**
 * Hand the asset to someone (assets.assign). Mount it only while open. The server's refusals show in the dialog: why
 * the asset cannot be assigned (key `asset`), why this person cannot get it (`employee_id`) and the date rules
 * (`assigned_on`).
 */
export function AssignDialog({
    asset,
    employees,
    truncated,
    today,
    onClose,
}: Props) {
    const form = useForm({ employee_id: '', assigned_on: today, note: '' });
    const { data, errors } = form;
    // Refusals for the whole action have no field of their own.
    const general = firstError(errors as Record<string, string | undefined>, [
        'asset',
    ]);
    const [term, setTerm] = useState('');
    const [found, setFound] = useState<AssetPerson[] | null>(null);
    const [picked, setPicked] = useState<AssetPerson | null>(null);

    // With a cut-short list the server does the searching, a moment after typing stops.
    useEffect(() => {
        if (!truncated || term.trim() === '') {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(
                AssetController.employeeOptions({ query: { q: term.trim() } })
                    .url,
                {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                },
            )
                .then((response) => (response.ok ? response.json() : null))
                .then((json: { employees: AssetPerson[] } | null) => {
                    setFound(json?.employees ?? []);
                })
                .catch(() => undefined);
        }, SEARCH_DELAY_MS);

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [term, truncated]);

    const shown =
        truncated && term.trim() !== ''
            ? (found ?? [])
            : filterPeople(employees, term).slice(0, 50);

    const choose = (person: AssetPerson) => {
        setPicked(person);
        form.setData('employee_id', String(person.id));
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(AssignmentController.assign(asset.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Assign {asset.asset_code}</DialogTitle>
                        <DialogDescription>
                            {asset.name}. Choose who gets it and the day they
                            received it. Only active and probation employees can
                            be given an asset.
                        </DialogDescription>
                    </DialogHeader>

                    {general && (
                        <p
                            role="alert"
                            className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                        >
                            {general}
                        </p>
                    )}

                    <Field
                        id="assign-search"
                        label="Employee"
                        error={errors.employee_id}
                    >
                        <Input
                            {...fieldAria('assign-search', errors.employee_id)}
                            type="search"
                            value={term}
                            onChange={(e) => setTerm(e.target.value)}
                            placeholder="Search by name or employee ID"
                            autoComplete="off"
                        />
                        <ul
                            role="listbox"
                            aria-label="Employees"
                            className="max-h-44 divide-y overflow-y-auto rounded-lg border"
                        >
                            {shown.length === 0 ? (
                                <li className="px-3 py-2 text-sm text-muted-foreground">
                                    No one matches.
                                </li>
                            ) : (
                                shown.map((person) => (
                                    <li
                                        key={person.id}
                                        role="option"
                                        aria-selected={
                                            data.employee_id ===
                                            String(person.id)
                                        }
                                    >
                                        <button
                                            type="button"
                                            onClick={() => choose(person)}
                                            className={cn(
                                                'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none',
                                                data.employee_id ===
                                                    String(person.id) &&
                                                    'bg-muted font-medium',
                                            )}
                                        >
                                            <span className="truncate">
                                                {person.name}
                                            </span>
                                            <span className="shrink-0 text-xs text-muted-foreground">
                                                {person.code}
                                            </span>
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>
                        <p
                            className="text-xs text-muted-foreground"
                            aria-live="polite"
                        >
                            {picked
                                ? `Chosen: ${personLabel(picked)}`
                                : 'No one chosen yet.'}
                        </p>
                    </Field>

                    <Field
                        id="assign-date"
                        label="Handed over on"
                        error={errors.assigned_on}
                    >
                        <Input
                            {...fieldAria('assign-date', errors.assigned_on)}
                            type="date"
                            max={today}
                            value={data.assigned_on}
                            onChange={(e) =>
                                form.setData('assigned_on', e.target.value)
                            }
                            className="w-44"
                        />
                    </Field>

                    <Field
                        id="assign-note"
                        label="Note"
                        error={errors.note}
                        hint="Optional. For example: charger and bag included."
                    >
                        <Textarea
                            {...fieldAria('assign-note', errors.note, true)}
                            value={data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                            maxLength={500}
                            rows={2}
                        />
                    </Field>

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
                            Assign
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
