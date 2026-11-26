import { useForm } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import ExitController from '@/actions/App/Http/Controllers/Exits/ExitController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { exitTypeLabel, generalErrors, personText } from '@/lib/exit';
import { cn } from '@/lib/utils';

type Person = {
    id: number;
    code: string;
    name: string;
    department: string | null;
    joining_date: string | null;
};

type Props = {
    /** The attendance day "Y-m-d": the default submitted-on day and the latest the server accepts. */
    today: string;
    types: string[];
    /** Open on this person (the profile's "Record exit"), skipping the search. */
    person?: { id: number; code: string; name: string };
    onClose: () => void;
};

const FIELDS = [
    'employee_id',
    'type',
    'last_working_day',
    'submitted_on',
    'reason',
    'approve_now',
];
const DELAY_MS = 250;

/**
 * HR records a resignation or termination (exit.clearance). Mount it only while open. The person is searched on the server
 * as you type (active and probation people with no open exit, never yourself); a failed search shows a retry, never stale
 * results. The server's refusals show in the dialog: the day and reason rules under their fields, everything else (an open
 * exit, the Admin-only rule, a locked month) above the form.
 */
export function RecordExitDialog({ today, types, person, onClose }: Props) {
    const form = useForm({
        employee_id: person ? String(person.id) : '',
        type: types[0] ?? 'resignation',
        last_working_day: today,
        submitted_on: today,
        reason: '',
        approve_now: true,
    });
    const { data, errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        FIELDS,
    );
    const [picked, setPicked] = useState<{
        id: number;
        code: string;
        name: string;
    } | null>(person ?? null);
    const [term, setTerm] = useState('');
    const [found, setFound] = useState<{
        term: string;
        people: Person[];
    } | null>(null);
    const [failedFor, setFailedFor] = useState<string | null>(null);
    const [attempt, setAttempt] = useState(0);
    const needle = term.trim();
    const searching = picked === null;

    useEffect(() => {
        if (!searching) {
            return;
        }

        const controller = new AbortController();
        const timer = window.setTimeout(
            () => {
                fetch(
                    ExitController.employeeOptions({ query: { q: needle } })
                        .url,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                )
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error('failed');
                        }

                        return response.json() as Promise<{
                            employees: Person[];
                        }>;
                    })
                    .then((json) =>
                        setFound({ term: needle, people: json.employees }),
                    )
                    .catch(() => {
                        if (!controller.signal.aborted) {
                            setFailedFor(needle);
                        }
                    });
            },
            needle === '' ? 0 : DELAY_MS,
        );

        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [searching, needle, attempt]);

    const failed = failedFor === needle;
    const loading = searching && !failed && found?.term !== needle;
    const shown = found?.term === needle ? found.people : [];

    const choose = (p: Person) => {
        setPicked(p);
        form.setData('employee_id', String(p.id));
    };

    const submit = () => {
        form.submit(ExitController.store(), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogFrame
            title="Record an exit"
            description="Record a resignation or termination for someone. It goes straight to the HR step; tick Approve now to approve it in the same step."
            submitLabel="Record exit"
            processing={form.processing}
            onClose={onClose}
            onSubmit={submit}
            general={general}
        >
            <Field id="exit-person" label="Employee" error={errors.employee_id}>
                {picked ? (
                    <div className="flex items-center justify-between gap-3 rounded-lg border px-3 py-2 text-sm">
                        <span className="font-medium">
                            {personText(picked)}
                        </span>
                        {!person && (
                            <button
                                type="button"
                                className="text-sm text-brand-ink hover:underline"
                                onClick={() => {
                                    setPicked(null);
                                    form.setData('employee_id', '');
                                }}
                            >
                                Change
                            </button>
                        )}
                    </div>
                ) : (
                    <>
                        <Input
                            {...fieldAria('exit-person', errors.employee_id)}
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
                            {failed ? (
                                <li className="flex items-center justify-between gap-3 px-3 py-2 text-sm text-destructive">
                                    Could not load people.
                                    <button
                                        type="button"
                                        className="font-medium underline"
                                        onClick={() => {
                                            setFailedFor(null);
                                            setAttempt((n) => n + 1);
                                        }}
                                    >
                                        Try again
                                    </button>
                                </li>
                            ) : loading ? (
                                <li className="px-3 py-2 text-sm text-muted-foreground">
                                    Searching…
                                </li>
                            ) : shown.length === 0 ? (
                                <li className="px-3 py-2 text-sm text-muted-foreground">
                                    No one matches. Only active and probation
                                    people with no open exit are listed.
                                </li>
                            ) : (
                                shown.map((p) => (
                                    <li
                                        key={p.id}
                                        role="option"
                                        aria-selected={false}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => choose(p)}
                                            className={cn(
                                                'flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm hover:bg-accent',
                                            )}
                                        >
                                            <span className="font-medium">
                                                {p.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {p.code}
                                                {p.department &&
                                                    ` · ${p.department}`}
                                            </span>
                                        </button>
                                    </li>
                                ))
                            )}
                        </ul>
                    </>
                )}
            </Field>

            <div className="grid gap-5 sm:grid-cols-2">
                <Field id="exit-type" label="Type" error={errors.type}>
                    <Select
                        value={data.type}
                        onValueChange={(value) => form.setData('type', value)}
                    >
                        <SelectTrigger
                            {...fieldAria('exit-type', errors.type)}
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {types.map((type) => (
                                <SelectItem key={type} value={type}>
                                    {exitTypeLabel(type)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field
                    id="exit-last-day"
                    label="Last working day"
                    error={errors.last_working_day}
                >
                    <Input
                        {...fieldAria('exit-last-day', errors.last_working_day)}
                        type="date"
                        value={data.last_working_day}
                        onChange={(e) =>
                            form.setData('last_working_day', e.target.value)
                        }
                    />
                </Field>
            </div>

            <Field
                id="exit-submitted"
                label="Submitted on"
                error={errors.submitted_on}
                hint="The day the person gave notice. Not in the future."
            >
                <Input
                    {...fieldAria('exit-submitted', errors.submitted_on, true)}
                    type="date"
                    max={today}
                    value={data.submitted_on}
                    onChange={(e) =>
                        form.setData('submitted_on', e.target.value)
                    }
                    className="sm:w-56"
                />
            </Field>

            <Field
                id="exit-reason"
                label="Reason"
                error={errors.reason}
                hint="Required. At most 1,000 characters."
            >
                <Textarea
                    {...fieldAria('exit-reason', errors.reason, true)}
                    value={data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={1000}
                    rows={3}
                />
            </Field>

            <div className="flex items-start gap-3">
                <Checkbox
                    id="exit-approve-now"
                    checked={data.approve_now}
                    onCheckedChange={(checked) =>
                        form.setData('approve_now', checked === true)
                    }
                    className="mt-0.5"
                />
                <Label
                    htmlFor="exit-approve-now"
                    className="grid gap-0.5 font-normal"
                >
                    <span className="font-medium">Approve now</span>
                    <span className="text-xs text-muted-foreground">
                        Gives the final approval in the same step, when you may
                        decide this exit. Leave it unticked to approve later.
                    </span>
                </Label>
            </div>
        </DialogFrame>
    );
}
