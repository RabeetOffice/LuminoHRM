import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    HandHelping,
    Pencil,
    Trash2,
    Undo2,
    Wrench,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import AssetController from '@/actions/App/Http/Controllers/Assets/AssetController';
import { AssetFormDialog } from '@/components/assets/asset-form-dialog';
import type { AssetFormValues } from '@/components/assets/asset-form-dialog';
import { AssetStatusPill } from '@/components/assets/asset-status-pill';
import { AssignDialog } from '@/components/assets/assign-dialog';
import { HistoryTable } from '@/components/assets/history-table';
import type { AssetHistoryRow } from '@/components/assets/history-table';
import { ReturnDialog } from '@/components/assets/return-dialog';
import { StatusDialog } from '@/components/assets/status-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { assetConditionLabel, firstError, personLabel } from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';
import { index, show } from '@/routes/assets';

type Props = {
    asset: AssetFormValues & {
        asset_code: string;
        category: string | null;
        cost_formatted: string | null;
        status: string;
    };
    holder:
        | (AssetPerson & { assignment_id: number; assigned_on: string })
        | null;
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
    employees: AssetPerson[];
    employees_truncated: boolean;
    /** The attendance day "Y-m-d". */
    today: string;
};

type Dialog = 'edit' | 'assign' | 'return' | 'status' | null;

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
    employees,
    employees_truncated,
    today,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const [dialog, setDialog] = useState<Dialog>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const close = () => setDialog(null);

    const remove = () =>
        router.delete(AssetController.destroy(asset.id).url, {
            onStart: () => setBusy(true),
            // A late refusal (the asset was handed out since this page loaded) comes back on the key `asset`.
            onError: (errors) => {
                toast.error(
                    firstError(errors, ['asset']) ??
                        'The asset could not be deleted.',
                );
            },
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
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
                                <Button onClick={() => setDialog('return')}>
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
                    <HistoryTable rows={history} />
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
                <AssignDialog
                    asset={asset}
                    employees={employees}
                    truncated={employees_truncated}
                    today={today}
                    onClose={close}
                />
            )}
            {dialog === 'return' && holder && (
                <ReturnDialog
                    asset={asset}
                    holder={holder}
                    today={today}
                    canRetire={can.retire}
                    onClose={close}
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
                onOpenChange={setConfirmOpen}
                title="Delete asset?"
                description={`${asset.asset_code} "${asset.name}" will be deleted. It has never been handed out. This cannot be undone.`}
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
