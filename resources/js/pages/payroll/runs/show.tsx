import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    BadgeCheck,
    CalendarCheck,
    Download,
    FileSpreadsheet,
    Mail,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { ReactNode } from 'react';
import PayrollRunController from '@/actions/App/Http/Controllers/Payroll/PayrollRunController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { ApproveRunDialog } from '@/components/payroll/approve-run-dialog';
import { LineDrawer } from '@/components/payroll/line-drawer';
import { MarkPaidDialog } from '@/components/payroll/mark-paid-dialog';
import { RunChecks } from '@/components/payroll/run-checks';
import { RunLinesTable } from '@/components/payroll/run-lines-table';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatMoney, runStatusPill } from '@/lib/payroll';
import type { RunCheck, RunLine } from '@/lib/payroll';
import { index, show } from '@/routes/payroll/runs';

type Run = {
    id: number;
    month: string;
    label: string;
    status: string;
    headcount: number;
    /** Paisa. */
    gross: number;
    deductions: number;
    tax: number;
    net: number;
    generated_by: string | null;
    generated_on: string | null;
    calculated_on: string | null;
    approved_by: string | null;
    approved_on: string | null;
    paid_by: string | null;
    paid_on: string | null;
    /** Digest of the figures this page shows; sent back on approve. */
    fingerprint: string;
    can_edit: boolean;
    can_approve: boolean;
    can_pay: boolean;
    approves_own: boolean;
    payslip_count: number;
    emailed_count: number;
    today: string;
};

type Person = { id: number; name: string; code: string };

type Props = {
    run: Run;
    checks: RunCheck[];
    lines: RunLine[];
    settled_separately: (Person & {
        last_working_day: string;
        settlement: 'draft' | 'approved' | 'paid' | null;
    })[];
    left_without_last_day: (Person & { status: string })[];
    downloads: {
        bank_sheet: string | null;
        summary: string | null;
        payslips: string | null;
    };
};

function settlementNote(status: 'draft' | 'approved' | 'paid' | null) {
    switch (status) {
        case 'draft':
            return 'Settlement in draft';
        case 'approved':
            return 'Settlement approved';
        case 'paid':
            return 'Settlement paid';
        default:
            return 'No settlement yet';
    }
}

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 rounded-xl border bg-card px-4 py-3 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-base font-semibold whitespace-nowrap tabular-nums sm:text-lg">
                {children}
            </dd>
        </div>
    );
}

function PeopleList({
    title,
    hint,
    people,
}: {
    title: string;
    hint: string;
    people: { id: number; name: string; code: string; note: string }[];
}) {
    return (
        <section className="overflow-hidden rounded-xl border bg-card shadow-xs">
            <div className="border-b px-4 py-3">
                <h2 className="font-medium">{title}</h2>
                <p className="text-sm text-muted-foreground">{hint}</p>
            </div>
            <ul className="divide-y text-sm">
                {people.map((person) => (
                    <li
                        key={person.id}
                        className="flex items-baseline justify-between gap-3 px-4 py-2"
                    >
                        <span>
                            {person.name}
                            <span className="ml-2 text-xs text-muted-foreground">
                                {person.code}
                            </span>
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {person.note}
                        </span>
                    </li>
                ))}
            </ul>
        </section>
    );
}

