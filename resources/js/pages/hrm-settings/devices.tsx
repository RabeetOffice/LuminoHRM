import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Power, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import DeviceController from '@/actions/App/Http/Controllers/Settings/DeviceController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { DEVICES_ENTRY, SettingsNav } from '@/components/settings/settings-nav';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { formatClockTime } from '@/lib/clock-time';
import { formatDate } from '@/lib/format-date';
import { punches as punchesRoute } from '@/routes/attendance';
import { index as settingsIndex } from '@/routes/hrm-settings';
import { index } from '@/routes/hrm-settings/devices';
import type { SettingsGroup } from '@/types/hrm-settings';

type DeviceRow = {
    id: number;
    name: string;
    serial_no: string;
    is_active: boolean;
    online: boolean;
    /** Company wall clock, "Y-m-d H:i". */
    last_seen_at: string | null;
    /** Machine time, "Y-m-d H:i". */
    last_punch_at: string | null;
    punches_today: number;
    has_punches: boolean;
};

type LogRow = {
    id: number;
    time: string | null;
    type: string;
    accepted: boolean;
    summary: string;
};

type CommandRow = {
    id: number;
    label: string;
    status: 'queued' | 'sent' | 'done' | 'failed';
    queued_at: string | null;
    sent_at: string | null;
};

type UnknownRow = {
    device_user_id: string;
    punches: number;
    last_punch_at: string;
};

type Props = {
    groups: SettingsGroup[];
    devices: DeviceRow[];
    selected: number | null;
    log: LogRow[];
    commands: CommandRow[];
    unknown: UnknownRow[];
    /** Calendar today in the company zone, "Y-m-d". */
    today: string;
    maxResendDays: number;
    onlineMinutes: number;
};

type Pending = { kind: 'remove' | 'toggle'; row: DeviceRow };

const COMMAND_TONES: Record<CommandRow['status'], StatusTone> = {
    queued: 'amber',
    sent: 'blue',
    done: 'green',
    failed: 'red',
};

const COMMAND_LABELS: Record<CommandRow['status'], string> = {
    queued: 'Waiting for the machine',
    sent: 'Sent',
    done: 'Done',
    failed: 'Failed',
};

/** "Y-m-d H:i" in the company date format and 12-hour time. */
function useMoment() {
    const dateFormat = usePage().props.app.dateFormat;

    return (value: string | null) =>
        value
            ? `${formatDate(value.slice(0, 10), dateFormat)}, ${formatClockTime(value.slice(11, 16))}`
            : '–';
}

