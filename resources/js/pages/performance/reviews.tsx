import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { OpenCycleDialog } from '@/components/performance/open-cycle-dialog';
import type { OpenOptions } from '@/components/performance/open-cycle-dialog';
import {
    CycleStatePill,
    ReviewStatePill,
} from '@/components/performance/review-state-pill';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import {
    REVIEW_STATES,
    cycleProgressPercent,
    formatScore,
    reviewStateLabel,
} from '@/lib/performance';
import type { ReviewCounts } from '@/lib/performance';
import { nextTableUrl } from '@/lib/table-query';
import { show as cycleShow } from '@/routes/performance/cycles';
import { index } from '@/routes/performance/reviews';

type CycleRow = {
    id: number;
    name: string;
    status: string;
    period_start: string;
    period_end: string;
    due_on: string;
    overdue: boolean;
    counts: ReviewCounts;
    total: number;
    progress: string;
};

type TeamRow = {
    id: number;
    employee: {
        id: number;
        code: string;
        name: string;
        department: string | null;
    };
    cycle: string;
    state: string;
    reviewer: string;
    awaiting_me: boolean;
    url: string;
    /** Only on a closed review. */
    score?: number;
    label?: string;
};

type Props =
    | {
          view: 'cycles';
          cycles: CycleRow[];
          open_options: OpenOptions;
          can: { open: boolean };
      }
    | {
          view: 'team';
          team: DataTablePagination & { data: TeamRow[] };
          filters: { search: string; cycle: number | null; state: string };
          options: { cycles: { id: number; name: string }[] };
      };

const ALL = 'all';

function ProgressBar({ percent, label }: { percent: number; label: string }) {
    return (
        <div
            role="progressbar"
            aria-label={label}
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="h-2 w-full overflow-hidden rounded-full bg-muted"
        >
            <div
                className="h-full rounded-full bg-primary transition-[width] duration-(--duration-fast)"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}

function CyclesView({
    cycles,
    openOptions,
}: {
    cycles: CycleRow[];
    openOptions: OpenOptions;
}) {
    const dateFormat = usePage().props.app.dateFormat;
    const [opening, setOpening] = useState(false);

    const columns: DataTableColumn<CycleRow>[] = [
        {
            key: 'name',
            header: 'Cycle',
            className: 'font-medium sm:min-w-44',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <Link
                        href={cycleShow(row.id)}
                        className="text-brand-ink underline-offset-2 hover:underline"
                    >
                        {row.name}
                    </Link>
                    <span className="text-xs font-normal text-muted-foreground">
                        {formatDate(row.period_start, dateFormat)} to{' '}
                        {formatDate(row.period_end, dateFormat)}
                    </span>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => <CycleStatePill state={row.status} />,
        },
        {
            key: 'due',
            header: 'Due',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <span>{formatDate(row.due_on, dateFormat)}</span>
                    {row.overdue && <StatusPill tone="red">Overdue</StatusPill>}
                </div>
            ),
        },
        {
            key: 'progress',
            header: 'Progress',
            className: 'min-w-44',
            cell: (row) => (
                <div className="grid gap-1.5">
                    <ProgressBar
                        percent={cycleProgressPercent(row.counts)}
                        label={`${row.name}: ${row.progress}`}
                    />
                    <span className="text-xs text-muted-foreground">
                        {row.progress}
                        {row.counts.skipped > 0 &&
                            ` · ${row.counts.skipped} skipped`}
                    </span>
                </div>
            ),
        },
        {
            key: 'states',
            header: 'Waiting on',
            className: 'hidden text-xs text-muted-foreground lg:table-cell',
            headerClassName: 'hidden lg:table-cell',
            cell: (row) => {
                const waiting = REVIEW_STATES.filter(
                    (s) => s !== 'closed' && s !== 'skipped' && row.counts[s],
                );

                return waiting.length === 0
                    ? '–'
                    : waiting
                          .map((s) => `${row.counts[s]} ${reviewStateLabel(s)}`)
                          .join(' · ');
            },
        },
    ];

    return (
        <>
            <PageHeader
                title="Reviews"
                subtitle="A cycle covers one period. Everyone with KPIs gets a review; the person writes a self-review, their manager rates, and HR closes it."
                actions={
                    <Button onClick={() => setOpening(true)}>
                        <Plus />
                        Open cycle
                    </Button>
                }
            />

            <DataTable
                label="Review cycles"
                columns={columns}
                rows={cycles}
                rowKey={(row) => row.id}
                emptyText="No review cycles yet. Add KPIs for your designations, then open the first cycle."
                emptyAction={
                    <Button size="sm" onClick={() => setOpening(true)}>
                        <Plus />
                        Open cycle
                    </Button>
                }
            />

            {opening && (
                <OpenCycleDialog
                    options={openOptions}
                    onClose={() => setOpening(false)}
                />
            )}
        </>
    );
}

