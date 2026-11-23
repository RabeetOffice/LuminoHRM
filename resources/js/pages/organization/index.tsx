import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/DepartmentController';
import DesignationController from '@/actions/App/Http/Controllers/DesignationController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { DepartmentDialog } from '@/components/organization/department-dialog';
import type { DepartmentRow } from '@/components/organization/department-dialog';
import { DesignationDialog } from '@/components/organization/designation-dialog';
import type {
    DepartmentOption,
    DesignationRow,
} from '@/components/organization/designation-dialog';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/hooks/use-can';
import { nextTableUrl } from '@/lib/table-query';
import { index } from '@/routes/organization';

type Tab = 'departments' | 'designations';

type Props = {
    departments: DataTablePagination & { data: DepartmentRow[] };
    designations: DataTablePagination & { data: DesignationRow[] };
    counts: { departments: number; designations: number };
    departmentOptions: DepartmentOption[];
    filters: {
        tab: Tab;
        search: string;
        per_page: number;
        department_id: number | null;
    };
};

type Editing =
    | { kind: 'department'; row: DepartmentRow | null }
    | { kind: 'designation'; row: DesignationRow | null }
    | null;

type Deleting =
    | { kind: 'department'; row: DepartmentRow }
    | { kind: 'designation'; row: DesignationRow }
    | null;

const ALL_DEPARTMENTS = 'all';
const PANEL_ID = 'organization-panel';

const dateFormat = new Intl.DateTimeFormat('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
});

const formatDate = (iso: string | null) =>
    iso ? dateFormat.format(new Date(iso)) : '';

