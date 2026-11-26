import { Head, Link, router, usePage } from '@inertiajs/react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { assetConditionLabel } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { index as assignmentsIndex } from '@/routes/assets/assignments';
import { show } from '@/routes/assets';

type AssignmentRow = {
    id: number;
    asset: {
        id: number;
        code: string;
        name: string;
        category: string | null;
    };
    employee: {
        id: number;
        code: string;
        name: string;
        department: string | null;
    };
    /** "Y-m-d". */
    assigned_on: string;
    returned_on: string | null;
    days_held: number;
    condition_out: string;
    return_condition: string | null;
};

type StatusFilter = 'open' | 'returned' | 'all';

type Option = { id: number; name: string };

type Props = {
    assignments: DataTablePagination & { data: AssignmentRow[] };
    filters: {
        status: StatusFilter;
        search: string;
        category: number | null;
        department: number | null;
    };
    options: { categories: Option[]; departments: Option[] };
    /** `team` is the Manager's read-only view of their own people. */
    scope: 'all' | 'team';
    can: { return: boolean };
};

const ALL = 'all';
const ID_PREFIX = 'assignments';

const TABS: { key: StatusFilter; label: string }[] = [
    { key: 'open', label: 'Still out' },
    { key: 'returned', label: 'Returned' },
    { key: 'all', label: 'All' },
];

function dayCount(days: number): string {
    return days === 1 ? '1 day' : `${days} days`;
}

/** Every hand-over: who has what, since when, in what condition. HR and Admin go on to return from the asset page. */
export default function Assignments({
    assignments,
    filters,
    options,
    scope,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const team = scope === 'team';

    const filtering =
        filters.search !== '' ||
        filters.category !== null ||
        filters.department !== null;

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const columns: DataTableColumn<AssignmentRow>[] = [
        {
            key: 'asset',
            header: 'Asset',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <div className="flex flex-wrap items-center gap-x-2">
                        {can.return ? (
                            <Link
                                href={show.url(row.asset.id)}
                                className="font-medium hover:underline"
                            >
                                {row.asset.name}
                            </Link>
                        ) : (
                            <span className="font-medium">
                                {row.asset.name}
                            </span>
                        )}
                        <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
                            {row.asset.code}
                        </span>
                    </div>
                    {row.asset.category && (
                        <span className="text-xs text-muted-foreground">
                            {row.asset.category}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span className="font-medium">{row.employee.name}</span>
                    <span className="text-xs text-muted-foreground">
                        {row.employee.code}
                        {row.employee.department &&
                            ` · ${row.employee.department}`}
                    </span>
                </div>
            ),
        },
        {
            key: 'given',
            header: 'Given',
            className: 'whitespace-nowrap',
            cell: (row) => formatDate(row.assigned_on, dateFormat),
        },
        {
            key: 'returned',
            header: 'Returned',
            className: 'whitespace-nowrap',
            cell: (row) =>
                row.returned_on ? (
                    formatDate(row.returned_on, dateFormat)
                ) : (
                    <StatusPill tone="blue">Still out</StatusPill>
                ),
        },
        {
            key: 'held',
            header: 'Held',
            className: 'whitespace-nowrap',
            cell: (row) => dayCount(row.days_held),
        },
        {
            key: 'condition',
            header: 'Condition',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusPill tone="grey">
                        Out: {assetConditionLabel(row.condition_out)}
                    </StatusPill>
                    {row.return_condition && (
                        <StatusPill tone="grey">
                            Back: {assetConditionLabel(row.return_condition)}
                        </StatusPill>
                    )}
                </div>
            ),
        },
    ];

    if (can.return) {
        columns.push({
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'text-right',
            cell: (row) =>
                row.returned_on === null ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={show.url(row.asset.id)}>Return</Link>
                    </Button>
                ) : null,
        });
    }

    return (
        <>
            <Head title="Assignments" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Assignments"
                    subtitle={
                        team
                            ? 'Assets held by you and your team.'
                            : 'Who holds which asset, and the history of every hand-over.'
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Assignment status"
                        idPrefix={ID_PREFIX}
                        value={filters.status}
                        onChange={(key) => {
                            if (key !== filters.status) {
                                visit({ status: key === 'open' ? null : key });
                            }
                        }}
                        tabs={TABS}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, filters.status)}
                    >
                        <DataTable
                            label="Assignments"
                            bordered={false}
                            columns={columns}
                            rows={assignments.data}
                            rowKey={(row) => row.id}
                            pagination={assignments}
                            search={{
                                value: filters.search,
                                placeholder:
                                    'Search by asset, serial, employee or ID',
                                label: 'Search assignments',
                            }}
                            toolbar={
                                <>
                                    <Select
                                        value={
                                            filters.category === null
                                                ? ALL
                                                : String(filters.category)
                                        }
                                        onValueChange={(value) =>
                                            visit({
                                                category:
                                                    value === ALL
                                                        ? null
                                                        : value,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-44"
                                            aria-label="Filter by category"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All Categories
                                            </SelectItem>
                                            {options.categories.map((c) => (
                                                <SelectItem
                                                    key={c.id}
                                                    value={String(c.id)}
                                                >
                                                    {c.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value={
                                            filters.department === null
                                                ? ALL
                                                : String(filters.department)
                                        }
                                        onValueChange={(value) =>
                                            visit({
                                                department:
                                                    value === ALL
                                                        ? null
                                                        : value,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-48"
                                            aria-label="Filter by department"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All Departments
                                            </SelectItem>
                                            {options.departments.map((d) => (
                                                <SelectItem
                                                    key={d.id}
                                                    value={String(d.id)}
                                                >
                                                    {d.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </>
                            }
                            emptyText={
                                filtering
                                    ? 'No assignments match these filters.'
                                    : filters.status === 'returned'
                                      ? 'Nothing has been returned yet.'
                                      : filters.status === 'open'
                                        ? 'No assets are out right now.'
                                        : 'No assets have been assigned yet.'
                            }
                        />
                    </div>
                </div>
            </div>
        </>
    );
}

Assignments.layout = {
    breadcrumbs: [
        { title: 'Assets', href: '/assets' },
        { title: 'Assignments', href: assignmentsIndex() },
    ],
};