function TeamView({
    team,
    filters,
    options,
}: {
    team: DataTablePagination & { data: TeamRow[] };
    filters: { search: string; cycle: number | null; state: string };
    options: { cycles: { id: number; name: string }[] };
}) {
    const { url } = usePage();
    const filtering =
        filters.search !== '' || filters.cycle !== null || filters.state !== '';

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const columns: DataTableColumn<TeamRow>[] = [
        {
            key: 'person',
            header: 'Person',
            className: 'font-medium sm:min-w-48',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <Link
                        href={row.url}
                        className="text-brand-ink underline-offset-2 hover:underline"
                    >
                        {row.employee.name}
                    </Link>
                    <span className="text-xs font-normal text-muted-foreground">
                        {row.employee.code}
                        {row.employee.department &&
                            ` · ${row.employee.department}`}
                    </span>
                    {/* The columns hidden on a phone, stacked under the name. */}
                    <span className="text-xs font-normal text-muted-foreground md:hidden">
                        {row.cycle} · reviewer {row.reviewer}
                    </span>
                </div>
            ),
        },
        {
            key: 'cycle',
            header: 'Cycle',
            className: 'hidden whitespace-nowrap md:table-cell',
            headerClassName: 'hidden md:table-cell',
            cell: (row) => row.cycle,
        },
        {
            key: 'state',
            header: 'State',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    <ReviewStatePill state={row.state} />
                    {row.awaiting_me && (
                        <StatusPill tone="amber">Your turn</StatusPill>
                    )}
                </div>
            ),
        },
        {
            key: 'reviewer',
            header: 'Reviewer',
            className: 'hidden whitespace-nowrap md:table-cell',
            headerClassName: 'hidden md:table-cell',
            cell: (row) => row.reviewer,
        },
        {
            key: 'score',
            header: 'Final score',
            className: 'whitespace-nowrap tabular-nums',
            cell: (row) =>
                row.score === undefined ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    <span>
                        {formatScore(row.score)}
                        {row.label && (
                            <span className="ml-1.5 text-xs text-muted-foreground">
                                {row.label}
                            </span>
                        )}
                    </span>
                ),
        },
    ];

    return (
        <>
            <PageHeader
                title="Team reviews"
                subtitle="Reviews you write for your team, and the closed reviews of the people who report to you now. Your own reviews are under My Reviews."
            />

            <DataTable
                label="Team reviews"
                columns={columns}
                rows={team.data}
                rowKey={(row) => row.id}
                pagination={team}
                search={{
                    value: filters.search,
                    placeholder: 'Search by name or code',
                    label: 'Search team reviews',
                }}
                toolbar={
                    <>
                        <Select
                            value={
                                filters.cycle === null
                                    ? ALL
                                    : String(filters.cycle)
                            }
                            onValueChange={(v) =>
                                visit({ cycle: v === ALL ? null : v })
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-48"
                                aria-label="Filter by cycle"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All cycles</SelectItem>
                                {options.cycles.map((cycle) => (
                                    <SelectItem
                                        key={cycle.id}
                                        value={String(cycle.id)}
                                    >
                                        {cycle.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <Select
                            value={filters.state === '' ? ALL : filters.state}
                            onValueChange={(v) =>
                                visit({ state: v === ALL ? null : v })
                            }
                        >
                            <SelectTrigger
                                className="w-full sm:w-44"
                                aria-label="Filter by state"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={ALL}>All states</SelectItem>
                                {REVIEW_STATES.map((state) => (
                                    <SelectItem key={state} value={state}>
                                        {reviewStateLabel(state)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </>
                }
                emptyText={
                    filtering
                        ? 'No reviews match these filters.'
                        : 'No team reviews yet. They appear here when HR opens a cycle that includes your team.'
                }
                emptyAction={
                    filtering ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() =>
                                visit({
                                    search: null,
                                    cycle: null,
                                    state: null,
                                })
                            }
                        >
                            Clear filters
                        </Button>
                    ) : undefined
                }
            />
        </>
    );
}

export default function Reviews(props: Props) {
    return (
        <>
            <Head title={props.view === 'team' ? 'Team reviews' : 'Reviews'} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                {props.view === 'cycles' ? (
                    <CyclesView
                        cycles={props.cycles}
                        openOptions={props.open_options}
                    />
                ) : (
                    <TeamView
                        team={props.team}
                        filters={props.filters}
                        options={props.options}
                    />
                )}
            </div>
        </>
    );
}

Reviews.layout = {
    breadcrumbs: [{ title: 'Reviews', href: index() }],
};
