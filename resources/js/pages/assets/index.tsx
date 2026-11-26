import { Head, Link, router, usePage } from '@inertiajs/react';
import { Download, Plus, Upload } from 'lucide-react';
import { useState } from 'react';
import { AssetFormDialog } from '@/components/assets/asset-form-dialog';
import { AssetStatusPill } from '@/components/assets/asset-status-pill';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { assetConditionLabel, assetStatusLabel } from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';
import { formatMoney } from '@/lib/payroll';
import { nextTableUrl } from '@/lib/table-query';
import { index, show } from '@/routes/assets';

type AssetRow = {
    id: number;
    asset_code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    condition: string;
    status: string;
    holder: AssetPerson | null;
    /** "Y-m-d". */
    held_since: string | null;
    /** Paisa. Only sent to people who may assign assets. */
    cost?: number | null;
};

type Props = {
    assets: DataTablePagination & { data: AssetRow[] };
    filters: {
        search: string;
        category: number | null;
        status: string;
        condition: string;
    };
    options: {
        categories: { id: number; name: string }[];
        statuses: string[];
        conditions: string[];
    };
    scope: 'all' | 'team';
    can: { create: boolean; import: boolean; export: boolean };
};

const ALL = 'all';

/** The import and export screens (Task 7) are plain pages and downloads, so they are linked by path. */
const IMPORT_HREF = '/assets/import';
const EXPORT_HREF = '/assets/export';

function CodeBadge({ code }: { code: string }) {
    return (
        <span className="inline-flex rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
            {code}
        </span>
    );
}

export default function Assets({
    assets,
    filters,
    options,
    scope,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const team = scope === 'team';
    const [adding, setAdding] = useState(false);

    const filtering =
        filters.search !== '' ||
        filters.category !== null ||
        filters.status !== '' ||
        filters.condition !== '';

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    // The export carries the list's filters: every matching row, not just this page.
    const exportQuery = new URLSearchParams(
        Object.entries({
            search: filters.search,
            category: filters.category === null ? '' : String(filters.category),
            status: filters.status,
            condition: filters.condition,
        }).filter(([, value]) => value !== ''),
    ).toString();

    const columns: DataTableColumn<AssetRow>[] = [
        {
            key: 'code',
            header: 'Asset ID',
            className: 'whitespace-nowrap',
            cell: (row) => <CodeBadge code={row.asset_code} />,
        },
        {
            key: 'name',
            header: 'Name',
            className: 'min-w-44 font-medium',
            cell: (row) => (
                <div className="grid gap-0.5">
                    {team ? (
                        <span>{row.name}</span>
                    ) : (
                        <Link
                            href={show(row.id)}
                            className="hover:underline focus-visible:underline"
                        >
                            {row.name}
                        </Link>
                    )}
                    {/* The columns hidden on smaller screens, as one line under the name. */}
                    <span className="text-xs font-normal text-muted-foreground lg:hidden">
                        {[row.category, row.serial_number]
                            .filter(Boolean)
                            .join(' · ')}
                    </span>
                </div>
            ),
        },
        {
            key: 'category',
            header: 'Category',
            className: 'hidden text-muted-foreground lg:table-cell',
            cell: (row) => row.category ?? '–',
        },
        {
            key: 'serial',
            header: 'Serial number',
            className: 'hidden text-muted-foreground xl:table-cell',
            cell: (row) => row.serial_number ?? '–',
        },
        {
            key: 'condition',
            header: 'Condition',
            className: 'hidden sm:table-cell',
            cell: (row) => assetConditionLabel(row.condition),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => <AssetStatusPill status={row.status} />,
        },
        {
            key: 'holder',
            header: 'Held by',
            className: 'min-w-36',
            cell: (row) =>
                row.holder ? (
                    <div className="grid gap-0.5">
                        <span>{row.holder.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {row.holder.code}
                            {row.held_since &&
                                ` · since ${formatDate(row.held_since, dateFormat)}`}
                        </span>
                    </div>
                ) : (
                    <span className="text-muted-foreground">–</span>
                ),
        },
        ...(team
            ? []
            : [
                  {
                      key: 'cost',
                      header: 'Cost',
                      className:
                          'hidden text-right whitespace-nowrap tabular-nums md:table-cell',
                      headerClassName: 'hidden text-right md:table-cell',
                      cell: (row: AssetRow) =>
                          row.cost === null || row.cost === undefined
                              ? '–'
                              : formatMoney(row.cost),
                  },
              ]),
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
            <Head title={team ? 'Team assets' : 'Assets'} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={team ? 'Team assets' : 'Assets'}
                    subtitle={
                        team
                            ? 'Company assets held by you and the people who report to you. This list is read-only.'
                            : 'Every company asset, who has it and what state it is in.'
                    }
                    actions={
                        <>
                            {can.export && (
                                <Button asChild variant="outline">
                                    {/* A file download, not a page visit. */}
                                    <a
                                        href={
                                            exportQuery === ''
                                                ? EXPORT_HREF
                                                : `${EXPORT_HREF}?${exportQuery}`
                                        }
                                    >
                                        <Download />
                                        Export
                                    </a>
                                </Button>
                            )}
                            {can.import && (
                                <Button asChild variant="outline">
                                    <Link href={IMPORT_HREF}>
                                        <Upload />
                                        Import
                                    </Link>
                                </Button>
                            )}
                            {can.create && (
                                <Button onClick={() => setAdding(true)}>
                                    <Plus />
                                    Add asset
                                </Button>
                            )}
                        </>
                    }
                />

                <DataTable
                    label={team ? 'Team assets' : 'Assets'}
                    columns={columns}
                    rows={assets.data}
                    rowKey={(row) => row.id}
                    pagination={assets}
                    search={{
                        value: filters.search,
                        placeholder:
                            'Search by ID, name, serial number or holder',
                        label: 'Search assets',
                    }}
                    toolbar={
                        <>
                            {filterSelect(
                                'Filter by category',
                                'All categories',
                                filters.category === null
                                    ? ''
                                    : String(filters.category),
                                options.categories.map((c) => ({
                                    value: String(c.id),
                                    label: c.name,
                                })),
                                (value) => visit({ category: value }),
                            )}
                            {!team &&
                                filterSelect(
                                    'Filter by status',
                                    'All statuses',
                                    filters.status,
                                    options.statuses.map((value) => ({
                                        value,
                                        label: assetStatusLabel(value),
                                    })),
                                    (value) => visit({ status: value }),
                                )}
                            {!team &&
                                filterSelect(
                                    'Filter by condition',
                                    'All conditions',
                                    filters.condition,
                                    options.conditions.map((value) => ({
                                        value,
                                        label: assetConditionLabel(value),
                                    })),
                                    (value) => visit({ condition: value }),
                                )}
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No assets match these filters.'
                            : team
                              ? 'Nobody in your team holds a company asset right now.'
                              : 'No assets yet. Add the first one to get started.'
                    }
                    emptyAction={
                        filtering ? (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    visit({
                                        search: null,
                                        category: null,
                                        status: null,
                                        condition: null,
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
                <AssetFormDialog
                    asset={null}
                    categories={options.categories}
                    onClose={() => setAdding(false)}
                />
            )}
        </>
    );
}

Assets.layout = {
    breadcrumbs: [{ title: 'Assets', href: index() }],
};