export default function Organization({
    departments,
    designations,
    counts,
    departmentOptions,
    filters,
}: Props) {
    const { url } = usePage();
    const can = useCan();
    const canManage = can('org.manage');
    const tab = filters.tab;

    const [editing, setEditing] = useState<Editing>(null);
    const [deleting, setDeleting] = useState<Deleting>(null);
    const [deleteBusy, setDeleteBusy] = useState(false);

    const switchTab = (key: string) => {
        if (key === tab) {
            return;
        }

        // Search belongs to one tab, so it does not follow the switch.
        router.get(
            nextTableUrl(url, {
                tab: key === 'designations' ? 'designations' : null,
                search: null,
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const filterByDepartment = (value: string) => {
        router.get(
            nextTableUrl(url, {
                department_id: value === ALL_DEPARTMENTS ? null : value,
                desig_page: null,
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }

        const target =
            deleting.kind === 'department'
                ? DepartmentController.destroy(deleting.row.id)
                : DesignationController.destroy(deleting.row.id);

        router.delete(target.url, {
            preserveScroll: true,
            onStart: () => setDeleteBusy(true),
            // Success and refusal both end here; the toast says which.
            onFinish: () => {
                setDeleteBusy(false);
                setDeleting(null);
            },
        });
    };

    const rowActions = (
        kind: 'department' | 'designation',
        row: DepartmentRow | DesignationRow,
    ) =>
        canManage && (
            <div className="flex justify-end gap-1">
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8"
                    aria-label={`Edit ${row.name}`}
                    title="Edit"
                    onClick={() =>
                        setEditing(
                            kind === 'department'
                                ? { kind, row: row as DepartmentRow }
                                : { kind, row: row as DesignationRow },
                        )
                    }
                >
                    <Pencil />
                </Button>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 text-destructive hover:text-destructive"
                    aria-label={`Delete ${row.name}`}
                    title="Delete"
                    onClick={() =>
                        setDeleting(
                            kind === 'department'
                                ? { kind, row: row as DepartmentRow }
                                : { kind, row: row as DesignationRow },
                        )
                    }
                >
                    <Trash2 />
                </Button>
            </div>
        );

    const departmentColumns: DataTableColumn<DepartmentRow>[] = [
        {
            key: 'name',
            header: 'Name',
            className: 'font-medium',
            cell: (row) => row.name,
        },
        {
            key: 'designations',
            header: 'Designations',
            className: 'tabular-nums text-muted-foreground',
            cell: (row) => row.designations_count,
        },
        {
            key: 'created',
            header: 'Created',
            // Hidden on phones so the row actions stay on screen.
            className:
                'hidden whitespace-nowrap text-muted-foreground sm:table-cell',
            cell: (row) => formatDate(row.created_at),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (row) => rowActions('department', row),
        },
    ];

    const designationColumns: DataTableColumn<DesignationRow>[] = [
        {
            key: 'name',
            header: 'Name',
            className: 'font-medium',
            cell: (row) => row.name,
        },
        {
            key: 'department',
            header: 'Department',
            className: 'text-muted-foreground',
            cell: (row) => row.department_name,
        },
        {
            key: 'created',
            header: 'Created',
            // Hidden on phones so the row actions stay on screen.
            className:
                'hidden whitespace-nowrap text-muted-foreground sm:table-cell',
            cell: (row) => formatDate(row.created_at),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (row) => rowActions('designation', row),
        },
    ];

    const tabs = (
        <StatusTabs
            label="Organization sections"
            panelId={PANEL_ID}
            value={tab}
            onChange={switchTab}
            tabs={[
                {
                    key: 'departments',
                    label: 'Departments',
                    count: counts.departments,
                },
                {
                    key: 'designations',
                    label: 'Designations',
                    count: counts.designations,
                },
            ]}
        />
    );

    const searching = filters.search !== '';

    return (
        <>
            <Head title="Departments & Designations" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Departments & Designations"
                    subtitle="Manage the departments of your company and the job titles inside them."
                    actions={
                        canManage && (
                            <Button
                                onClick={() =>
                                    setEditing(
                                        tab === 'departments'
                                            ? { kind: 'department', row: null }
                                            : {
                                                  kind: 'designation',
                                                  row: null,
                                              },
                                    )
                                }
                            >
                                <Plus />
                                {tab === 'departments'
                                    ? 'Add department'
                                    : 'Add designation'}
                            </Button>
                        )
                    }
                />

                <div id={PANEL_ID} role="tabpanel">
                    {tab === 'departments' ? (
                        <DataTable
                            key="departments"
                            label="Departments"
                            header={tabs}
                            columns={departmentColumns}
                            rows={departments.data}
                            rowKey={(row) => row.id}
                            pagination={departments}
                            pageParam="dept_page"
                            search={{
                                value: filters.search,
                                placeholder: 'Search departments',
                                label: 'Search departments',
                            }}
                            emptyText={
                                searching
                                    ? 'No departments match your search.'
                                    : 'No departments yet. Add the first one to get started.'
                            }
                        />
                    ) : (
                        <DataTable
                            key="designations"
                            label="Designations"
                            header={tabs}
                            columns={designationColumns}
                            rows={designations.data}
                            rowKey={(row) => row.id}
                            pagination={designations}
                            pageParam="desig_page"
                            search={{
                                value: filters.search,
                                placeholder: 'Search designations',
                                label: 'Search designations',
                            }}
                            toolbar={
                                <Select
                                    value={
                                        filters.department_id === null
                                            ? ALL_DEPARTMENTS
                                            : String(filters.department_id)
                                    }
                                    onValueChange={filterByDepartment}
                                >
                                    <SelectTrigger
                                        className="w-full sm:w-52"
                                        aria-label="Filter by department"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_DEPARTMENTS}>
                                            All departments
                                        </SelectItem>
                                        {departmentOptions.map((option) => (
                                            <SelectItem
                                                key={option.id}
                                                value={String(option.id)}
                                            >
                                                {option.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            }
                            emptyText={
                                searching || filters.department_id !== null
                                    ? 'No designations match your filters.'
                                    : 'No designations yet. Add a department first, then its job titles.'
                            }
                        />
                    )}
                </div>
            </div>

            {editing?.kind === 'department' && (
                <DepartmentDialog
                    key={editing.row?.id ?? 'new'}
                    department={editing.row}
                    onClose={() => setEditing(null)}
                />
            )}
            {editing?.kind === 'designation' && (
                <DesignationDialog
                    key={editing.row?.id ?? 'new'}
                    designation={editing.row}
                    departments={departmentOptions}
                    defaultDepartmentId={filters.department_id}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={
                    deleting?.kind === 'designation'
                        ? 'Delete designation?'
                        : 'Delete department?'
                }
                description={
                    deleting
                        ? `"${deleting.row.name}" will be deleted. This cannot be undone.`
                        : ''
                }
                processing={deleteBusy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

Organization.layout = {
    breadcrumbs: [
        {
            title: 'Departments & Designations',
            href: index(),
        },
    ],
};
