import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import KpiController from '@/actions/App/Http/Controllers/Performance/KpiController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { KpiFormDialog } from '@/components/performance/kpi-form-dialog';
import type {
    KpiOptions,
    KpiRow,
} from '@/components/performance/kpi-form-dialog';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { nextTableUrl } from '@/lib/table-query';
import { index } from '@/routes/performance/kpis';

type Props = {
    kpis: DataTablePagination & { data: KpiRow[] };
    filters: { search: string; target: string; active: string };
    options: KpiOptions;
};

const ALL = 'all';

/** The data the update route needs to change only the active flag. */
/** The same sentence the server gives when a delete is refused (SaveKpi::inUseMessage). */
function inUseMessage(count: number): string {
    const uses = count === 1 ? '1 review uses' : `${count} reviews use`;

    return `${uses} this KPI, so it cannot be deleted. Deactivate it instead.`;
}

function activeToggleData(kpi: KpiRow, active: boolean) {
    return {
        name: kpi.name,
        description: kpi.description,
        weight: kpi.weight,
        is_active: active,
        target: kpi.target.type,
        designation_id:
            kpi.target.type === 'designation' ? kpi.target.id : null,
        employee_id: kpi.target.type === 'employee' ? kpi.target.id : null,
    };
}

export default function Kpis({ kpis, filters, options }: Props) {
    const { url } = usePage();
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<KpiRow | null>(null);
    const [deleting, setDeleting] = useState<KpiRow | null>(null);
    const [busy, setBusy] = useState(false);
    const [deleteError, setDeleteError] = useState<string | null>(null);

    const filtering =
        filters.search !== '' || filters.target !== '' || filters.active !== '';

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const toggleActive = (kpi: KpiRow) =>
        router.put(
            KpiController.update(kpi.id).url,
            activeToggleData(kpi, !kpi.active),
            { preserveScroll: true },
        );

    const remove = () => {
        if (!deleting) {
            return;
        }

        router.delete(KpiController.destroy(deleting.id).url, {
            preserveScroll: true,
            onStart: () => {
                setBusy(true);
                setDeleteError(null);
            },
            onSuccess: () => setDeleting(null),
            onError: (errors) =>
                setDeleteError(errors.kpi ?? 'The KPI could not be deleted.'),
            onFinish: () => setBusy(false),
        });
    };

    const columns: DataTableColumn<KpiRow>[] = [
        {
            key: 'name',
            header: 'KPI',
            className: 'font-medium sm:min-w-52',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span>{row.name}</span>
                    {row.description && (
                        <span className="text-xs font-normal text-muted-foreground">
                            {row.description}
                        </span>
                    )}
                    {/* The columns hidden below xl (a laptop with the sidebar open has about 650px), stacked under the name. */}
                    <span className="text-xs font-normal text-muted-foreground xl:hidden">
                        {row.target.type === 'designation'
                            ? 'Designation'
                            : 'Person'}
                        : {row.target.name} · weight {row.weight}
                    </span>
                </div>
            ),
        },
        {
            key: 'target',
            header: 'Applies to',
            className: 'hidden xl:table-cell',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span>{row.target.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {row.target.type === 'designation'
                            ? 'Designation'
                            : `Person${row.target.code ? ` · ${row.target.code}` : ''}`}
                    </span>
                </div>
            ),
        },
        {
            key: 'weight',
            header: 'Weight',
            className: 'hidden text-right tabular-nums xl:table-cell',
            headerClassName: 'hidden text-right xl:table-cell',
            cell: (row) => row.weight,
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => (
                <StatusPill tone={row.active ? 'green' : 'grey'}>
                    {row.active ? 'Active' : 'Inactive'}
                </StatusPill>
            ),
        },
        {
            key: 'uses',
            header: 'Used in',
            className:
                'hidden whitespace-nowrap text-muted-foreground xl:table-cell',
            headerClassName: 'hidden xl:table-cell',
            cell: (row) =>
                row.uses === 0
                    ? '–'
                    : `${row.uses} review${row.uses === 1 ? '' : 's'}`,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'text-right whitespace-nowrap',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => toggleActive(row)}
                    >
                        {row.active ? 'Deactivate' : 'Activate'}
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Edit ${row.name}`}
                        onClick={() => setEditing(row)}
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label={`Delete ${row.name}`}
                        title={
                            row.can_delete
                                ? undefined
                                : `${inUseMessage(row.uses)}`
                        }
                        disabled={!row.can_delete}
                        onClick={() => {
                            setDeleteError(null);
                            setDeleting(row);
                        }}
                    >
                        <Trash2 />
                    </Button>
                </div>
            ),
        },
    ];

    const filterSelect = (
        label: string,
        allLabel: string,
        value: string,
        choices: { value: string; label: string }[],
        onChange: (value: string | null) => void,
    ) => (
        <Select
            value={value === '' ? ALL : value}
            onValueChange={(next) => onChange(next === ALL ? null : next)}
        >
            <SelectTrigger className="w-full sm:w-44" aria-label={label}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ALL}>{allLabel}</SelectItem>
                {choices.map((choice) => (
                    <SelectItem key={choice.value} value={choice.value}>
                        {choice.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    return (
        <>
            <Head title="KPIs" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="KPIs"
                    subtitle="What people are rated on. A KPI belongs to a designation or to one person, and is copied onto each review when a cycle opens."
                    actions={
                        <Button onClick={() => setAdding(true)}>
                            <Plus />
                            Add KPI
                        </Button>
                    }
                />

                <DataTable
                    label="KPIs"
                    columns={columns}
                    rows={kpis.data}
                    rowKey={(row) => row.id}
                    pagination={kpis}
                    search={{
                        value: filters.search,
                        placeholder: 'Search by KPI, designation or person',
                        label: 'Search KPIs',
                    }}
                    toolbar={
                        <>
                            {filterSelect(
                                'Filter by who it applies to',
                                'Everyone',
                                filters.target,
                                [
                                    {
                                        value: 'designation',
                                        label: 'Designations',
                                    },
                                    { value: 'employee', label: 'One person' },
                                ],
                                (value) => visit({ target: value }),
                            )}
                            {filterSelect(
                                'Filter by status',
                                'Active and inactive',
                                filters.active,
                                [
                                    { value: '1', label: 'Active' },
                                    { value: '0', label: 'Inactive' },
                                ],
                                (value) => visit({ active: value }),
                            )}
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No KPIs match these filters.'
                            : 'No KPIs yet. Add the first one before you open a review cycle.'
                    }
                    emptyAction={
                        filtering ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    visit({
                                        search: null,
                                        target: null,
                                        active: null,
                                    })
                                }
                            >
                                Clear filters
                            </Button>
                        ) : undefined
                    }
                />
            </div>

            {adding && (
                <KpiFormDialog
                    kpi={null}
                    options={options}
                    onClose={() => setAdding(false)}
                />
            )}
            {editing && (
                <KpiFormDialog
                    key={editing.id}
                    kpi={editing}
                    options={options}
                    onClose={() => setEditing(null)}
                />
            )}
            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete this KPI?"
                description={
                    <>
                        {deleting?.name} will be removed. This cannot be undone.
                        {deleteError && (
                            <span
                                role="alert"
                                className="mt-2 block text-red-600 dark:text-red-400"
                            >
                                {deleteError}
                            </span>
                        )}
                    </>
                }
                confirmLabel="Delete KPI"
                processing={busy}
                onConfirm={remove}
            />
        </>
    );
}

Kpis.layout = {
    breadcrumbs: [{ title: 'KPIs', href: index() }],
};