function Section({
    title,
    description,
    actions,
    children,
}: {
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="grid gap-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <h3 className="text-base font-semibold">{title}</h3>
                    {description && (
                        <p className="mt-0.5 text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            {children}
        </section>
    );
}

export default function Devices({
    groups,
    devices,
    selected,
    log,
    commands,
    unknown,
    today,
    maxResendDays,
    onlineMinutes,
}: Props) {
    const moment = useMoment();
    const [editing, setEditing] = useState<DeviceRow | 'new' | null>(null);
    const [pending, setPending] = useState<Pending | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const current = devices.find((d) => d.id === selected) ?? null;

    const ask = (kind: Pending['kind'], row: DeviceRow) => {
        setPending({ kind, row });
        setConfirmOpen(true);
    };

    const confirm = () => {
        if (!pending) {
            return;
        }

        const options = {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
        };

        if (pending.kind === 'remove') {
            router.delete(
                DeviceController.destroy(pending.row.id).url,
                options,
            );
        } else {
            router.patch(
                DeviceController.toggle(pending.row.id).url,
                { is_active: !pending.row.is_active },
                options,
            );
        }
    };

    const status = (row: DeviceRow) =>
        !row.is_active ? (
            <StatusPill tone="red">Inactive</StatusPill>
        ) : row.online ? (
            <StatusPill tone="green">Online</StatusPill>
        ) : (
            <StatusPill tone="grey">Offline</StatusPill>
        );

    const deviceColumns: DataTableColumn<DeviceRow>[] = [
        {
            key: 'name',
            header: 'Device',
            cell: (row) => (
                <div className="grid gap-1">
                    <Link
                        href={index({ query: { device: row.id } })}
                        preserveScroll
                        className="font-medium hover:underline"
                        aria-current={row.id === selected ? 'true' : undefined}
                    >
                        {row.name}
                    </Link>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.serial_no}
                    </span>
                    <span className="sm:hidden">{status(row)}</span>
                </div>
            ),
        },
        {
            key: 'status',
            header: 'Status',
            className: 'hidden sm:table-cell',
            cell: status,
        },
        {
            key: 'seen',
            header: 'Last contact',
            className:
                'hidden whitespace-nowrap text-muted-foreground md:table-cell',
            cell: (row) => moment(row.last_seen_at),
        },
        {
            key: 'punch',
            header: 'Last punch',
            className:
                'hidden whitespace-nowrap text-muted-foreground lg:table-cell',
            cell: (row) => moment(row.last_punch_at),
        },
        {
            key: 'today',
            header: 'Punches today',
            className: 'tabular-nums',
            cell: (row) => row.punches_today,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-28',
            cell: (row) => (
                <div className="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={`Rename ${row.name}`}
                        title="Rename"
                        onClick={() => setEditing(row)}
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        className="size-8"
                        aria-label={`${row.is_active ? 'Deactivate' : 'Activate'} ${row.name}`}
                        title={row.is_active ? 'Deactivate' : 'Activate'}
                        onClick={() => ask('toggle', row)}
                    >
                        <Power />
                    </Button>
                    {!row.has_punches && (
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-destructive hover:text-destructive"
                            aria-label={`Remove ${row.name}`}
                            title="Remove"
                            onClick={() => ask('remove', row)}
                        >
                            <Trash2 />
                        </Button>
                    )}
                </div>
            ),
        },
    ];

    const logColumns: DataTableColumn<LogRow>[] = [
        {
            key: 'time',
            header: 'Time',
            className: 'whitespace-nowrap text-muted-foreground',
            cell: (row) => moment(row.time),
        },
        {
            key: 'type',
            header: 'Type',
            className: 'whitespace-nowrap',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-2">
                    {row.type}
                    {!row.accepted && (
                        <StatusPill tone="red">Refused</StatusPill>
                    )}
                </div>
            ),
        },
        {
            key: 'summary',
            header: 'Summary',
            className: 'text-muted-foreground',
            cell: (row) => row.summary,
        },
    ];

    const unknownColumns: DataTableColumn<UnknownRow>[] = [
        {
            key: 'id',
            header: 'Machine user ID',
            className: 'font-medium tabular-nums',
            cell: (row) => row.device_user_id,
        },
        {
            key: 'count',
            header: 'Punches',
            className: 'tabular-nums',
            cell: (row) => row.punches,
        },
        {
            key: 'last',
            header: 'Last punch',
            className:
                'hidden whitespace-nowrap text-muted-foreground sm:table-cell',
            cell: (row) => moment(row.last_punch_at),
        },
        {
            key: 'link',
            header: <span className="sr-only">Open</span>,
            className: 'text-right',
            cell: (row) => (
                <Button asChild variant="outline" size="sm">
                    <Link
                        href={punchesRoute({
                            query: { unknown: '1', user: row.device_user_id },
                        })}
                    >
                        View punches
                    </Link>
                </Button>
            ),
        },
    ];

    return (
        <>
            <Head title="ZKTeco Device settings" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Settings"
                    subtitle="Company-wide rules. Each section saves on its own."
                />

                <div className="grid items-start gap-6 lg:grid-cols-[16rem_minmax(0,1fr)]">
                    <SettingsNav groups={groups} active="devices" />

                    <div className="grid min-w-0 gap-8">
                        <div>
                            <h2 className="text-lg font-semibold tracking-tight">
                                {DEVICES_ENTRY.label}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {DEVICES_ENTRY.description}
                            </p>
                        </div>

                        <Section
                            title="Devices"
                            description={`A machine is online when it checked in within the last ${onlineMinutes} minutes. A deactivated machine is refused by the server. A machine that has sent punches cannot be removed, only deactivated.`}
                            actions={
                                <Button onClick={() => setEditing('new')}>
                                    <Plus />
                                    Add device
                                </Button>
                            }
                        >
                            <DataTable
                                label="Devices"
                                columns={deviceColumns}
                                rows={devices}
                                rowKey={(row) => row.id}
                                emptyText="No machines yet. Add one with its serial number, then point its Cloud Server setting at this site."
                            />
                        </Section>

                        {current && (
                            <>
                                {devices.length > 1 && (
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span className="text-muted-foreground">
                                            Showing the log and commands of
                                        </span>
                                        <Select
                                            value={String(current.id)}
                                            onValueChange={(value) =>
                                                router.get(
                                                    index({
                                                        query: {
                                                            device: value,
                                                        },
                                                    }).url,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <SelectTrigger
                                                className="w-full sm:w-56"
                                                aria-label="Device"
                                            >
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {devices.map((device) => (
                                                    <SelectItem
                                                        key={device.id}
                                                        value={String(
                                                            device.id,
                                                        )}
                                                    >
                                                        {device.name}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                <ResendCard
                                    key={current.id}
                                    device={current}
                                    commands={commands}
                                    today={today}
                                    maxDays={maxResendDays}
                                />

                                <Section
                                    title="Sync log"
                                    description={`What ${current.name} sent in the last 7 days, newest first. Older entries are deleted automatically.`}
                                >
                                    <DataTable
                                        label="Sync log"
                                        columns={logColumns}
                                        rows={log}
                                        rowKey={(row) => row.id}
                                        emptyText="Nothing received from this machine in the last 7 days."
                                    />
                                </Section>
                            </>
                        )}

                        <Section
                            title="Unknown user IDs"
                            description="Punches whose machine user ID no employee has. Give the ID to the right employee from Raw Punches or the employee form; their punches are then worked into attendance."
                        >
                            <DataTable
                                label="Unknown user IDs"
                                columns={unknownColumns}
                                rows={unknown}
                                rowKey={(row) => row.device_user_id}
                                emptyText="Every punch belongs to an employee."
                            />
                        </Section>
                    </div>
                </div>
            </div>

            {editing !== null && (
                <DeviceDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    device={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={
                    pending?.kind === 'remove'
                        ? 'Remove device?'
                        : pending?.row.is_active
                          ? 'Deactivate device?'
                          : 'Activate device?'
                }
                description={
                    !pending
                        ? ''
                        : pending.kind === 'remove'
                          ? `"${pending.row.name}" will be removed. This cannot be undone.`
                          : pending.row.is_active
                            ? `The server will refuse "${pending.row.name}" until it is activated again. Punches it holds stay on the machine and arrive after it is activated.`
                            : `The server will accept punches from "${pending.row.name}" again.`
                }
                confirmLabel={
                    pending?.kind === 'remove'
                        ? 'Remove'
                        : pending?.row.is_active
                          ? 'Deactivate'
                          : 'Activate'
                }
                destructive={
                    pending?.kind === 'remove' || pending?.row.is_active
                }
                processing={busy}
                onConfirm={confirm}
            />
        </>
    );
}

function ResendCard({
    device,
    commands,
    today,
    maxDays,
}: {
    device: DeviceRow;
    commands: CommandRow[];
    today: string;
    maxDays: number;
}) {
    const moment = useMoment();
    const form = useForm({ from: today, to: today });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(DeviceController.resend(device.id), {
            preserveScroll: true,
        });
    };

    return (
        <Section
            title="Re-send punches"
            description={`Asks ${device.name} to send its punches for a date range again, for example after it was offline. Punches already stored are not duplicated. At most ${maxDays} days at a time.`}
        >
            <form
                onSubmit={submit}
                noValidate
                className="grid gap-4 rounded-xl border bg-card p-4 shadow-xs sm:p-5"
            >
                <div className="grid gap-4 sm:grid-cols-[auto_auto_1fr] sm:items-start">
                    <Field id="resend-from" label="From" error={errors.from}>
                        <Input
                            {...fieldAria('resend-from', errors.from)}
                            type="date"
                            value={data.from}
                            max={data.to || today}
                            className="sm:w-44"
                            onChange={(e) =>
                                form.setData('from', e.target.value)
                            }
                        />
                    </Field>
                    <Field id="resend-to" label="To" error={errors.to}>
                        <Input
                            {...fieldAria('resend-to', errors.to)}
                            type="date"
                            value={data.to}
                            min={data.from || undefined}
                            max={today}
                            className="sm:w-44"
                            onChange={(e) => form.setData('to', e.target.value)}
                        />
                    </Field>
                    <div className="grid gap-2 sm:pt-6">
                        <Button
                            type="submit"
                            disabled={form.processing || !device.is_active}
                            className="w-full sm:w-auto sm:justify-self-start"
                        >
                            {form.processing ? <Spinner /> : <RotateCcw />}
                            Queue re-send
                        </Button>
                    </div>
                </div>
                <p className="text-xs text-muted-foreground">
                    {device.is_active
                        ? 'The request runs when the machine next checks in, usually within a minute while it is online.'
                        : 'Activate this device first: the server refuses an inactive machine, so it would never receive the request.'}
                </p>

                {commands.length > 0 && (
                    <div className="grid gap-2 border-t pt-4">
                        <h4 className="text-sm font-medium">Recent requests</h4>
                        <ul className="grid gap-2">
                            {commands.map((command) => (
                                <li
                                    key={command.id}
                                    className="flex flex-col gap-1 text-sm sm:flex-row sm:items-center sm:justify-between"
                                >
                                    <span>{command.label}</span>
                                    <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        Queued {moment(command.queued_at)}
                                        <StatusPill
                                            tone={COMMAND_TONES[command.status]}
                                        >
                                            {COMMAND_LABELS[command.status]}
                                        </StatusPill>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}
            </form>
        </Section>
    );
}

function DeviceDialog({
    device,
    onClose,
}: {
    /** The device being renamed, or null to add one. */
    device: DeviceRow | null;
    onClose: () => void;
}) {
    const form = useForm({
        name: device?.name ?? '',
        serial_no: device?.serial_no ?? '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (device) {
            form.transform((d) => ({ name: d.name }));
            form.submit(DeviceController.update(device.id), options);
        } else {
            form.submit(DeviceController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {device ? 'Rename device' : 'Add device'}
                        </DialogTitle>
                        <DialogDescription>
                            {device
                                ? 'The serial number cannot change: it is how the server recognises the machine.'
                                : "The serial number is on the machine's label and under System Info on its screen."}
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="device-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('device-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                            placeholder="Front door"
                            autoComplete="off"
                        />
                    </Field>

                    <Field
                        id="device-serial"
                        label="Serial number"
                        error={errors.serial_no}
                    >
                        <Input
                            {...fieldAria('device-serial', errors.serial_no)}
                            value={data.serial_no}
                            onChange={(e) =>
                                form.setData('serial_no', e.target.value)
                            }
                            maxLength={50}
                            disabled={device !== null}
                            className="font-mono"
                            autoComplete="off"
                        />
                    </Field>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            {device ? 'Save changes' : 'Add device'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

Devices.layout = {
    breadcrumbs: [
        { title: 'Settings', href: settingsIndex() },
        { title: 'ZKTeco Device', href: index() },
    ],
};
