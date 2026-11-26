import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCheck,
    MoreHorizontal,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import ReviewController from '@/actions/App/Http/Controllers/Performance/ReviewController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { AddPeopleDialog } from '@/components/performance/add-people-dialog';
import type { NotAddedPerson } from '@/components/performance/add-people-dialog';
import { ChangeDueDateDialog } from '@/components/performance/change-due-date-dialog';
import { CloseCycleDialog } from '@/components/performance/close-cycle-dialog';
import {
    CycleStatePill,
    ReviewStatePill,
} from '@/components/performance/review-state-pill';
import { ReviewerDialog } from '@/components/performance/reviewer-dialog';
import type { ReviewerChoice } from '@/components/performance/reviewer-dialog';
import { SkipDialog } from '@/components/performance/skip-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import {
    cycleProgressPercent,
    cycleTabs,
    firstError,
    formatScore,
} from '@/lib/performance';
import type { ReviewCounts } from '@/lib/performance';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { show } from '@/routes/performance/cycles';
import { index as reviewsIndex } from '@/routes/performance/reviews';

type RowCan = {
    waive: boolean;
    reviewer: boolean;
    skip: boolean;
    restore: boolean;
    reopen: boolean;
};

type ReviewRow = {
    id: number;
    employee: { id: number; code: string; name: string };
    department: string | null;
    reviewer: string;
    reviewer_id: number | null;
    reviewer_problem: boolean;
    state: string;
    self_score: number | null;
    url: string;
    can: RowCan;
    /** Only on a closed review. */
    final_score?: number;
    label?: string;
    /** Only on a skipped review. */
    skip_reason?: string | null;
};

type Props = {
    cycle: {
        id: number;
        name: string;
        status: string;
        period_label: string;
        period_start: string;
        period_end: string;
        due_on: string;
        overdue: boolean;
        self_review_enabled: boolean;
        closed_on: string | null;
        total: number;
        unfinished: number;
        close_hint: string | null;
    };
    counts: ReviewCounts;
    reviews: DataTablePagination & { data: ReviewRow[] };
    filters: {
        state: string;
        search: string;
        department: number | null;
        reviewer: string;
        overdue: boolean;
    };
    options: {
        departments: { id: number; name: string }[];
        reviewers: { id: number; name: string }[];
        reviewer_choices: ReviewerChoice[];
    };
    not_added: { total: number; people: NotAddedPerson[] };
    can: {
        add_people: boolean;
        close: boolean;
        waive: boolean;
        reviewer: boolean;
        skip: boolean;
        restore: boolean;
        reopen: boolean;
    };
};

type Direct = 'waive' | 'restore' | 'reopen';

const ALL = 'all';
const ID_PREFIX = 'cycle-state';

/** The words of the three actions that need only a confirmation. */
const DIRECT: Record<
    Direct,
    { title: (name: string) => string; text: string; button: string }
> = {
    waive: {
        title: (name) => `Waive ${name}'s self-review?`,
        text: 'The review goes straight to the manager. The person will not be asked to write a self-review.',
        button: 'Waive self-review',
    },
    restore: {
        title: (name) => `Restore ${name}'s review?`,
        text: 'The review goes back to the step it was skipped from, with everything that was saved.',
        button: 'Restore review',
    },
    reopen: {
        title: (name) => `Reopen ${name}'s review?`,
        text: 'The final score is cleared and the review goes back to Ready to close, so it can be changed and closed again.',
        button: 'Reopen review',
    },
};

const DIRECT_ROUTE = {
    waive: (id: number) => ReviewController.waive(id).url,
    restore: (id: number) => ReviewController.restore(id).url,
    reopen: (id: number) => ReviewController.reopen(id).url,
};

function Tile({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="min-w-0 rounded-xl border bg-card px-4 py-3 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 text-base font-semibold sm:text-lg">
                {children}
            </dd>
        </div>
    );
}

