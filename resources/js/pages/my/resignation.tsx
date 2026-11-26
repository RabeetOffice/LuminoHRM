import { Head, useForm, usePage } from '@inertiajs/react';
import { Download, Undo2 } from 'lucide-react';
import { useState } from 'react';
import MyResignationController from '@/actions/App/Http/Controllers/Exits/MyResignationController';
import { ClearancePanel } from '@/components/exit/clearance-panel';
import type { Clearance } from '@/components/exit/clearance-panel';
import { ExitFlow } from '@/components/exit/exit-flow';
import { ExitRequestCard } from '@/components/exit/exit-request-card';
import { ActionError, useExitAction } from '@/components/exit/use-exit-action';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import {
    exitStageText,
    exitStageTone,
    exitTypeLabel,
    generalErrors,
    noticeGiven,
    noticeLabel,
} from '@/lib/exit';
import type { ExitRow, FlowStep } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';
import { resignation as myResignation } from '@/routes/my';

type Current = {
    resignation: ExitRow;
    flow: FlowStep[];
    can: { withdraw: boolean };
    reasons: Record<string, string>;
    clearance?: Clearance;
    statement_url?: string;
    letter_url?: string;
};

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string; status: string; joining_date: string } | null;
    current: Current | null;
    /** Rejected, withdrawn and cancelled exits, newest first. */
    ended: ExitRow[];
    can_submit: boolean;
    /** Why the form is not offered (an open resignation, a status that cannot resign, no record). */
    why_not: string | null;
    form: { today: string; max_days: number; notice_days: number };
};

const FIELDS = ['last_working_day', 'reason'];

/** Today plus N days as "Y-m-d", read as text (no time zone). */
function addDays(day: string, days: number): string {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(day);

    if (!match) {
        return day;
    }

    const date = new Date(
        Date.UTC(
            Number(match[1]),
            Number(match[2]) - 1,
            Number(match[3]) + days,
        ),
    );

    return date.toISOString().slice(0, 10);
}

function ResignForm({ form: rules }: { form: Props['form'] }) {
    const form = useForm({
        last_working_day: addDays(rules.today, rules.notice_days),
        reason: '',
    });
    const { data, errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        FIELDS,
    );
    const given = noticeGiven(rules.today, data.last_working_day);
    const hint =
        given === 0
            ? `Pick a day from today onwards. Your notice period is ${rules.notice_days} days.`
            : noticeLabel(given, rules.notice_days);

    return (
        <section
            className="rounded-xl border bg-card shadow-xs"
            aria-label="Resign"
        >
            <div className="border-b px-5 py-4">
                <h2 className="text-base font-semibold">
                    Submit your resignation
                </h2>
            </div>
            <form
                noValidate
                className="grid max-w-xl gap-5 p-5"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.submit(MyResignationController.store(), {
                        preserveScroll: true,
                    });
                }}
            >
                <p className="text-sm text-muted-foreground">
                    Your manager decides first, then HR. You can withdraw it
                    until HR gives the final approval. Your notice period is{' '}
                    {rules.notice_days} days.
                </p>
                {general.length > 0 && (
                    <Alert variant="destructive" role="alert">
                        <AlertDescription>
                            {general.map((message) => (
                                <span key={message} className="block">
                                    {message}
                                </span>
                            ))}
                        </AlertDescription>
                    </Alert>
                )}
                <Field
                    id="resign-day"
                    label="Last working day"
                    error={errors.last_working_day}
                    hint={hint}
                >
                    <Input
                        {...fieldAria(
                            'resign-day',
                            errors.last_working_day,
                            true,
                        )}
                        type="date"
                        min={rules.today}
                        max={addDays(rules.today, rules.max_days)}
                        value={data.last_working_day}
                        onChange={(e) =>
                            form.setData('last_working_day', e.target.value)
                        }
                        className="sm:w-56"
                    />
                </Field>
                <Field
                    id="resign-reason"
                    label="Reason"
                    error={errors.reason}
                    hint="Required. At most 1,000 characters."
                >
                    <Textarea
                        {...fieldAria('resign-reason', errors.reason, true)}
                        value={data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        maxLength={1000}
                        rows={4}
                    />
                </Field>
                <div>
                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Submit resignation
                    </Button>
                </div>
            </form>
        </section>
    );
}

