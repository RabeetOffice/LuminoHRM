import { Link, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import CycleController from '@/actions/App/Http/Controllers/Performance/CycleController';
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
import { Spinner } from '@/components/ui/spinner';
import { index as kpisIndex } from '@/routes/performance/kpis';

export type NotAddedPerson = {
    id: number;
    code: string;
    name: string;
    department: string | null;
    /** "Can be added" or "No KPIs". */
    reason: string;
    addable: boolean;
};

type Props = {
    cycleId: number;
    cycleName: string;
    people: NotAddedPerson[];
    onClose: () => void;
};

/**
 * Adds people to an open cycle (performance.cycles.people). The list is the people who meet the period rules and are not in
 * the cycle: those with KPIs can be ticked, those without are shown disabled with a link to the KPIs page. The server judges
 * each person again under its lock; its refusals (already in, no KPIs now, cycle closed) show in the dialog and the dialog
 * stays open. Mount it only while open.
 */
export function AddPeopleDialog({
    cycleId,
    cycleName,
    people,
    onClose,
}: Props) {
    const form = useForm<{ employee_ids: number[] }>({ employee_ids: [] });
    const { data } = form;
    const errors: Record<string, string | undefined> = form.errors;
    const addable = people.filter((p) => p.addable);
    const chosen = new Set(data.employee_ids);
    const problem = errors.employee_ids ?? errors.cycle;

    const toggle = (id: number, on: boolean) =>
        form.setData(
            'employee_ids',
            on
                ? [...data.employee_ids, id]
                : data.employee_ids.filter((x) => x !== id),
        );

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(CycleController.people(cycleId), {
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
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Add people to {cycleName}</DialogTitle>
                        <DialogDescription>
                            These people were active in the period and are not
                            in the cycle yet. Each gets the KPIs they have
                            today.
                        </DialogDescription>
                    </DialogHeader>

                    {people.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Everyone who can be reviewed is already in this
                            cycle.
                        </p>
                    ) : (
                        <div className="grid gap-2">
                            <div className="flex items-center justify-between gap-2 text-sm">
                                <span className="text-muted-foreground">
                                    {chosen.size} of {addable.length} chosen
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    disabled={addable.length === 0}
                                    onClick={() =>
                                        form.setData(
                                            'employee_ids',
                                            chosen.size === addable.length
                                                ? []
                                                : addable.map((p) => p.id),
                                        )
                                    }
                                >
                                    {chosen.size === addable.length &&
                                    addable.length > 0
                                        ? 'Clear'
                                        : 'Choose everyone who can be added'}
                                </Button>
                            </div>
                            <ul
                                className="max-h-72 divide-y overflow-y-auto rounded-lg border"
                                aria-label="People who can be added"
                            >
                                {people.map((person) => {
                                    const id = `add-person-${person.id}`;

                                    return (
                                        <li
                                            key={person.id}
                                            className="flex items-start gap-3 px-3 py-2"
                                        >
                                            <Checkbox
                                                id={id}
                                                className="mt-1"
                                                disabled={!person.addable}
                                                checked={chosen.has(person.id)}
                                                onCheckedChange={(on) =>
                                                    toggle(
                                                        person.id,
                                                        on === true,
                                                    )
                                                }
                                            />
                                            <label
                                                htmlFor={id}
                                                className="grid min-w-0 flex-1 gap-0.5 text-sm"
                                            >
                                                <span
                                                    className={
                                                        person.addable
                                                            ? 'font-medium'
                                                            : 'font-medium text-muted-foreground'
                                                    }
                                                >
                                                    {person.name}
                                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                                        {person.code}
                                                    </span>
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {person.department ??
                                                        'No department'}
                                                    {!person.addable &&
                                                        ' · No KPIs'}
                                                </span>
                                            </label>
                                            {!person.addable && (
                                                <Link
                                                    href={kpisIndex()}
                                                    className="shrink-0 text-xs text-brand-ink underline underline-offset-2"
                                                >
                                                    Add KPIs
                                                </Link>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    {problem && (
                        <p
                            role="alert"
                            className="text-sm text-red-600 dark:text-red-400"
                        >
                            {problem}
                        </p>
                    )}

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
                            disabled={form.processing || chosen.size === 0}
                        >
                            {form.processing && <Spinner />}
                            Add{' '}
                            {chosen.size === 0
                                ? 'people'
                                : chosen.size === 1
                                  ? '1 person'
                                  : `${chosen.size} people`}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
