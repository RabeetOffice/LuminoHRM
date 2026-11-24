import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Download,
    Eye,
    KeyRound,
    Pencil,
    Plus,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import { DeleteEmployeeDialog } from '@/components/employees/delete-employee-dialog';
import { STATUS_TONE } from '@/components/employees/status-dialog';
import { Avatar } from '@/components/hrm/avatar';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { ViewToggle } from '@/components/hrm/view-toggle';
import type { ViewMode } from '@/components/hrm/view-toggle';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { cn } from '@/lib/utils';
import {
    create,
    edit,
    exportMethod,
    importMethod,
    index,
    sendLinks,
    show,
} from '@/routes/employees';

type Login = 'active' | 'disabled' | 'none';

type EmployeeRow = {
    id: number;
    code: string;
    name: string;
    email: string;
    photo_url: string | null;
    department: string;
    designation: string;
    status: string;
    joining_date: string;
    login: Login;
};

type Option = { id: number; name: string };

type Props = {
    employees: DataTablePagination & { data: EmployeeRow[] };
    counts: Record<string, number>;
    filters: {
        search: string;
        department_id: number | null;
        designation_id: number | null;
        status: string;
        view: ViewMode;
        per_page: number;
    };
    departments: Option[];
    designations: Option[];
    can: { manage: boolean; delete: boolean; import: boolean; export: boolean };
};

const ID_PREFIX = 'employees';
const ALL = 'all';

const STATUS_TABS = [
    { key: 'all', label: 'All' },
    { key: 'active', label: 'Active' },
    { key: 'probation', label: 'Probation' },
    { key: 'inactive', label: 'Inactive' },
    { key: 'resigned', label: 'Resigned' },
    { key: 'terminated', label: 'Terminated' },
];

const LOGIN_PILL: Record<Login, { tone: StatusTone; label: string }> = {
    active: { tone: 'green', label: 'Active' },
    disabled: { tone: 'amber', label: 'Disabled' },
    none: { tone: 'grey', label: 'No account' },
};

const statusLabel = (status: string) =>
    STATUS_TABS.find((tab) => tab.key === status)?.label ?? status;

/** "Send set-password links" takes at most this many people per click (the server refuses more). */
const MAX_LINKS = 50;

function CodeBadge({ code }: { code: string }) {
    return (
        <span className="inline-flex rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
            {code}
        </span>
    );
}

function RowActions({
    row,
    can,
    onDelete,
    className,
}: {
    row: EmployeeRow;
    can: Props['can'];
    onDelete: (row: EmployeeRow) => void;
    className?: string;
}) {
    return (
        <div className={cn('flex justify-end gap-0.5', className)}>
            <Button asChild variant="ghost" size="icon" className="size-7">
                <Link
                    href={show(row.id)}
                    aria-label={`View ${row.name}`}
                    title="View"
                >
                    <Eye />
                </Link>
            </Button>
            {can.manage && (
                <Button asChild variant="ghost" size="icon" className="size-7">
                    <Link
                        href={edit(row.id)}
                        aria-label={`Edit ${row.name}`}
                        title="Edit"
                    >
                        <Pencil />
                    </Link>
                </Button>
            )}
            {can.delete && (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7 text-destructive"
                    aria-label={`Delete ${row.name}`}
                    title="Delete"
                    onClick={() => onDelete(row)}
                >
                    <Trash2 />
                </Button>
            )}
        </div>
    );
}

