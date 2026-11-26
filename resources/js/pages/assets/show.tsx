import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    HandHelping,
    Pencil,
    Trash2,
    Undo2,
    Wrench,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AssetController from '@/actions/App/Http/Controllers/Assets/AssetController';
import { AssetFormDialog } from '@/components/assets/asset-form-dialog';
import type { AssetFormValues } from '@/components/assets/asset-form-dialog';
import { AssetStatusPill } from '@/components/assets/asset-status-pill';
import { AssignDialog } from '@/components/assets/assign-dialog';
import { HistoryTable } from '@/components/assets/history-table';
import type { AssetHistoryRow } from '@/components/assets/history-table';
import { NoteDialog } from '@/components/assets/note-dialog';
import { ReturnDialog } from '@/components/assets/return-dialog';
import type { ReturnTarget } from '@/components/assets/return-dialog';
import { StatusDialog } from '@/components/assets/status-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { assetConditionLabel, firstError, personLabel } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';
import { index, show } from '@/routes/assets';

type Props = {
    asset: AssetFormValues & {
        asset_code: string;
        category: string | null;
        cost_formatted: string | null;
        status: string;
    };
    holder: ReturnTarget | null;
    history: AssetHistoryRow[];
    can: {
        edit: boolean;
        assign: boolean;
        return: boolean;
        status: boolean;
        delete: boolean;
        retire: boolean;
    };
    status_options: string[];
    categories: { id: number; name: string }[];
    /** The attendance day "Y-m-d". */
    today: string;
};

type Dialog = 'edit' | 'assign' | 'status' | null;

/** The assignment id in `?return=`, or null when it is absent or not a whole number. */
function returnParam(url: string): number | null {
    const value = new URLSearchParams(url.split('?')[1] ?? '').get('return');

    return value !== null && /^\d+$/.test(value) ? Number(value) : null;
}

function Detail({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs font-medium text-muted-foreground">
                {label}
            </dt>
            <dd className="text-sm break-words">{children}</dd>
        </div>
    );
}