export default function RunShow({
    run,
    checks,
    lines,
    settled_separately: settled,
    left_without_last_day: left,
    downloads,
}: Props) {
    const [dialog, setDialog] = useState<'approve' | 'paid' | 'delete' | null>(
        null,
    );
    const [open, setOpen] = useState<number | null>(null);
    const [recalculating, setRecalculating] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const pill = runStatusPill(run.status);
    const draft = run.status === 'draft';
    const blocking = checks.some((c) => c.severity === 'block');
    const openLine = lines.find((l) => l.id === open) ?? null;

    const failed = (errors: Record<string, string>) =>
        toast.error(
            Object.values(errors)[0] ?? 'Something went wrong. Try again.',
        );

    const recalculate = () =>
        router.post(
            PayrollRunController.recalculate(run.id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setRecalculating(true),
                onFinish: () => setRecalculating(false),
                onError: failed,
            },
        );

    const remove = () =>
        router.delete(PayrollRunController.destroy(run.id).url, {
            onStart: () => setDeleting(true),
            onFinish: () => setDeleting(false),
            // Only a deleted draft closes the dialog (the page then leaves); a refusal keeps it open with a toast.
            onSuccess: () => setDialog(null),
            onError: failed,
        });

    const history = [
        run.calculated_on && `Calculated ${run.calculated_on}`,
        run.approved_on &&
            `Approved ${run.approved_on}${run.approved_by ? ` by ${run.approved_by}` : ''}`,
        run.paid_on &&
            `Paid ${run.paid_on}${run.paid_by ? ` (recorded by ${run.paid_by})` : ''}`,
    ].filter(Boolean);

    return (
        <>
            <Head title={`Payroll · ${run.label}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={`Payroll for ${run.label}`}
                    subtitle={history.join(' · ') || undefined}
                    actions={
                        <>
                            <Button asChild variant="ghost">
                                <Link href={index()}>
                                    <ArrowLeft />
                                    All runs
                                </Link>
                            </Button>
                            {run.can_edit && (
                                <Button
                                    variant="outline"
                                    onClick={recalculate}
                                    disabled={recalculating}
                                >
                                    {recalculating ? (
                                        <Spinner />
                                    ) : (
                                        <RefreshCw />
                                    )}
                                    Recalculate
                                </Button>
                            )}
                            {run.can_approve && (
                                <Button
                                    onClick={() => setDialog('approve')}
                                    disabled={blocking || lines.length === 0}
                                    title={
                                        blocking
                                            ? 'Fix the blocking checks first'
                                            : undefined
                                    }
                                >
                                    <BadgeCheck />
                                    Approve
                                </Button>
                            )}
                            {run.can_pay && (
                                <Button onClick={() => setDialog('paid')}>
                                    <CalendarCheck />
                                    Mark paid
                                </Button>
                            )}
                            {run.can_edit && (
                                <Button
                                    variant="destructive"
                                    onClick={() => setDialog('delete')}
                                >
                                    <Trash2 />
                                    Delete draft
                                </Button>
                            )}
                        </>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <StatusPill tone={pill.tone}>{pill.label}</StatusPill>
                    {draft && !run.can_approve && (
                        <span className="text-sm text-muted-foreground">
                            {run.approves_own
                                ? 'This payroll includes your own pay; another Admin must approve it.'
                                : 'Only an Admin can approve this payroll.'}
                        </span>
                    )}
                    {draft && run.can_approve && blocking && (
                        <span className="text-sm text-muted-foreground">
                            Approval is blocked until the red checks are fixed.
                        </span>
                    )}
                </div>

                <dl className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <Tile label="People">{run.headcount}</Tile>
                    <Tile label="Gross pay">{formatMoney(run.gross)}</Tile>
                    <Tile label="Deductions">
                        {formatMoney(run.deductions)}
                    </Tile>
                    <Tile label="Tax">{formatMoney(run.tax)}</Tile>
                    <Tile label="Net pay">{formatMoney(run.net)}</Tile>
                </dl>

                {(downloads.bank_sheet ||
                    downloads.summary ||
                    downloads.payslips) && (
                    <div className="flex flex-wrap gap-2">
                        {downloads.bank_sheet && (
                            <Button asChild variant="outline">
                                <a href={downloads.bank_sheet}>
                                    <Download />
                                    Bank transfer sheet
                                </a>
                            </Button>
                        )}
                        {downloads.summary && (
                            <Button asChild variant="outline">
                                <a href={downloads.summary}>
                                    <FileSpreadsheet />
                                    Payroll summary
                                </a>
                            </Button>
                        )}
                        {downloads.payslips && (
                            <Button asChild variant="outline">
                                <Link href={downloads.payslips}>
                                    <Mail />
                                    Payslips
                                </Link>
                            </Button>
                        )}
                    </div>
                )}

                <RunChecks checks={checks} draft={draft} />

                <RunLinesTable lines={lines} onOpen={(l) => setOpen(l.id)} />

                {settled.length > 0 && (
                    <PeopleList
                        title="Settled separately"
                        hint="Their last working day is in this month, so they are paid through the final settlement, not this payroll."
                        people={settled.map((p) => ({
                            ...p,
                            note: `Last day ${p.last_working_day} · ${settlementNote(p.settlement)}`,
                        }))}
                    />
                )}
                {left.length > 0 && (
                    <PeopleList
                        title="Left without a last working day"
                        hint="They are not paid by this payroll. Set their last working day on their record."
                        people={left.map((p) => ({ ...p, note: p.status }))}
                    />
                )}
            </div>

            <LineDrawer
                runId={run.id}
                line={openLine}
                onClose={() => setOpen(null)}
            />

            {dialog === 'approve' && (
                <ApproveRunDialog
                    runId={run.id}
                    label={run.label}
                    headcount={run.headcount}
                    gross={run.gross}
                    tax={run.tax}
                    net={run.net}
                    payslipCount={run.payslip_count}
                    emailedCount={run.emailed_count}
                    approvesOwn={run.approves_own}
                    fingerprint={run.fingerprint}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'paid' && (
                <MarkPaidDialog
                    runId={run.id}
                    label={run.label}
                    month={run.month}
                    today={run.today}
                    onClose={() => setDialog(null)}
                />
            )}
            <ConfirmDialog
                open={dialog === 'delete'}
                onOpenChange={(next) => !next && setDialog(null)}
                title={`Delete the draft for ${run.label}?`}
                description="The calculated lines and the adjustments you added are removed. You can generate the month again later."
                confirmLabel="Delete draft"
                processing={deleting}
                onConfirm={remove}
            />
        </>
    );
}

RunShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Payroll Runs', href: index() },
        { title: props.run.label, href: show(props.run.id) },
    ],
});