export default function Employees({
    employees,
    counts,
    filters,
    departments,
    designations,
    can,
}: Props) {
    const { url, props } = usePage();
    const dateFormat = props.app.dateFormat;
    const [deleting, setDeleting] = useState<EmployeeRow | null>(null);
    // Selection for "Send set-password links": ids on the current page only, cleared when the rows change.
    const [selected, setSelected] = useState<number[]>([]);
    const [selectionFor, setSelectionFor] = useState(employees.data);
    const [confirmingLinks, setConfirmingLinks] = useState(false);
    const [sendingLinks, setSendingLinks] = useState(false);

    if (selectionFor !== employees.data) {
        setSelectionFor(employees.data);
        setSelected([]);
    }

    const pageIds = employees.data.map((row) => row.id);
    const allSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const toggleRow = (id: number, on: boolean) =>
        setSelected((current) =>
            on ? [...current, id] : current.filter((value) => value !== id),
        );
    const toggleAll = (on: boolean) => setSelected(on ? pageIds : []);

    const sendSelectedLinks = () =>
        router.post(
            sendLinks.url(),
            { ids: selected },
            {
                preserveScroll: true,
                onStart: () => setSendingLinks(true),
                onFinish: () => {
                    setSendingLinks(false);
                    setConfirmingLinks(false);
                    setSelected([]);
                },
            },
        );

    // The export carries the list's filters (not the page or the view): every matching row.
    const exportQuery = Object.fromEntries(
        Object.entries({
            search: filters.search,
            department_id: filters.department_id,
            designation_id: filters.designation_id,
            status: filters.status,
        }).filter(([, value]) => value !== '' && value !== null),
    );
    const status = filters.status === '' ? ALL : filters.status;
    const filtering =
        filters.search !== '' ||
        filters.department_id !== null ||
        filters.designation_id !== null ||
        filters.status !== '';

    const visit = (
        changes: Parameters<typeof nextTableUrl>[1],
        replace = false,
    ) =>
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace },
        );

    const switchTab = (key: string) => {
        if (key !== status) {
            visit({ status: key === ALL ? null : key });
        }
    };

    const filterByDepartment = (value: string) =>
        // A designation belongs to one department, so choosing another department drops it.
        visit(
            value === ALL
                ? { department_id: null }
                : { department_id: value, designation_id: null },
            true,
        );

    const filterByDesignation = (value: string) =>
        visit({ designation_id: value === ALL ? null : value }, true);

    const changeView = (view: ViewMode) =>
        router.get(
            nextTableUrl(url, { view: view === 'list' ? null : view }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );

    const clearFilters = () =>
        visit({
            search: null,
            department_id: null,
            designation_id: null,
            status: null,
        });

    const firstNumber = employees.from ?? 1;

    const selectColumn: DataTableColumn<EmployeeRow> = {
        key: 'select',
        header: (
            <Checkbox
                checked={allSelected}
                onCheckedChange={(value) => toggleAll(value === true)}
                aria-label="Select all employees on this page"
            />
        ),
        className: 'w-8 pr-0',
        cell: (row) => (
            <Checkbox
                checked={selected.includes(row.id)}
                onCheckedChange={(value) => toggleRow(row.id, value === true)}
                aria-label={`Select ${row.name}`}
            />
        ),
    };

    const columns: DataTableColumn<EmployeeRow>[] = [
        ...(can.manage ? [selectColumn] : []),
        {
            key: 'number',
            header: '#',
            className: 'w-10 pr-3 text-muted-foreground tabular-nums',
            cell: (row) => firstNumber + employees.data.indexOf(row),
        },
        {
            key: 'name',
            header: 'Name',
            className: 'min-w-48 px-3',
            cell: (row) => (
                <div className="flex items-center gap-3">
                    <Avatar src={row.photo_url} name={row.name} />
                    <div className="min-w-0">
                        <Link
                            href={show(row.id)}
                            className="block truncate font-medium hover:underline focus-visible:underline"
                        >
                            {row.name}
                        </Link>
                        <div className="max-w-40 truncate text-xs text-muted-foreground">
                            {row.email}
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'code',
            header: 'Employee ID',
            className: 'px-3 whitespace-nowrap',
            cell: (row) => <CodeBadge code={row.code} />,
        },
        {
            key: 'department',
            header: 'Department',
            className: 'min-w-28 px-3',
            cell: (row) => row.department,
        },
        {
            key: 'designation',
            header: 'Designation',
            className: 'min-w-32 px-3',
            cell: (row) => row.designation,
        },
        {
            key: 'status',
            header: 'Status',
            className: 'px-3',
            cell: (row) => (
                <StatusPill tone={STATUS_TONE[row.status] ?? 'grey'}>
                    {statusLabel(row.status)}
                </StatusPill>
            ),
        },
        {
            key: 'joined',
            header: 'Joined',
            className: 'px-3 whitespace-nowrap text-muted-foreground',
            cell: (row) => (
                <span className="inline-flex items-center gap-1.5">
                    <CalendarDays aria-hidden="true" className="size-3.5" />
                    {formatDate(row.joining_date, dateFormat)}
                </span>
            ),
        },
        {
            key: 'login',
            header: 'Login',
            className: 'px-3',
            cell: (row) => (
                <StatusPill tone={LOGIN_PILL[row.login].tone}>
                    {LOGIN_PILL[row.login].label}
                </StatusPill>
            ),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-px pl-3',
            cell: (row) => (
                <RowActions row={row} can={can} onDelete={setDeleting} />
            ),
        },
    ];

    const grid = (
        <ul
            role="list"
            className="grid grid-cols-1 gap-4 p-3 sm:grid-cols-2 sm:p-4 lg:grid-cols-3 2xl:grid-cols-4"
        >
            {employees.data.map((row) => (
                <li
                    key={row.id}
                    className="relative flex flex-col items-center rounded-xl border bg-card p-5 text-center shadow-xs"
                >
                    {can.manage && (
                        <Checkbox
                            className="absolute top-3 left-3"
                            checked={selected.includes(row.id)}
                            onCheckedChange={(value) =>
                                toggleRow(row.id, value === true)
                            }
                            aria-label={`Select ${row.name}`}
                        />
                    )}
                    <Avatar src={row.photo_url} name={row.name} size="lg" />
                    <h2 className="mt-3 w-full truncate font-semibold">
                        <Link
                            href={show(row.id)}
                            className="hover:underline focus-visible:underline"
                        >
                            {row.name}
                        </Link>
                    </h2>
                    <p className="w-full truncate text-sm">{row.designation}</p>
                    <p className="w-full truncate text-sm text-muted-foreground">
                        {row.department}
                    </p>
                    <div className="mt-3 flex flex-wrap items-center justify-center gap-2">
                        <StatusPill tone={STATUS_TONE[row.status] ?? 'grey'}>
                            {statusLabel(row.status)}
                        </StatusPill>
                        <CodeBadge code={row.code} />
                    </div>
                    <RowActions
                        row={row}
                        can={can}
                        onDelete={setDeleting}
                        className="mt-3 w-full justify-center border-t pt-3"
                    />
                </li>
            ))}
        </ul>
    );

    const addButton = (
        <Button asChild>
            <Link href={create()}>
                <Plus />
                Add Employee
            </Link>
        </Button>
    );

    return (
        <>
            <Head title="Employees" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Employees"
                    subtitle="Manage employees and their information."
                    actions={
                        <>
                            {can.export && (
                                <Button asChild variant="outline">
                                    {/* A file download, not a page visit. */}
                                    <a
                                        href={exportMethod.url({
                                            query: exportQuery,
                                        })}
                                    >
                                        <Download />
                                        Export
                                    </a>
                                </Button>
                            )}
                            {can.import && (
                                <Button asChild variant="outline">
                                    <Link href={importMethod()}>
                                        <Upload />
                                        Import
                                    </Link>
                                </Button>
                            )}
                            {can.manage && addButton}
                        </>
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Employee status"
                        idPrefix={ID_PREFIX}
                        value={status}
                        onChange={switchTab}
                        tabs={STATUS_TABS.map((tab) => ({
                            ...tab,
                            count: counts[tab.key] ?? 0,
                        }))}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, status)}
                    >
                        {can.manage && selected.length > 0 && (
                            <div className="flex flex-col gap-2 border-b bg-muted/40 px-3 py-2 sm:flex-row sm:items-center sm:justify-between sm:px-4">
                                <p className="text-sm" aria-live="polite">
                                    {selected.length} selected
                                    {selected.length > MAX_LINKS &&
                                        ` (at most ${MAX_LINKS} at a time)`}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => setSelected([])}
                                    >
                                        Clear selection
                                    </Button>
                                    <Button
                                        size="sm"
                                        disabled={selected.length > MAX_LINKS}
                                        onClick={() => setConfirmingLinks(true)}
                                    >
                                        <KeyRound />
                                        Send set-password links
                                    </Button>
                                </div>
                            </div>
                        )}
                        <DataTable
                            label="Employees"
                            bordered={false}
                            columns={columns}
                            rows={employees.data}
                            rowKey={(row) => row.id}
                            pagination={employees}
                            pageParam="page"
                            search={{
                                value: filters.search,
                                placeholder: 'Search by name, ID or email',
                                label: 'Search employees',
                            }}
                            toolbar={
                                <>
                                    <Select
                                        value={
                                            filters.department_id === null
                                                ? ALL
                                                : String(filters.department_id)
                                        }
                                        onValueChange={filterByDepartment}
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
                                            {departments.map((option) => (
                                                <SelectItem
                                                    key={option.id}
                                                    value={String(option.id)}
                                                >
                                                    {option.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <Select
                                        value={
                                            filters.designation_id === null
                                                ? ALL
                                                : String(filters.designation_id)
                                        }
                                        onValueChange={filterByDesignation}
                                    >
                                        <SelectTrigger
                                            className="w-full sm:w-48"
                                            aria-label="Filter by designation"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={ALL}>
                                                All Designations
                                            </SelectItem>
                                            {designations.map((option) => (
                                                <SelectItem
                                                    key={option.id}
                                                    value={String(option.id)}
                                                >
                                                    {option.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <ViewToggle
                                        value={filters.view}
                                        onChange={changeView}
                                    />
                                </>
                            }
                            view={filters.view === 'grid' ? grid : undefined}
                            emptyText={
                                filtering
                                    ? 'No employees match these filters.'
                                    : can.manage
                                      ? 'No employees yet. Add the first one to get started.'
                                      : 'There are no employees to show here yet.'
                            }
                            emptyAction={
                                filtering ? (
                                    <Button
                                        variant="outline"
                                        onClick={clearFilters}
                                    >
                                        Clear filters
                                    </Button>
                                ) : can.manage ? (
                                    addButton
                                ) : undefined
                            }
                        />
                    </div>
                </div>
            </div>

            <DeleteEmployeeDialog
                employee={deleting}
                onClose={() => setDeleting(null)}
            />

            <ConfirmDialog
                open={confirmingLinks}
                onOpenChange={setConfirmingLinks}
                title="Send set-password links?"
                description={`Each of the ${selected.length} selected ${selected.length === 1 ? 'employee' : 'employees'} whose login is enabled and has never been used gets an email with a link to choose a password. Anyone else is skipped.`}
                confirmLabel="Send links"
                destructive={false}
                onConfirm={sendSelectedLinks}
                processing={sendingLinks}
            />
        </>
    );
}

Employees.layout = {
    breadcrumbs: [
        {
            title: 'Employees',
            href: index(),
        },
    ],
};