export default function AssetPage({
    asset,
    holder,
    history,
    can,
    status_options,
    categories,
    today,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [dialog, setDialog] = useState<Dialog>(null);
    // The Return dialog works from a copy of the open hand-over taken when it opens: the live `holder` changes under it
    // when someone else returns or re-assigns the asset, and the dialog must neither vanish nor retarget. `?return={id}`
    // (the Assignments list's Return button) opens it for that assignment while it is still open.
    const [returning, setReturning] = useState<ReturnTarget | null>(() =>
        holder !== null && returnParam(url) === holder.assignment_id
            ? { ...holder }
            : null,
    );
    const [noteRow, setNoteRow] = useState<AssetHistoryRow | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);
    const close = () => setDialog(null);

    // The parameter has done its job: drop it so a reload does not reopen the dialog.
    const hadReturnParam = returnParam(url) !== null;
    useEffect(() => {
        if (hadReturnParam) {
            router.replace({
                url: show.url(asset.id),
                preserveState: true,
                preserveScroll: true,
            });
        }
    }, [hadReturnParam, asset.id]);

    const closeConfirm = (open: boolean) => {
        setConfirmOpen(open);

        if (!open) {
            setDeleteError(null);
        }
    };

    // The dialog stays open when the delete is refused (the asset was handed out since this page loaded, key `asset`)
    // and says why; on success the server redirects to the list.
    const remove = () =>
        router.delete(AssetController.destroy(asset.id).url, {
            onStart: () => {
                setBusy(true);
                setDeleteError(null);
            },
            onError: (errors) =>
                setDeleteError(
                    firstError(errors, ['asset']) ??
                        'The asset could not be deleted.',
                ),
            onFinish: () => setBusy(false),
        });

    return (
        <>
            <Head title={`${asset.asset_code} ${asset.name}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <Link
                    href={index()}
                    className="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft className="size-4" />
                    All assets
                </Link>

                <PageHeader
                    title={asset.name}
                    subtitle={`${asset.asset_code}${asset.category ? ` · ${asset.category}` : ''}`}
                    actions={
                        <>
                            {can.edit && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('edit')}
                                >
                                    <Pencil />
                                    Edit
                                </Button>
                            )}
                            {can.assign && (
                                <Button onClick={() => setDialog('assign')}>
                                    <HandHelping />
                                    Assign
                                </Button>
                            )}
                            {can.return && (
                                <Button
                                    onClick={() =>
                                        holder && setReturning({ ...holder })
                                    }
                                >
                                    <Undo2 />
                                    Return
                                </Button>
                            )}
                            {can.status && (
                                <Button
                                    variant="outline"
                                    onClick={() => setDialog('status')}
                                >
                                    <Wrench />
                                    Change status
                                </Button>
                            )}
                            {can.delete && (
                                <Button
                                    variant="outline"
                                    className="text-destructive hover:text-destructive"
                                    onClick={() => setConfirmOpen(true)}
                                >
                                    <Trash2 />
                                    Delete
                                </Button>
                            )}
                        </>
                    }
                />

                <section
                    aria-label="Asset details"
                    className="rounded-xl border bg-card p-4 shadow-xs md:p-6"
                >
                    <dl className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        <Detail label="Status">
                            <AssetStatusPill status={asset.status} />
                        </Detail>
                        <Detail label="Held by">
                            {holder ? (
                                <>
                                    {personLabel(holder)}
                                    <span className="block text-xs text-muted-foreground">
                                        since{' '}
                                        {formatDate(
                                            holder.assigned_on,
                                            dateFormat,
                                        )}
                                    </span>
                                </>
                            ) : (
                                '–'
                            )}
                        </Detail>
                        <Detail label="Condition">
                            {assetConditionLabel(asset.condition)}
                        </Detail>
                        <Detail label="Serial number">
                            {asset.serial_number ?? '–'}
                        </Detail>
                        <Detail label="Purchase date">
                            {asset.purchase_date
                                ? formatDate(asset.purchase_date, dateFormat)
                                : '–'}
                        </Detail>
                        <Detail label="Cost">
                            {asset.cost_formatted ?? '–'}
                        </Detail>
                        <Detail label="Notes">
                            <span className="whitespace-pre-line">
                                {asset.notes ?? '–'}
                            </span>
                        </Detail>
                    </dl>
                </section>

                <section aria-labelledby="asset-history" className="grid gap-3">
                    <h2
                        id="asset-history"
                        className="text-lg font-semibold tracking-tight"
                    >
                        History
                    </h2>
                    <HistoryTable rows={history} onEditNotes={setNoteRow} />
                </section>
            </div>

            {dialog === 'edit' && (
                <AssetFormDialog
                    key={`edit-${asset.id}`}
                    asset={asset}
                    categories={categories}
                    onClose={close}
                />
            )}
            {dialog === 'assign' && (
                <AssignDialog asset={asset} today={today} onClose={close} />
            )}
            {returning && (
                <ReturnDialog
                    key={returning.assignment_id}
                    asset={asset}
                    holder={returning}
                    today={today}
                    canRetire={can.retire}
                    onClose={() => setReturning(null)}
                />
            )}
            {noteRow && (
                <NoteDialog
                    key={noteRow.id}
                    assignment={noteRow}
                    onClose={() => setNoteRow(null)}
                />
            )}
            {dialog === 'status' && (
                <StatusDialog
                    asset={asset}
                    options={status_options}
                    onClose={close}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={closeConfirm}
                title="Delete asset?"
                description={
                    <>
                        {`${asset.asset_code} "${asset.name}" will be deleted. It has never been handed out. This cannot be undone.`}
                        {deleteError && (
                            <span
                                role="alert"
                                className="mt-3 block rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
                            >
                                {deleteError}
                            </span>
                        )}
                    </>
                }
                processing={busy}
                onConfirm={remove}
            />
        </>
    );
}

AssetPage.layout = (props: { asset: { id: number; asset_code: string } }) => ({
    breadcrumbs: [
        { title: 'Assets', href: index() },
        { title: props.asset.asset_code, href: show(props.asset.id) },
    ],
});
