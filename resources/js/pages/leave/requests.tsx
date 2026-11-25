import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Plus, X } from 'lucide-react';
import { useId, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { Avatar } from '@/components/hrm/avatar';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { ApplyLeaveDialog } from '@/components/leave/apply-leave-dialog';
import { CancelApprovedDialog } from '@/components/leave/cancel-approved-dialog';
import { DecisionDialog } from '@/components/leave/decision-dialog';
import type { DecisionChoice } from '@/components/leave/decision-dialog';
import { LeaveTypeBadge } from '@/components/leave/leave-type-badge';
import { WithdrawRequestDialog } from '@/components/leave/withdraw-request-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import {
    formatLeaveDays,
    leaveRangeLabel,
    leaveStatusLabel,
} from '@/lib/leave';
import type { LeaveHalf, LeaveStatus } from '@/lib/leave';
import { filterPeople, requestPill } from '@/lib/leave-requests';
import type { LeaveStage } from '@/lib/leave-requests';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { cn } from '@/lib/utils';
import { index, show } from '@/routes/leave/requests';

type Tab = 'waiting' | 'all';

export type LeaveRequestRow = {
    id: number;
    employee: {
        id: number;
        name: string;
        code: string;
        photo_url: string | null;
    };
    type: { id: number; name: string; colour: string; is_paid: boolean };
    /** "Y-m-d", never passed through Date. */
    from: string;
    to: string;
    half: LeaveHalf | null;
    days: number;
    applied_on: string | null;
    status: LeaveStatus;
    stage: LeaveStage;
    manager_first_name: string | null;
    manager_name: string | null;
    manager_approval_final: boolean;
    partly_cancelled: boolean;
    cancelled_from: string | null;
    can: {
        decide: 'manager' | 'final' | null;
        skips_manager: boolean;
        cancel_open: boolean;
        cancel_approved: boolean;
    };
    cancel_range: { min: string; max: string; default: string } | null;
    /** The viewer's own request. */
    is_own: boolean;
};

type Option = { id: number; name: string };

type Props = {
    tab: Tab;
    filters: {
        status: string;
        type: number | null;
        department: number | null;
        from: string;
        to: string;
        search: string;
    };
    requests: DataTablePagination & { data: LeaveRequestRow[] };
    counts: Record<Tab, number>;
    statuses: LeaveStatus[];
    types: (Option & {
        colour: string;
        is_paid: boolean;
        is_active: boolean;
    })[];
    departments: Option[];
    today: string;
    can: { apply_on_behalf: boolean };
    on_behalf: {
        employees: (Option & { code: string })[];
        window: { min: string; max: string };
    } | null;
};

const ID_PREFIX = 'leave-requests';
const ALL = 'all';

const TAB_LABELS: Record<Tab, string> = {
    waiting: 'Waiting for me',
    all: 'All',
};