export default function Cycle({
    cycle,
    counts,
    reviews,
    filters,
    options,
    not_added: notAdded,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const open = cycle.status === 'open';
    const percent = cycleProgressPercent(counts);
    const counted = cycle.total - counts.skipped;

    const [adding, setAdding] = useState(false);
    const [dueDate, setDueDate] = useState(false);
    const [closing, setClosing] = useState(false);
    const [skipping, setSkipping] = useState<ReviewRow | null>(null);
    const [changing, setChanging] = useState<ReviewRow | null>(null);
    const [direct, setDirect] = useState<{
        kind: Direct;
        row: ReviewRow;
    } | null>(null);
    const [busy, setBusy] = useState(false);
    const [directError, setDirectError] = useState<string | null>(null);

    const filtering =
        filters.state !== '' ||
        filters.search !== '' ||
        filters.department !== null ||
        filters.reviewer !== '' ||
        filters.overdue;

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const runDirect = () => {
        if (!direct) {
            return;
        }

        router.post(
            DIRECT_ROUTE[direct.kind](direct.row.id),
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setDirectError(null);
                },
                onSuccess: () => setDirect(null),
                onError: (errors) =>
                    setDirectError(
                        firstError(errors) || 'That could not be done.',
                    ),
                onFinish: () => setBusy(false),
            },
        );
    };

    const columns: DataTableColumn<ReviewRow>[] = [
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
                        {row.department && ` · ${row.department}`}
                    </span>
                    {/* The reviewer column is hidden on a phone: it moves under the name. */}
                    <span className="text-xs font-normal text-muted-foreground md:hidden">
                        Reviewer: {row.reviewer}
                    </span>
                </div>
            ),
        },
        {
            key: 'reviewer',
            header: 'Reviewer',
            className: 'hidden md:table-cell',
            headerClassName: 'hidden md:table-cell',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span className="whitespace-nowrap">{row.reviewer}</span>
                    {row.reviewer_problem && (
                        <span
                            role="status"
                            className="text-xs text-amber-700 dark:text-amber-300"
                        >
                            No login or review access. Change the reviewer.
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'state',
            header: 'State',
            cell: (row) => (
                <div className="grid justify-items-start gap-1">
                    <ReviewStatePill state={row.state} />
                    {row.reviewer_problem && (
                        <span className="text-xs text-amber-700 md:hidden dark:text-amber-300">
                            Reviewer has no access
                        </span>
                    )}
                    {row.skip_reason && (
                        <span className="max-w-56 text-xs text-muted-foreground">
                            {row.skip_reason}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'self',
            header: 'Self',
            className:
                'hidden text-right whitespace-nowrap tabular-nums lg:table-cell',
            headerClassName: 'hidden text-right lg:table-cell',
            cell: (row) => formatScore(row.self_score),
        },
        {
            key: 'final',
            header: 'Final',
            className: 'text-right whitespace-nowrap tabular-nums',
            headerClassName: 'text-right',
            cell: (row) =>
                row.final_score === undefined ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    <span>
                        {formatScore(row.final_score)}
                        {row.label && (
                            <span className="ml-1.5 text-xs text-muted-foreground">
                                {row.label}
                            </span>
                        )}
                    </span>
                ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'text-right whitespace-nowrap',
            cell: (row) => {
                const any = Object.values(row.can).some(Boolean);

                return (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={`Actions for ${row.employee.name}`}
                            >
                                <MoreHorizontal />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <Link href={row.url}>Open review</Link>
                            </DropdownMenuItem>
                            {any && <DropdownMenuSeparator />}
                            {row.can.reviewer && (
                                <DropdownMenuItem
                                    onSelect={() => setChanging(row)}
                                >
                                    Change reviewer
                                </DropdownMenuItem>
                            )}
                            {row.can.waive && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        setDirectError(null);
                                        setDirect({ kind: 'waive', row });
                                    }}
                                >
                                    Waive self-review
                                </DropdownMenuItem>
                            )}
                            {row.can.skip && (
                                <DropdownMenuItem
                                    onSelect={() => setSkipping(row)}
                                >
                                    Skip review
                                </DropdownMenuItem>
                            )}
                            {row.can.restore && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        setDirectError(null);
                                        setDirect({ kind: 'restore', row });
                                    }}
                                >
                                    Restore review
                                </DropdownMenuItem>
                            )}
                            {row.can.reopen && (
                                <DropdownMenuItem
                                    onSelect={() => {
                                        setDirectError(null);
                                        setDirect({ kind: 'reopen', row });
                                    }}
                                >
                                    Reopen review
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
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

    const waiting = notAdded.people.filter((p) => p.addable).length;

    return (
        <>
            <Head title={cycle.name} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={cycle.name}
                    subtitle={`${formatDate(cycle.period_start, dateFormat)} to ${formatDate(cycle.period_end, dateFormat)}`}
                    actions={
                        open ? (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => setDueDate(true)}
                                >
                                    <CalendarClock />
                                    Change due date
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setAdding(true)}
                                >
                                    <UserPlus />
                                    Add people
                                </Button>
                                <Button
                                    disabled={!can.close}
                                    onClick={() => setClosing(true)}
                                >
                                    <CheckCheck />
                                    Close cycle
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                {open && cycle.close_hint && (
                    <p
                        role="status"
                        className="-mt-3 text-sm text-muted-foreground"
                    >
                        Close cycle is off: {cycle.close_hint}
                    </p>
                )}

                <section
                    aria-label="Cycle progress"
                    className="grid gap-4 rounded-xl border bg-card p-4 shadow-xs"
                >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                            <CycleStatePill state={cycle.status} />
                            {cycle.overdue && (
                                <StatusPill tone="red">Overdue</StatusPill>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {counted === 0
                                ? 'No reviews to close yet'
                                : `${counts.closed} of ${counted} closed`}
                            {counts.skipped > 0 &&
                                ` · ${counts.skipped} skipped`}
                        </p>
                    </div>
                    <div
                        role="progressbar"
                        aria-label="Reviews closed"
                        aria-valuemin={0}
                        aria-valuemax={100}
                        aria-valuenow={percent}
                        className="h-2.5 w-full overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            className="h-full rounded-full bg-primary transition-[width] duration-(--duration-fast)"
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                    <dl className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <Tile label="Period">{cycle.period_label}</Tile>
                        <Tile
                            label={
                                cycle.status === 'closed' ? 'Was due' : 'Due'
                            }
                        >
                            {formatDate(cycle.due_on, dateFormat)}
                        </Tile>
                        <Tile label="Self-review">
                            {cycle.self_review_enabled ? 'On' : 'Off'}
                        </Tile>
                        <Tile
                            label={
                                cycle.status === 'closed' ? 'Closed' : 'Reviews'
                            }
                        >
                            {cycle.status === 'closed' && cycle.closed_on
                                ? formatDate(cycle.closed_on, dateFormat)
                                : cycle.total}
                        </Tile>
                    </dl>
                </section>

                <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem] xl:items-start">
                    <div className="min-w-0 overflow-hidden rounded-xl border bg-card shadow-xs">
                        <StatusTabs
                            label="Review state"
                            idPrefix={ID_PREFIX}
                            value={filters.state}
                            onChange={(key) =>
                                key !== filters.state &&
                                visit({ state: key === '' ? null : key })
                            }
                            tabs={cycleTabs(counts)}
                        />
                        <div
                            id={statusPanelId(ID_PREFIX)}
                            role="tabpanel"
                            aria-labelledby={statusTabId(
                                ID_PREFIX,
                                filters.state,
                            )}
                        >
                            <DataTable
                                label={`Reviews in ${cycle.name}`}
                                bordered={false}
                                columns={columns}
                                rows={reviews.data}
                                rowKey={(row) => row.id}
                                pagination={reviews}
                                search={{
                                    value: filters.search,
                                    placeholder: 'Search by name or code',
                                    label: 'Search reviews',
                                }}
                                toolbar={
                                    <>
                                        {filterSelect(
                                            'Filter by department',
                                            'All departments',
                                            filters.department === null
                                                ? ''
                                                : String(filters.department),
                                            options.departments.map((d) => ({
                                                value: String(d.id),
                                                label: d.name,
                                            })),
                                            (value) =>
                                                visit({ department: value }),
                                        )}
                                        {filterSelect(
                                            'Filter by reviewer',
                                            'All reviewers',
                                            filters.reviewer,
                                            [
                                                { value: 'hr', label: 'HR' },
                                                ...options.reviewers.map(
                                                    (r) => ({
                                                        value: String(r.id),
                                                        label: r.name,
                                                    }),
                                                ),
                                            ],
                                            (value) =>
                                                visit({ reviewer: value }),
                                        )}
                                        {open && (
                                            <Button
                                                variant={
                                                    filters.overdue
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={filters.overdue}
                                                onClick={() =>
                                                    visit({
                                                        overdue: filters.overdue
                                                            ? null
                                                            : '1',
                                                    })
                                                }
                                            >
                                                Overdue only
                                            </Button>
                                        )}
                                    </>
                                }
                                emptyText={
                                    filtering
                                        ? 'No reviews match these filters.'
                                        : 'No reviews in this cycle yet. Add people to start.'
                                }
                                emptyAction={
                                    filtering ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                visit({
                                                    state: null,
                                                    search: null,
                                                    department: null,
                                                    reviewer: null,
                                                    overdue: null,
                                                })
                                            }
                                        >
                                            Clear filters
                                        </Button>
                                    ) : undefined
                                }
                            />
                        </div>
                    </div>

                    {open && (
                        <aside
                            aria-label="People not in this cycle"
                            className="overflow-hidden rounded-xl border bg-card shadow-xs"
                        >
                            <div className="border-b px-4 py-3">
                                <h2 className="font-medium">
                                    Not in this cycle
                                </h2>
                                <p className="text-sm text-muted-foreground">
                                    {notAdded.total === 0
                                        ? 'Everyone who can be reviewed is in.'
                                        : `${notAdded.total} ${notAdded.total === 1 ? 'person was' : 'people were'} active in the period without a review.`}
                                </p>
                            </div>
                            {notAdded.people.length > 0 && (
                                <ul className="max-h-80 divide-y overflow-y-auto text-sm">
                                    {notAdded.people.map((person) => (
                                        <li
                                            key={person.id}
                                            className="flex items-baseline justify-between gap-3 px-4 py-2"
                                        >
                                            <span className="min-w-0">
                                                <span className="block truncate">
                                                    {person.name}
                                                </span>
                                                <span className="text-xs text-muted-foreground">
                                                    {person.code}
                                                </span>
                                            </span>
                                            <span
                                                className={
                                                    person.addable
                                                        ? 'shrink-0 text-xs text-muted-foreground'
                                                        : 'shrink-0 text-xs text-amber-700 dark:text-amber-300'
                                                }
                                            >
                                                {person.reason}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {notAdded.total > 0 && (
                                <div className="border-t px-4 py-3">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        className="w-full"
                                        onClick={() => setAdding(true)}
                                    >
                                        <UserPlus />
                                        {waiting > 0
                                            ? 'Add people'
                                            : 'See who can be added'}
                                    </Button>
                                </div>
                            )}
                        </aside>
                    )}
                </div>

                <p className="text-sm">
                    <Link
                        href={reviewsIndex()}
                        className="text-brand-ink underline underline-offset-2"
                    >
                        Back to all cycles
                    </Link>
                </p>
            </div>

            {adding && (
                <AddPeopleDialog
                    cycleId={cycle.id}
                    cycleName={cycle.name}
                    people={notAdded.people}
                    onClose={() => setAdding(false)}
                />
            )}
            {dueDate && (
                <ChangeDueDateDialog
                    cycleId={cycle.id}
                    dueOn={cycle.due_on}
                    periodEnd={cycle.period_end}
                    onClose={() => setDueDate(false)}
                />
            )}
            <CloseCycleDialog
                cycleId={cycle.id}
                cycleName={cycle.name}
                open={closing}
                onOpenChange={setClosing}
            />
            {skipping && (
                <SkipDialog
                    key={skipping.id}
                    reviewId={skipping.id}
                    employeeName={skipping.employee.name}
                    onClose={() => setSkipping(null)}
                />
            )}
            {changing && (
                <ReviewerDialog
                    key={changing.id}
                    reviewId={changing.id}
                    employeeName={changing.employee.name}
                    currentId={changing.reviewer_id}
                    choices={options.reviewer_choices}
                    onClose={() => setChanging(null)}
                />
            )}
            <ConfirmDialog
                open={direct !== null}
                onOpenChange={(next) => !next && setDirect(null)}
                title={
                    direct
                        ? DIRECT[direct.kind].title(direct.row.employee.name)
                        : ''
                }
                description={
                    <>
                        {direct && DIRECT[direct.kind].text}
                        {directError && (
                            <span
                                role="alert"
                                className="mt-2 block text-red-600 dark:text-red-400"
                            >
                                {directError}
                            </span>
                        )}
                    </>
                }
                confirmLabel={direct ? DIRECT[direct.kind].button : 'Confirm'}
                destructive={false}
                processing={busy}
                onConfirm={runDirect}
            />
        </>
    );
}

Cycle.layout = (props: { cycle: { id: number; name: string } }) => ({
    breadcrumbs: [
        { title: 'Reviews', href: reviewsIndex() },
        { title: props.cycle.name, href: show(props.cycle.id) },
    ],
});
