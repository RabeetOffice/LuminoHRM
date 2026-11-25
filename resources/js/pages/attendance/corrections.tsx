import { Head, router, usePage } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import CorrectionController from '@/actions/App/Http/Controllers/Attendance/CorrectionController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { correctionState, requestedSides, timePair } from '@/lib/corrections';
import type {
    CorrectionStage,
    CorrectionStatus,
    CorrectionTimes,
} from '@/lib/corrections';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { index } from '@/routes/attendance/corrections';

type Tab = 'pending' | 'approved' | 'rejected' | 'cancelled';

type Times = CorrectionTimes;

type CorrectionRow = {
    id: number;
    employee: { id: number; name: string; code: string };
    /** "Y-m-d", never passed through Date. */
    date: string;
    current: Times & { is_manual: boolean };
    /** A null side keeps what is recorded when the request is approved. */
    requested: Times;
    reason: string;
    status: CorrectionStatus;
    stage: CorrectionStage;
    /** The routed manager while the request waits for the manager. */
    manager_name: string | null;
    manager_note: string | null;
    hr_note: string | null;
    manager_decider: string | null;
    hr_decider: string | null;
    is_own: boolean;
    can: {
        decide: 'manager' | 'hr' | null;
        /** Deciding now as HR skips the manager, who has not answered yet. */
        skips_manager: boolean;
        cancel: boolean;
    };
};

type Props = {
    tab: Tab;
    requests: DataTablePagination & { data: CorrectionRow[] };
    counts: Record<Tab, number>;
};

type Deciding = {
    row: CorrectionRow;
    /** Null: the form offers both (a decision as HR that skips the manager). */
    decision: 'approve' | 'reject' | null;
};

const ID_PREFIX = 'corrections';

const TAB_LABELS: Record<Tab, string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

const EMPTY: Record<Tab, string> = {
    pending: 'No requests are waiting.',
    approved: 'No approved requests yet.',
    rejected: 'No rejected requests.',
    cancelled: 'No cancelled requests.',
};

