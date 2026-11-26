import { Head, Link, router, usePage } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, UserCheck, UserX } from 'lucide-react';
import { useState } from 'react';
import UserController from '@/actions/App/Http/Controllers/UserController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { StatusTabs } from '@/components/hrm/status-tabs';
import { UserDialog } from '@/components/users/user-dialog';
import type { RoleOption, UserRow } from '@/components/users/user-dialog';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useInitials } from '@/hooks/use-initials';
import { nextTableUrl } from '@/lib/table-query';
import { statusPanelId, statusTabId } from '@/lib/tabs';
import { show as showEmployee } from '@/routes/employees';
import { index } from '@/routes/users';

type Status = 'all' | 'active' | 'disabled';

type Props = {
    users: DataTablePagination & { data: UserRow[] };
    counts: { all: number; active: number; disabled: number };
    roleOptions: RoleOption[];
    filters: {
        status: Status;
        role: string;
        search: string;
        per_page: number;
    };
};

const ALL_ROLES = 'all';
const ID_PREFIX = 'users';

export default function Users({ users, counts, roleOptions, filters }: Props) {
    const { url, props } = usePage();
    const getInitials = useInitials();
    const status = filters.status;
    const timezone = props.app.timezone;

    const [editing, setEditing] = useState<{ row: UserRow | null } | null>(
        null,
    );
    const [disabling, setDisabling] = useState<UserRow | null>(null);
    const [busy, setBusy] = useState(false);

    const roleLabel = (role: string | null) =>
        roleOptions.find((option) => option.value === role)?.label ??
        role ??
        'No role';

    const lastSignIn = (iso: string | null) =>
        iso
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  timeZone: timezone,
              }).format(new Date(iso))
            : 'Never';

    const switchTab = (key: string) => {
        if (key === status) {
            return;
        }

        router.get(
            nextTableUrl(url, {
                status: key === 'all' ? null : key,
                page: null,
            }),
            {},
            { preserveScroll: true, preserveState: true },
        );
    };

    const filterByRole = (value: string) => {
        router.get(
            nextTableUrl(url, {
                role: value === ALL_ROLES ? null : value,
                page: null,
            }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const enable = (row: UserRow) => {
        router.patch(
            UserController.toggle(row.id).url,
            { active: true },
            { preserveScroll: true },
        );
    };

    const sendReset = (row: UserRow) => {
        router.post(
            UserController.sendReset(row.id).url,
            {},
            { preserveScroll: true },
        );
    };

    const confirmDisable = () => {
        if (!disabling) {
            return;
        }

        router.patch(
            UserController.toggle(disabling.id).url,
            { active: false },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                // Success and refusal both end here; the toast says which.
                onFinish: () => {
                    setBusy(false);
                    setDisabling(null);
                },
            },
        );
    };

    const columns: DataTableColumn<UserRow>[] = [
        {
            key: 'name',
            header: 'Name',
            cell: (row) => (
                <div className="flex items-center gap-3">
                    <Avatar className="size-8">
                        <AvatarFallback className="bg-primary/15 text-xs font-medium text-brand-ink">
                            {getInitials(row.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 font-medium">
                            <span className="truncate">{row.name}</span>
                            {row.is_self && (
                                <Badge variant="outline" className="shrink-0">
                                    You
                                </Badge>
                            )}
                        </div>
                        {/* Below xl (a laptop with the sidebar open has about 650px) the email, linked employee and last sign-in sit under the name; role and status sit here on a phone. */}
                        <div className="max-w-[14rem] truncate text-xs text-muted-foreground xl:hidden">
                            {row.email}
                        </div>
                        <div className="text-xs text-muted-foreground xl:hidden">
                            {row.employee
                                ? `${row.employee.name} · ${row.employee.code}`
                                : 'Not linked to an employee'}
                            {' · '}
                            {lastSignIn(row.last_login_at)}
                        </div>
                        <div className="mt-1 flex flex-wrap items-center gap-1 sm:hidden">
                            <Badge variant="secondary">
                                {roleLabel(row.role)}
                            </Badge>
                            <StatusPill tone={row.is_active ? 'green' : 'grey'}>
                                {row.is_active ? 'Active' : 'Disabled'}
                            </StatusPill>
                        </div>
                    </div>
                </div>
            ),
        },
        {
            key: 'email',
            header: 'Email',
            className: 'hidden text-muted-foreground xl:table-cell',
            cell: (row) => row.email,
        },
        {
            key: 'employee',
            header: 'Employee',
            className: 'hidden xl:table-cell',
            cell: (row) =>
                row.employee ? (
                    <Link
                        href={showEmployee(row.employee.id)}
                        className="group block text-sm"
                    >
                        <span className="block font-medium group-hover:underline">
                            {row.employee.name}
                        </span>
                        <span className="block text-xs whitespace-nowrap text-muted-foreground">
                            {row.employee.code}
                        </span>
                    </Link>
                ) : (
                    <span className="text-muted-foreground">
                        <span aria-hidden="true">—</span>
                        <span className="sr-only">Not linked</span>
                    </span>
                ),
        },
        {
            key: 'role',
            header: 'Role',
            className: 'hidden sm:table-cell',
            cell: (row) => (
                <Badge variant="secondary">{roleLabel(row.role)}</Badge>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            className: 'hidden sm:table-cell',
            cell: (row) => (
                <StatusPill tone={row.is_active ? 'green' : 'grey'}>
                    {row.is_active ? 'Active' : 'Disabled'}
                </StatusPill>
            ),
        },
        {
            key: 'last_sign_in',
            header: 'Last sign-in',
            className:
                'hidden whitespace-nowrap text-muted-foreground xl:table-cell',
            cell: (row) => lastSignIn(row.last_login_at),
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-28 sm:w-36',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={`Edit ${row.name}`}
                        title="Edit"
                        onClick={() => setEditing({ row })}
                    >
                        <Pencil />
                    </Button>
                    {row.is_active && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Send reset link to ${row.name}`}
                            title="Send password reset link"
                            onClick={() => sendReset(row)}
                        >
                            <KeyRound />
                        </Button>
                    )}
                    {!row.is_self &&
                        (row.is_active ? (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8 text-destructive hover:text-destructive"
                                aria-label={`Disable ${row.name}`}
                                title="Disable account"
                                onClick={() => setDisabling(row)}
                            >
                                <UserX />
                            </Button>
                        ) : (
                            <Button
                                variant="ghost"
                                size="icon"
                                className="size-8"
                                aria-label={`Enable ${row.name}`}
                                title="Enable account"
                                onClick={() => enable(row)}
                            >
                                <UserCheck />
                            </Button>
                        ))}
                </div>
            ),
        },
    ];

    const filtering = filters.search !== '' || filters.role !== '';

    return (
        <>
            <Head title="Users & Roles" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Users & Roles"
                    subtitle="Create sign-in accounts, choose what each person can do, and disable access when someone leaves."
                    actions={
                        <Button onClick={() => setEditing({ row: null })}>
                            <Plus />
                            Add user
                        </Button>
                    }
                />

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <StatusTabs
                        label="Account status"
                        idPrefix={ID_PREFIX}
                        value={status}
                        onChange={switchTab}
                        tabs={[
                            { key: 'all', label: 'All', count: counts.all },
                            {
                                key: 'active',
                                label: 'Active',
                                count: counts.active,
                            },
                            {
                                key: 'disabled',
                                label: 'Disabled',
                                count: counts.disabled,
                            },
                        ]}
                    />
                    <div
                        id={statusPanelId(ID_PREFIX)}
                        role="tabpanel"
                        aria-labelledby={statusTabId(ID_PREFIX, status)}
                    >
                        <DataTable
                            label="Users"
                            bordered={false}
                            columns={columns}
                            rows={users.data}
                            rowKey={(row) => row.id}
                            pagination={users}
                            pageParam="page"
                            search={{
                                value: filters.search,
                                placeholder: 'Search by name or email',
                                label: 'Search users',
                            }}
                            toolbar={
                                <Select
                                    value={
                                        filters.role === ''
                                            ? ALL_ROLES
                                            : filters.role
                                    }
                                    onValueChange={filterByRole}
                                >
                                    <SelectTrigger
                                        className="w-full sm:w-44"
                                        aria-label="Filter by role"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={ALL_ROLES}>
                                            All roles
                                        </SelectItem>
                                        {roleOptions.map((option) => (
                                            <SelectItem
                                                key={option.value}
                                                value={option.value}
                                            >
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            }
                            emptyText={
                                filtering
                                    ? 'No users match your filters.'
                                    : status === 'disabled'
                                      ? 'No disabled accounts.'
                                      : 'No users yet.'
                            }
                        />
                    </div>
                </div>
            </div>

            {editing && (
                <UserDialog
                    key={editing.row?.id ?? 'new'}
                    user={editing.row}
                    roles={roleOptions}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={disabling !== null}
                onOpenChange={(open) => !open && setDisabling(null)}
                title="Disable account?"
                description={
                    disabling
                        ? `${disabling.name} will be signed out at once and will not be able to sign in until you enable the account again.`
                        : ''
                }
                confirmLabel="Disable"
                processing={busy}
                onConfirm={confirmDisable}
            />
        </>
    );
}

Users.layout = {
    breadcrumbs: [
        {
            title: 'Users & Roles',
            href: index(),
        },
    ],
};
