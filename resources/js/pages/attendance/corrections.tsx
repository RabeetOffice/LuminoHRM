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
import { correctionState, timePair } from '@/lib/corrections';
import type { CorrectionStage, CorrectionStatus } from '@/lib/corrections';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { index } from '@/routes/attendance/corrections';

type Tab = 'pending' | 'approved' | 'rejected' | 'cancelled';

type Times = { clock_in: string | null; clock_out: string | null };

type CorrectionRow = {
    id: number;
    employee: { id: number; name: string; code: string };
    /** "Y-m-d", never passed through Date. */
    date: string;
    current: Times & { is_manual: boolean };
    requested: Times;
    reason: string;
    status: CorrectionStatus;
    stage: CorrectionStage;
    decision_note: string | null;
    manager_decider: string | null;
    hr_decider: string | null;
    is_own: boolean;
    can: { decide: 'manager' | 'hr' | null; cancel: boolean };
};

type Props = {
    tab: Tab;
    requests: DataTablePagination & { data: CorrectionRow[] };
    counts: Record<Tab, number>;
};

type Deciding = { row: CorrectionRow; decision: 'approve' | 'reject' };

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
                    <span className="font-medium">
                        <span className="sr-only">Requested: </span>
                        {timePair(
                            row.requested.clock_in,
                            row.requested.clock_out,
                        )}
                    </span>
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
                const by = row.hr_decider ?? row.manager_decider;

                return (
                    <div className="grid justify-items-start gap-1">
                        <StatusPill tone={state.tone}>{state.label}</StatusPill>
                        {row.status === 'manager_approved' &&
                            row.manager_decider && (
                                <span className="text-xs text-muted-foreground">
                                    Manager approved: {row.manager_decider}
                                </span>
                            )}
                        {row.stage === null && by && (
                            <span className="text-xs text-muted-foreground">
                                By {by}
                            </span>
                        )}
                        {row.stage === null && row.decision_note && (
                            <span
                                className="line-clamp-2 max-w-60 text-xs text-muted-foreground"
                                title={row.decision_note}
                            >
                                “{row.decision_note}”
                            </span>
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
                    {row.can.decide && (
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
                    subtitle="Employees ask for a missed or wrong punch to be corrected. The reporting manager decides first, then HR; an approved request corrects the day."
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
                            key={`${deciding.row.id}-${deciding.decision}`}
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
    const { row, decision } = deciding;
    const approve = decision === 'approve';
    const [note, setNote] = useState('');
    const [error, setError] = useState<string | undefined>();
    const [processing, setProcessing] = useState(false);
    const final = row.can.decide === 'hr';

    const submit = (event: FormEvent) => {
        event.preventDefault();

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

    return (
        <form onSubmit={submit} className="grid gap-5" noValidate>
            <DialogHeader>
                <DialogTitle>
                    {approve ? 'Approve request' : 'Reject request'}
                </DialogTitle>
                <DialogDescription>
                    {row.employee.name} · {formatDate(row.date, dateFormat)}.{' '}
                    {approve
                        ? final
                            ? 'The day will be corrected to the requested times.'
                            : 'The request then goes to HR for the final decision.'
                        : 'The employee will see your note. A rejection is final.'}
                </DialogDescription>
            </DialogHeader>

            <dl className="grid gap-2 rounded-lg border bg-muted/40 p-3 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-4">
                <dt className="text-muted-foreground">Recorded</dt>
                <dd className="tabular-nums">
                    {timePair(row.current.clock_in, row.current.clock_out)}
                </dd>
                <dt className="text-muted-foreground">Requested</dt>
                <dd className="font-medium tabular-nums">
                    {timePair(row.requested.clock_in, row.requested.clock_out)}
                </dd>
                <dt className="text-muted-foreground">Reason</dt>
                <dd className="break-words">{row.reason}</dd>
            </dl>

            <Field
                id="decision-note"
                label={approve ? 'Note (optional)' : 'Why is it rejected?'}
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
                <Button
                    type="submit"
                    variant={approve ? 'default' : 'destructive'}
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {approve ? 'Approve' : 'Reject'}
                </Button>
            </DialogFooter>
        </form>
    );
}

Corrections.layout = {
    breadcrumbs: [{ title: 'Correction Requests', href: index() }],
};