function CurrentExit({ current }: { current: Current }) {
    const exit = current.resignation;
    const { busy, error, run, clear } = useExitAction();
    const [confirm, setConfirm] = useState(false);

    return (
        <div className="grid gap-6">
            <div className="grid gap-6 xl:grid-cols-2">
                <ExitRequestCard exit={exit} />
                <section
                    className="rounded-xl border bg-card shadow-xs"
                    aria-label="Approval flow"
                >
                    <div className="border-b px-5 py-4">
                        <h2 className="text-base font-semibold">
                            Where it stands
                        </h2>
                    </div>
                    <div className="grid gap-4 p-5">
                        <ExitFlow steps={current.flow} />
                        {current.can.withdraw ? (
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        clear();
                                        setConfirm(true);
                                    }}
                                >
                                    <Undo2 /> Withdraw resignation
                                </Button>
                            </div>
                        ) : (
                            exit.status === 'approved' &&
                            exit.type === 'resignation' && (
                                <p className="text-sm text-muted-foreground">
                                    This resignation can no longer be withdrawn;
                                    ask HR.
                                </p>
                            )
                        )}
                        {exit.type === 'termination' && (
                            <p className="text-sm text-muted-foreground">
                                A termination recorded by HR cannot be
                                withdrawn.
                            </p>
                        )}
                    </div>
                </section>
            </div>

            {current.clearance && (
                <ClearancePanel
                    clearance={{ ...current.clearance, editable: false }}
                    frozen={false}
                />
            )}

            {(current.statement_url || current.letter_url) && (
                <section
                    className="rounded-xl border bg-card shadow-xs"
                    aria-label="Documents"
                >
                    <div className="border-b px-5 py-4">
                        <h2 className="text-base font-semibold">Documents</h2>
                    </div>
                    <div className="flex flex-wrap gap-2 p-5">
                        {current.statement_url && (
                            <Button variant="outline" asChild>
                                <a href={current.statement_url}>
                                    <Download /> Final settlement statement
                                </a>
                            </Button>
                        )}
                        {current.letter_url && (
                            <Button variant="outline" asChild>
                                <a href={current.letter_url}>
                                    <Download /> Experience letter
                                </a>
                            </Button>
                        )}
                    </div>
                </section>
            )}

            {confirm && (
                <ConfirmDialog
                    open
                    onOpenChange={(next) => !next && setConfirm(false)}
                    title="Withdraw your resignation?"
                    description={
                        <>
                            The request ends and you stay employed. You can
                            resign again later.
                            {error.map((message) => (
                                <span
                                    key={message}
                                    role="alert"
                                    className="mt-3 block text-sm text-red-600 dark:text-red-400"
                                >
                                    {message}
                                </span>
                            ))}
                        </>
                    }
                    confirmLabel="Withdraw"
                    destructive
                    processing={busy === 'withdraw'}
                    onConfirm={() =>
                        run(
                            'withdraw',
                            MyResignationController.withdraw(exit.id),
                            {
                                onSuccess: () => setConfirm(false),
                            },
                        )
                    }
                />
            )}
            {!confirm && <ActionError messages={error} />}
        </div>
    );
}

/**
 * My Resignation: resign, or follow the open exit (status, flow, clearance ticks, documents once they exist), and the
 * earlier ones below. The employee never receives a settlement figure: the statement is a download once it is approved.
 */
export default function MyResignation({
    employee,
    current,
    ended,
    can_submit: canSubmit,
    why_not: whyNot,
    form,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;

    return (
        <>
            <Head title="My Resignation" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Resignation"
                    subtitle={
                        employee
                            ? 'Resign, follow the approvals and download your documents.'
                            : undefined
                    }
                />

                {employee === null ? (
                    <div className="rounded-xl border bg-card p-5 text-sm text-muted-foreground shadow-xs">
                        {whyNot ??
                            'Your account is not linked to an employee record.'}
                    </div>
                ) : (
                    <>
                        {current && <CurrentExit current={current} />}
                        {canSubmit && <ResignForm form={form} />}
                        {!canSubmit && !current && whyNot && (
                            <div className="rounded-xl border bg-card p-5 text-sm text-muted-foreground shadow-xs">
                                {whyNot}
                            </div>
                        )}
                    </>
                )}

                {ended.length > 0 && (
                    <section
                        className="rounded-xl border bg-card shadow-xs"
                        aria-label="Earlier exits"
                    >
                        <div className="border-b px-5 py-4">
                            <h2 className="text-base font-semibold">
                                Earlier requests
                            </h2>
                        </div>
                        <ul role="list" className="divide-y">
                            {ended.map((row) => (
                                <li
                                    key={row.id}
                                    className="grid gap-1.5 px-5 py-4 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {exitTypeLabel(row.type)}
                                        </span>
                                        <StatusPill
                                            tone={exitStageTone(
                                                row.status,
                                                row.detail,
                                            )}
                                        >
                                            {exitStageText(row)}
                                        </StatusPill>
                                    </div>
                                    <p className="text-muted-foreground">
                                        Submitted{' '}
                                        {formatDate(
                                            row.submitted_on,
                                            dateFormat,
                                        )}{' '}
                                        · Last working day{' '}
                                        {formatDate(
                                            row.last_working_day,
                                            dateFormat,
                                        )}
                                    </p>
                                    {row.decision_note && (
                                        <p className="break-words whitespace-pre-line">
                                            Note: {row.decision_note}
                                        </p>
                                    )}
                                    {row.cancel_reason && (
                                        <p className="break-words whitespace-pre-line">
                                            Reason: {row.cancel_reason}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

MyResignation.layout = {
    breadcrumbs: [{ title: 'My Resignation', href: myResignation() }],
};