export default function Corrections({ tab, requests, counts }: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;

    // The last row asked about stays set while a dialog animates closed, so its text does not change mid-close.
    const [deciding, setDeciding] = useState<Deciding | null>(null);
    const [decideOpen, setDecideOpen] = useState(false);
    const [cancelling, setCancelling] = useState<CorrectionRow | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const switchTab = (key: string) => {
        if (key === tab) {
            return;
        }

        router.get(
            nextTableUrl(url, {
                tab: key === 'pending' ? null : key,
                page: null,
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const decide = (row: CorrectionRow, decision: Deciding['decision']) => {
        setDeciding({ row, decision });
        setDecideOpen(true);
    };

    const cancel = () => {
        if (!cancelling) {
            return;
        }

        router.post(
            CorrectionController.cancel(cancelling.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => {
                    setBusy(false);
                    setCancelOpen(false);
                },
            },
        );
    };

    const columns: DataTableColumn<CorrectionRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">
                        {row.employee.name}
                        {row.is_own && (
                            <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                (you)
                            </span>
                        )}
                    </span>
                    <span className="text-xs text-muted-foreground">
                        {row.employee.code}
                    </span>
                </div>
            ),
        },
        {
            key: 'date',
            header: 'Date',
            className: 'whitespace-nowrap',
            cell: (row) => formatDate(row.date, dateFormat),
        },
        {
            key: 'times',
            header: 'Recorded → Requested',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) => (
                <div className="grid gap-0.5 text-sm">
                    <span className="text-muted-foreground">
                        <span className="sr-only">Recorded: </span>
                        {timePair(row.current.clock_in, row.current.clock_out)}
                        {row.current.is_manual && ' · corrected'}
                    </span>
                    <RequestedTimes row={row} />
                </div>
            ),
        },
        {
            key: 'reason',
            header: 'Reason',
            className: 'hidden min-w-48 max-w-72 md:table-cell',
            cell: (row) => (
                <p className="line-clamp-2 text-sm" title={row.reason}>
                    {row.reason}
                </p>
            ),
        },
        {
            key: 'state',
            header: tab === 'pending' ? 'Stage' : 'Outcome',
            cell: (row) => {
                const state = correctionState(row.status, row.stage);

                return (
                    <div className="grid justify-items-start gap-1">
                        <StatusPill tone={state.tone}>{state.label}</StatusPill>
                        {row.stage === 'manager' && row.manager_name && (
                            <span className="text-xs text-muted-foreground">
                                {row.manager_name}
                            </span>
                        )}
                        {row.manager_decider && (
                            <Decided
                                who={`Manager: ${row.manager_decider}`}
                                note={row.manager_note}
                            />
                        )}
                        {row.hr_decider && (
                            <Decided
                                who={`HR: ${row.hr_decider}`}
                                note={row.hr_note}
                            />
                        )}
                    </div>
                );
            },
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px whitespace-nowrap',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    {row.can.skips_manager && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Decide as HR on the request of ${row.employee.name}, skipping the manager`}
                            onClick={() => decide(row, null)}
                        >
                            Decide as HR (skips manager)
                        </Button>
                    )}
                    {row.can.decide && !row.can.skips_manager && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label={`Approve the request of ${row.employee.name}`}
                                onClick={() => decide(row, 'approve')}
                            >
                                <Check />
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                aria-label={`Reject the request of ${row.employee.name}`}
                                onClick={() => decide(row, 'reject')}
                            >
                                <X />
                                Reject
                            </Button>
                        </>
                    )}
                    {row.can.cancel && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => {
                                setCancelling(row);
                                setCancelOpen(true);
                            }}
                        >
                            Cancel request
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    return (
        <>
            <Head title="Correction Requests" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Correction Requests"
                    subtitle="Employees ask for a missed or wrong punch to be corrected. The reporting manager decides first, then HR; HR can also decide a request the manager has not answered. An approved request corrects the day."
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Request status"
                        idPrefix={ID_PREFIX}
                        value={tab}
                        onChange={switchTab}
                        tabs={(Object.keys(TAB_LABELS) as Tab[]).map((key) => ({
                            key,
                            label: TAB_LABELS[key],
                            count: counts[key],
                        }))}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, tab)}
                    >
                        <DataTable
                            label="Correction requests"
                            bordered={false}
                            columns={columns}
                            rows={requests.data}
                            rowKey={(row) => row.id}
                            pagination={requests}
                            pageParam="page"
                            emptyText={EMPTY[tab]}
                        />
                    </div>
                </div>
            </div>

            <Dialog
                open={decideOpen}
                onOpenChange={(next) => !next && !busy && setDecideOpen(false)}
            >
                <DialogContent className="sm:max-w-lg">
                    {deciding && (
                        <DecisionForm
                            key={`${deciding.row.id}-${deciding.decision ?? 'either'}`}
                            deciding={deciding}
                            dateFormat={dateFormat}
                            onBusy={setBusy}
                            onClose={() => setDecideOpen(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                title="Cancel this request?"
                description={
                    cancelling
                        ? `Your request for ${formatDate(cancelling.date, dateFormat)} will be withdrawn. You can send a new one afterwards.`
                        : ''
                }
                confirmLabel="Cancel request"
                cancelLabel="Keep it"
                processing={busy}
                onConfirm={cancel}
            />
        </>
    );
}

type DecisionFormProps = {
    deciding: Deciding;
    dateFormat: string;
    onBusy: (busy: boolean) => void;
    onClose: () => void;
};

function DecisionForm({
    deciding,
    dateFormat,
    onBusy,
    onClose,
}: DecisionFormProps) {
    const { row } = deciding;
    // Null: a decision as HR that skips the manager; the form offers both.
    const either = deciding.decision === null;
    const approve = deciding.decision === 'approve';
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const final = row.can.decide === 'hr';
    const noteEmpty = note.trim() === '';

    const send = (decision: 'approve' | 'reject') => {
        const target =
            row.can.decide === 'hr'
                ? CorrectionController.hrDecide(row.id)
                : CorrectionController.managerDecide(row.id);

        router.put(
            target.url,
            { decision, note },
            {
                preserveScroll: true,
                onStart: () => {
                    setProcessing(true);
                    onBusy(true);
                },
                onFinish: () => {
                    setProcessing(false);
                    onBusy(false);
                },
                onError: (errors) => setError(errors.note ?? errors.decision),
                // A stale decision comes back as a toast; the list reloads either way.
                onSuccess: () => onClose(),
            },
        );
    };

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (!either && (approve || !noteEmpty)) {
            send(approve ? 'approve' : 'reject');
        }
    };

    const description = either
        ? `The manager${row.manager_name ? ` (${row.manager_name})` : ''} has not decided yet. Your decision is final and skips the manager. Approving corrects the day; a rejection needs a note, which the employee will see.`
        : approve
          ? final
              ? 'The day will be corrected to the requested times.'
              : 'The request then goes to HR for the final decision. The employee may see this note.'
          : 'The employee will see your note. A rejection is final.';

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {either
                        ? 'Decide as HR'
                        : approve
                          ? 'Approve request'
                          : 'Reject request'}
                </DialogTitle>
                <DialogDescription>
                    {row.employee.name} · {formatDate(row.date, dateFormat)}.{' '}
                    {description}
                </DialogDescription>
            </DialogHeader>

            <dl className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
                <dt className="text-muted-foreground">Recorded</dt>
                <dd className="tabular-nums">
                    {timePair(row.current.clock_in, row.current.clock_out)}
                </dd>
                <dt className="text-muted-foreground">Requested</dt>
                <dd>
                    <RequestedTimes row={row} />
                </dd>
                <dt className="text-muted-foreground">Reason</dt>
                <dd className="break-words">{row.reason}</dd>
                {row.manager_note && (
                    <>
                        <dt className="text-muted-foreground">
                            Manager&apos;s note
                        </dt>
                        <dd className="break-words">
                            {row.manager_note}
                            {row.manager_decider && (
                                <span className="text-muted-foreground">
                                    {' '}
                                    ({row.manager_decider})
                                </span>
                            )}
                        </dd>
                    </>
                )}
            </dl>

            <Field
                id="decision-note"
                label={
                    either
                        ? 'Note (required to reject)'
                        : approve
                          ? 'Note (optional)'
                          : 'Why is it rejected?'
                }
                error={error}
            >
                <Textarea
                    {...fieldAria('decision-note', error)}
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={500}
                    rows={3}
                />
            </Field>

            <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                <Button
                    type="button"
                    variant="outline"
                    onClick={onClose}
                    disabled={processing}
                >
                    Back
                </Button>
                {either ? (
                    <>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={processing || noteEmpty}
                            onClick={() => send('reject')}
                        >
                            Reject
                        </Button>
                        <Button
                            type="button"
                            disabled={processing}
                            onClick={() => send('approve')}
                        >
                            {processing && <Spinner />}
                            Approve
                        </Button>
                    </>
                ) : (
                    <Button
                        type="submit"
                        variant={approve ? 'default' : 'destructive'}
                        disabled={processing || (!approve && noteEmpty)}
                    >
                        {processing && <Spinner />}
                        {approve ? 'Approve' : 'Reject'}
                    </Button>
                )}
            </DialogFooter>
        </form>
    );
}

/** The requested sides: a changed side in full weight, a kept side as "keeps recorded". */
function RequestedTimes({ row }: { row: CorrectionRow }) {
    return (
        <span className="flex flex-wrap gap-x-3 gap-y-0.5 text-sm tabular-nums">
            <span className="sr-only">Requested: </span>
            {requestedSides(row.requested, row.current).map((side) => (
                <span
                    key={side.label}
                    className={
                        side.changed
                            ? 'font-medium'
                            : 'text-muted-foreground italic'
                    }
                >
                    {side.label} {side.text}
                    {side.changed && (
                        <span className="sr-only"> (changed)</span>
                    )}
                </span>
            ))}
        </span>
    );
}

/** Who decided a step, with their note. */
function Decided({ who, note }: { who: string; note: string | null }) {
    return (
        <span className="grid max-w-60 gap-0.5 text-xs text-muted-foreground">
            <span>{who}</span>
            {note && (
                <span className="line-clamp-2" title={note}>
                    “{note}”
                </span>
            )}
        </span>
    );
}

Corrections.layout = {
    breadcrumbs: [{ title: 'Correction Requests', href: index() }],
};
