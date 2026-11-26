import { Head, Link, router, usePage } from '@inertiajs/react';
import { DoorOpen } from 'lucide-react';
import { useState } from 'react';
import { RecordExitDialog } from '@/components/exit/record-exit-dialog';
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
import {
    exitStageText,
    exitStageTone,
    exitTypeLabel,
    exitTypeTone,
    noticeTone,
    settlementStatusLabel,
    settlementStatusTone,
} from '@/lib/exit';
import type { ExitRow } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { index as exitIndex, show } from '@/routes/exit';

type Tab = 'waiting' | 'open' | 'all' | 'ended';

type Props = {
    exits: DataTablePagination & { data: ExitRow[] };
    counts: Record<Tab, number>;
    filters: {
        tab: Tab;
        search: string;
        type: string;
        department: number | null;
    };
    options: { departments: { id: number; name: string }[]; types: string[] };
    /** The attendance day "Y-m-d" (the record dialog's default). */
    today: string;
    can: { record: boolean; settlements: boolean };
};

const ALL = 'all';
const ID_PREFIX = 'exits';

const TAB_LABELS: { key: Tab; label: string }[] = [
    { key: 'waiting', label: 'Waiting for me' },
    { key: 'open', label: 'Open' },
    { key: 'all', label: 'All' },
    { key: 'ended', label: 'Ended' },
];

/**
 * Exit > Resignations & Clearance. Waiting for me / Open / All / Ended, filters, and (HR) Record exit. A manager sees only
 * their reports' exits and no settlement column. Narrow screens stack the hidden columns under the person.
 */
export default function ExitIndex({
    exits,
    counts,
    filters,
    options,
    today,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [recording, setRecording] = useState(false);
    const filtering =
        filters.search !== '' ||
        filters.type !== '' ||
        filters.department !== null;

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const columns: DataTableColumn<ExitRow>[] = [
        {
            key: 'person',
            header: 'Person',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <Link
                        href={show.url(row.id)}
                        className="font-medium hover:underline"
                    >
                        {row.employee.name}
                    </Link>
                    <span className="text-xs text-muted-foreground">
                        {row.employee.code}
                        {row.employee.department &&
                            ` · ${row.employee.department}`}
                    </span>
                    <span className="text-xs text-muted-foreground md:hidden">
                        {exitTypeLabel(row.type)} · Last day{' '}
                        {formatDate(row.last_working_day, dateFormat)}
                    </span>
                    <span className="text-xs text-muted-foreground lg:hidden">
                        {row.notice.label}
                    </span>
                </div>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            className: 'hidden md:table-cell',
            cell: (row) => (
                <StatusPill tone={exitTypeTone(row.type)}>
                    {exitTypeLabel(row.type)}
                </StatusPill>
            ),
        },
        {
            key: 'submitted',
            header: 'Submitted',
            className: 'hidden whitespace-nowrap lg:table-cell',
            cell: (row) => formatDate(row.submitted_on, dateFormat),
        },
        {
            key: 'last_day',
            header: 'Last day',
            className: 'hidden whitespace-nowrap md:table-cell',
            cell: (row) => formatDate(row.last_working_day, dateFormat),
        },
        {
            key: 'notice',
            header: 'Notice',
            className: 'hidden lg:table-cell',
            cell: (row) => (
                <StatusPill tone={noticeTone(row.notice)}>
                    {row.notice.label}
                </StatusPill>
            ),
        },
        {
            key: 'stage',
            header: 'Stage',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusPill tone={exitStageTone(row.status, row.detail)}>
                        {exitStageText(row)}
                    </StatusPill>
                    {row.waiting_for_me && (
                        <StatusPill tone="blue">Your turn</StatusPill>
                    )}
                </div>
            ),
        },
    ];

    if (can.settlements) {
        columns.push({
            key: 'settlement',
            header: 'Settlement',
            className: 'hidden xl:table-cell',
            cell: (row) =>
                row.settlement ? (
                    <StatusPill tone={settlementStatusTone(row.settlement)}>
                        {settlementStatusLabel(row.settlement)}
                    </StatusPill>
                ) : (
                    <span className="text-sm text-muted-foreground">
                        {row.status === 'approved' ? 'Not prepared' : '–'}
                    </span>
                ),
        });
    }

    columns.push({
        key: 'actions',
        header: <span className="sr-only">Actions</span>,
        className: 'text-right',
        cell: (row) => (
            <Button variant="outline" size="sm" asChild>
                <Link href={show.url(row.id)}>Open</Link>
            </Button>
        ),
    });

    return (
        <>
            <Head title="Resignations & Clearance" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Resignations & Clearance"
                    subtitle={
                        can.record
                            ? 'Resignations, terminations, clearance and final settlements.'
                            : 'Exits of the people who report to you.'
                    }
                    actions={
                        can.record && (
                            <Button
                                type="button"
                                onClick={() => setRecording(true)}
                            >
                                <DoorOpen /> Record exit
                            </Button>
                        )
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Exit status"
                        idPrefix={ID_PREFIX}
                        value={filters.tab}
                        onChange={(key) => {
                            if (key !== filters.tab) {
                                visit({ tab: key === 'waiting' ? null : key });
                            }
                        }}
                        tabs={TAB_LABELS.map((tab) => ({
                            ...tab,
                            count: counts[tab.key],
                        }))}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, filters.tab)}
                    >
                        <DataTable
                            label="Exits"
                            bordered={false}
                            columns={columns}
                            rows={exits.data}
                            rowKey={(row) => row.id}
                            pagination={exits}
                            search={{
                                value: filters.search,
                                placeholder: 'Search by name or ID',
                                label: 'Search exits',
                            }}
                            toolbar={
                                <>
                                    <Select
                                        value={
                                            filters.type === ''
                                                ? ALL
                                                : filters.type
                                        }
                                        onValueChange={(value) =>
                                            visit({
                                                type:
                                                    value === ALL
                                                        ? null
                                                        : value,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-44"
                                            aria-label="Filter by type"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All Types
                                            </SelectItem>
                                            {options.types.map((type) => (
                                                <SelectItem
                                                    key={type}
                                                    value={type}
                                                >
                                                    {exitTypeLabel(type)}
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
                                    ? 'No exits match these filters.'
                                    : filters.tab === 'waiting'
                                      ? 'Nothing is waiting for you.'
                                      : filters.tab === 'open'
                                        ? 'No open exits.'
                                        : filters.tab === 'ended'
                                          ? 'No exit has ended yet.'
                                          : 'No exits have been recorded yet.'
                            }
                        />
                    </div>
                </div>
            </div>
            {recording && (
                <RecordExitDialog
                    today={today}
                    types={options.types}
                    onClose={() => setRecording(false)}
                />
            )}
        </>
    );
}

ExitIndex.layout = {
    breadcrumbs: [{ title: 'Resignations & Clearance', href: exitIndex() }],
};