export default function LeaveRequests({
    tab,
    filters,
    requests,
    counts,
    statuses,
    types,
    departments,
    today,
    can,
    on_behalf,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;

    // The last row asked about stays set while a dialog animates closed, so its text does not change mid-close.
    const [deciding, setDeciding] = useState<{
        row: LeaveRequestRow;
        decision: DecisionChoice;
    } | null>(null);
    const [decideOpen, setDecideOpen] = useState(false);
    const [cancelling, setCancelling] = useState<LeaveRequestRow | null>(null);
    const [cancelOpen, setCancelOpen] = useState(false);
    const [withdrawing, setWithdrawing] = useState<LeaveRequestRow | null>(
        null,
    );
    const [withdrawOpen, setWithdrawOpen] = useState(false);
    const [pickOpen, setPickOpen] = useState(false);
    const [picked, setPicked] = useState<number | null>(null);
    const [pickSearch, setPickSearch] = useState('');
    const [applyFor, setApplyFor] = useState<
        (Option & { code: string }) | null
    >(null);
    const [applyOpen, setApplyOpen] = useState(false);

    const visit = (changes: Parameters<typeof nextTableUrl>[1]) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const filtering =
        filters.status !== '' ||
        filters.type !== null ||
        filters.department !== null ||
        filters.from !== '' ||
        filters.to !== '' ||
        filters.search !== '';

    const decide = (row: LeaveRequestRow, decision: DecisionChoice) => {
        setDeciding({ row, decision });
        setDecideOpen(true);
    };

    const columns: DataTableColumn<LeaveRequestRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            cell: (row) => (
                <Link
                    href={show(row.id).url}
                    className="flex items-center gap-3 rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    <Avatar
                        src={row.employee.photo_url}
                        name={row.employee.name}
                    />
                    <span className="grid gap-0.5">
                        <span className="font-medium hover:underline">
                            {row.employee.name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                            {row.employee.code}
                        </span>
                    </span>
                </Link>
            ),
        },
        {
            key: 'type',
            header: 'Type',
            className: 'hidden sm:table-cell',
            cell: (row) => (
                <LeaveTypeBadge name={row.type.name} colour={row.type.colour} />
            ),
        },
        {
            key: 'dates',
            header: 'Dates',
            cell: (row) => (
                <div className="grid gap-0.5 text-sm">
                    <span>{leaveRangeLabel(row.from, row.to, row.half)}</span>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {formatLeaveDays(row.days)}
                        <span className="sm:hidden"> · {row.type.name}</span>
                    </span>
                </div>
            ),
        },
        {
            key: 'applied',
            header: 'Applied',
            className: 'hidden whitespace-nowrap md:table-cell',
            cell: (row) => formatDate(row.applied_on, dateFormat),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) => {
                const pill = requestPill(
                    row.status,
                    row.stage,
                    row.manager_first_name,
                    row.partly_cancelled,
                );

                return (
                    <div className="grid justify-items-start gap-1">
                        <StatusPill tone={pill.tone}>{pill.label}</StatusPill>
                        {pill.note && (
                            <span className="text-xs text-muted-foreground">
                                {pill.note}
                                {row.partly_cancelled && row.cancelled_from
                                    ? ` from ${formatDate(row.cancelled_from, dateFormat)}`
                                    : ''}
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
                <div className="flex flex-wrap justify-end gap-1">
                    {row.can.skips_manager && (
                        <Button
                            size="sm"
                            variant="outline"
                            aria-label={`Decide as HR on the leave of ${row.employee.name}, skipping the manager`}
                            onClick={() => decide(row, null)}
                        >
                            Decide as HR (skips manager)
                        </Button>
                    )}
                    {row.can.decide && !row.can.skips_manager && (
                        <>
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label={`Approve the leave of ${row.employee.name}`}
                                onClick={() => decide(row, 'approve')}
                            >
                                <Check />
                                Approve
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                aria-label={`Reject the leave of ${row.employee.name}`}
                                onClick={() => decide(row, 'reject')}
                            >
                                <X />
                                Reject
                            </Button>
                        </>
                    )}
                    {row.can.cancel_approved && (
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label={`Cancel the approved leave of ${row.employee.name}`}
                            onClick={() => {
                                setCancelling(row);
                                setCancelOpen(true);
                            }}
                        >
                            Cancel
                        </Button>
                    )}
                    {row.can.cancel_open && !row.can.decide && (
                        <Button
                            size="sm"
                            variant="ghost"
                            aria-label={`Cancel the leave request of ${row.employee.name}`}
                            onClick={() => {
                                setWithdrawing(row);
                                setWithdrawOpen(true);
                            }}
                        >
                            Cancel request
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const dateFilter = (key: 'from' | 'to', label: string) => (
        <div className="grid gap-1 sm:flex sm:items-center sm:gap-2">
            <Label
                htmlFor={`leave-filter-${key}`}
                className="text-xs text-muted-foreground"
            >
                {label}
            </Label>
            <Input
                id={`leave-filter-${key}`}
                type="date"
                value={filters[key]}
                onChange={(e) => visit({ [key]: e.target.value || null })}
                className="w-full sm:w-40"
            />
        </div>
    );

    return (
        <>
            <Head title="Leave Requests" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Leave Requests"
                    subtitle="The reporting manager decides first, then HR. HR can also decide a request the manager has not answered. Approved leave is counted again when it is approved and lands in attendance as On Leave."
                    actions={
                        can.apply_on_behalf && on_behalf ? (
                            <Button
                                onClick={() => {
                                    setPicked(null);
                                    setPickSearch('');
                                    setPickOpen(true);
                                }}
                            >
                                <Plus />
                                Apply on behalf
                            </Button>
                        ) : undefined
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Requests"
                        idPrefix={ID_PREFIX}
                        value={tab}
                        onChange={(key) =>
                            key !== tab &&
                            visit({ tab: key === 'waiting' ? null : key })
                        }
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
                            label="Leave requests"
                            bordered={false}
                            columns={columns}
                            rows={requests.data}
                            rowKey={(row) => row.id}
                            pagination={requests}
                            pageParam="page"
                            search={{
                                value: filters.search,
                                placeholder: 'Search by name or ID',
                                label: 'Search leave requests',
                            }}
                            toolbar={
                                <>
                                    <Select
                                        value={filters.status || ALL}
                                        onValueChange={(v) =>
                                            visit({
                                                status: v === ALL ? null : v,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-40"
                                            aria-label="Filter by status"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All statuses
                                            </SelectItem>
                                            {statuses.map((s) => (
                                                <SelectItem key={s} value={s}>
                                                    {s === 'manager_approved'
                                                        ? 'Pending (waiting for HR)'
                                                        : s === 'pending'
                                                          ? 'Pending (waiting for manager)'
                                                          : leaveStatusLabel(s)}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value={
                                            filters.type === null
                                                ? ALL
                                                : String(filters.type)
                                        }
                                        onValueChange={(v) =>
                                            visit({
                                                type: v === ALL ? null : v,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-40"
                                            aria-label="Filter by leave type"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All types
                                            </SelectItem>
                                            {types.map((t) => (
                                                <SelectItem
                                                    key={t.id}
                                                    value={String(t.id)}
                                                >
                                                    {t.name}
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
                                        onValueChange={(v) =>
                                            visit({
                                                department:
                                                    v === ALL ? null : v,
                                            })
                                        }
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-44"
                                            aria-label="Filter by department"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All Departments
                                            </SelectItem>
                                            {departments.map((d) => (
                                                <SelectItem
                                                    key={d.id}
                                                    value={String(d.id)}
                                                >
                                                    {d.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {dateFilter('from', 'From')}
                                    {dateFilter('to', 'To')}
                                </>
                            }
                            emptyText={
                                filtering
                                    ? 'No leave requests match these filters.'
                                    : tab === 'waiting'
                                      ? 'Nothing is waiting for your decision.'
                                      : 'No leave requests yet.'
                            }
                            emptyAction={
                                filtering ? (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            visit({
                                                status: null,
                                                type: null,
                                                department: null,
                                                from: null,
                                                to: null,
                                                search: null,
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
            </div>

            <DecisionDialog
                open={decideOpen}
                onOpenChange={setDecideOpen}
                subject={deciding?.row ?? null}
                decision={deciding?.decision ?? null}
            />

            <CancelApprovedDialog
                open={cancelOpen}
                onOpenChange={setCancelOpen}
                subject={cancelling}
                today={today}
            />

            <WithdrawRequestDialog
                open={withdrawOpen}
                onOpenChange={setWithdrawOpen}
                subject={withdrawing}
            />

            {on_behalf && (
                <>
                    <Dialog open={pickOpen} onOpenChange={setPickOpen}>
                        <DialogContent className="sm:max-w-md">
                            <DialogHeader>
                                <DialogTitle>Apply on behalf</DialogTitle>
                                <DialogDescription>
                                    Choose the employee. The request is recorded
                                    as applied by you and follows the usual
                                    approval steps; you may give the final
                                    approval yourself.
                                </DialogDescription>
                            </DialogHeader>
                            <EmployeePicker
                                people={on_behalf.employees}
                                search={pickSearch}
                                onSearch={setPickSearch}
                                picked={picked}
                                onPick={setPicked}
                            />
                            <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                                <Button
                                    variant="outline"
                                    onClick={() => setPickOpen(false)}
                                >
                                    Back
                                </Button>
                                <Button
                                    disabled={picked === null}
                                    onClick={() => {
                                        const found =
                                            on_behalf.employees.find(
                                                (e) => e.id === picked,
                                            ) ?? null;
                                        setApplyFor(found);
                                        setPickOpen(false);
                                        setApplyOpen(found !== null);
                                    }}
                                >
                                    Continue
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>

                    <ApplyLeaveDialog
                        open={applyOpen}
                        onClose={() => setApplyOpen(false)}
                        types={types.filter((t) => t.is_active)}
                        window={on_behalf.window}
                        employeeId={applyFor?.id ?? null}
                        employeeName={applyFor?.name ?? null}
                    />
                </>
            )}
        </>
    );
}

const PICKER_LIMIT = 50;

type Person = Option & { code: string };

/**
 * The on-behalf employee picker: a search box over the people the server says this user may apply for, and the
 * matching names as a list (the first 50; type more to narrow). Arrow keys move, Enter picks.
 */
function EmployeePicker({
    people,
    search,
    onSearch,
    picked,
    onPick,
}: {
    people: Person[];
    search: string;
    onSearch: (search: string) => void;
    picked: number | null;
    onPick: (id: number) => void;
}) {
    const listId = useId();
    const matches = filterPeople(people, search);
    const shown = matches.slice(0, PICKER_LIMIT);
    const activeIndex = Math.max(
        0,
        shown.findIndex((p) => p.id === picked),
    );

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (shown.length === 0) {
            return;
        }

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const step = event.key === 'ArrowDown' ? 1 : -1;
            const current = shown.findIndex((p) => p.id === picked);
            const next =
                current === -1
                    ? 0
                    : Math.min(Math.max(current + step, 0), shown.length - 1);
            onPick(shown[next].id);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onPick(shown[activeIndex].id);
        }
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor="on-behalf-employee">Employee</Label>
            <Input
                id="on-behalf-employee"
                role="combobox"
                aria-expanded="true"
                aria-controls={listId}
                aria-autocomplete="list"
                aria-activedescendant={
                    picked !== null ? `${listId}-${picked}` : undefined
                }
                autoComplete="off"
                placeholder="Search by name or ID"
                value={search}
                onChange={(e) => onSearch(e.target.value)}
                onKeyDown={onKeyDown}
            />
            <ul
                id={listId}
                role="listbox"
                aria-label="Employees"
                className="max-h-64 overflow-auto rounded-md border p-1"
            >
                {shown.map((person) => (
                    <li
                        key={person.id}
                        id={`${listId}-${person.id}`}
                        role="option"
                        aria-selected={person.id === picked}
                        onClick={() => onPick(person.id)}
                        className={cn(
                            'flex cursor-pointer items-center justify-between gap-3 rounded-sm px-2 py-1.5 text-sm hover:bg-accent',
                            person.id === picked &&
                                'bg-accent font-medium text-accent-foreground',
                        )}
                    >
                        <span>{person.name}</span>
                        <span className="text-xs text-muted-foreground">
                            {person.code}
                        </span>
                    </li>
                ))}
                {shown.length === 0 && (
                    <li
                        role="presentation"
                        className="px-2 py-1.5 text-sm text-muted-foreground"
                    >
                        No one found.
                    </li>
                )}
                {matches.length > shown.length && (
                    <li
                        role="presentation"
                        className="px-2 py-1.5 text-xs text-muted-foreground"
                    >
                        {matches.length - shown.length} more: type more of the
                        name to narrow the list.
                    </li>
                )}
            </ul>
        </div>
    );
}

LeaveRequests.layout = {
    breadcrumbs: [{ title: 'Leave Requests', href: index() }],
};
