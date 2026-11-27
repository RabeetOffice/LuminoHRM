import { Head, useForm, usePage } from '@inertiajs/react';
import {
    CircleAlert,
    CircleArrowUp,
    CircleCheck,
    History,
    Sparkles,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import UpdatesController from '@/actions/App/Http/Controllers/System/UpdatesController';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import type { StatusTone } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import { index } from '@/routes/system/updates';

type ChangeType = 'new' | 'improved' | 'fixed' | 'removed';

type Release = {
    version: string;
    /** Already formatted, e.g. "27 November 2026". */
    date: string;
    title: string;
    changes: { type: ChangeType; text: string }[];
    state: 'waiting' | 'installed' | null;
};

type HistoryEntry = {
    id: number;
    version: string | null;
    status: 'pending' | 'running' | 'success' | 'failed';
    statusLabel: string;
    at: string | null;
    by: string | null;
    applied: number;
    log: string;
};

type Props = {
    available: boolean;
    codeVersion: string;
    installedVersion: string | null;
    lastUpdate: { finishedAt: string | null; by: string | null } | null;
    pending: string[];
    failure: { id: number | null; backupFailed: boolean; log: string } | null;
    releases: Release[];
    types: Record<ChangeType, string>;
    history: HistoryEntry[];
};

const changeTones: Record<ChangeType, StatusTone> = {
    new: 'green',
    improved: 'blue',
    fixed: 'amber',
    removed: 'grey',
};

const statusTones: Record<HistoryEntry['status'], StatusTone> = {
    pending: 'grey',
    running: 'blue',
    success: 'green',
    failed: 'red',
};

const changes = (n: number) =>
    n === 1 ? '1 database change' : `${n} database changes`;

function LogBlock({ log }: { log: string }) {
    return (
        <pre className="mt-3 max-h-80 overflow-auto rounded-lg border bg-muted/40 p-3 font-mono text-xs leading-relaxed break-words whitespace-pre-wrap">
            {log === '' ? 'No details were recorded.' : log}
        </pre>
    );
}

function UpdateDialog({
    mode,
    version,
    pendingCount,
    onClose,
}: {
    mode: 'update' | 'without-backup';
    version: string;
    pendingCount: number;
    onClose: () => void;
}) {
    const withoutBackup = mode === 'without-backup';
    const needsPassword = withoutBackup || pendingCount > 0;
    const form = useForm({ password: '', without_backup: withoutBackup });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.submit(UpdatesController.run(), {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: () => form.reset('password'),
        });
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
                            {withoutBackup
                                ? 'Update without a backup?'
                                : `Update to version ${version}?`}
                        </DialogTitle>
                        <DialogDescription>
                            {withoutBackup
                                ? 'The database changes are applied without a fresh backup. Do this only if you have another backup, for example from the hosting panel.'
                                : pendingCount > 0
                                  ? `The database is backed up first, then ${changes(pendingCount)} ${pendingCount === 1 ? 'is' : 'are'} applied. It takes a few seconds; keep this page open.`
                                  : 'This version needs no database changes. Roles and caches are refreshed and the version is recorded.'}
                        </DialogDescription>
                    </DialogHeader>

                    {needsPassword && (
                        <div className="grid gap-2">
                            <Label htmlFor="update-password">
                                Your password
                            </Label>
                            <PasswordInput
                                id="update-password"
                                name="password"
                                autoComplete="current-password"
                                autoFocus
                                value={form.data.password}
                                onChange={(event) =>
                                    form.setData('password', event.target.value)
                                }
                                aria-invalid={
                                    form.errors.password ? true : undefined
                                }
                            />
                            <InputError message={form.errors.password} />
                        </div>
                    )}

                    {form.processing && (
                        <p
                            className="flex items-center gap-2 text-sm text-muted-foreground"
                            aria-live="polite"
                        >
                            <Spinner />
                            Updating the system. Keep this page open.
                        </p>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={withoutBackup ? 'destructive' : 'default'}
                            disabled={
                                form.processing ||
                                (needsPassword && form.data.password === '')
                            }
                        >
                            {form.processing && <Spinner />}
                            {withoutBackup
                                ? 'Update without a backup'
                                : 'Update system'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function Updates({
    available,
    codeVersion,
    installedVersion,
    lastUpdate,
    pending,
    failure,
    releases,
    types,
    history,
}: Props) {
    const timezone = usePage().props.app.timezone;
    const [dialog, setDialog] = useState<'update' | 'without-backup' | null>(
        null,
    );
    const [openLog, setOpenLog] = useState<number | 'failure' | null>(null);

    const when = (iso: string | null) =>
        iso
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  timeZone: timezone,
              }).format(new Date(iso))
            : '';

    const toggleLog = (key: number | 'failure') =>
        setOpenLog((current) => (current === key ? null : key));

    return (
        <>
            <Head title="Updates" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Updates"
                    subtitle="What changed in Lumino HRM, and the button that applies a new version. Only Admins see this screen."
                />

                {available ? (
                    <Card className="gap-4 border-primary/40">
                        <CardContent className="flex flex-col gap-4 sm:flex-row sm:items-start">
                            <span className="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/15 text-brand-ink">
                                <CircleArrowUp className="size-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <h2 className="text-lg font-semibold">
                                    Version {codeVersion} is ready
                                </h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {installedVersion !== null &&
                                    installedVersion !== codeVersion
                                        ? `This server is on version ${installedVersion}. `
                                        : installedVersion === null
                                          ? 'The new code is on the server and waits for you to apply it. '
                                          : `Version ${codeVersion} brought more database changes. `}
                                    {pending.length > 0
                                        ? `${changes(pending.length)} will be applied after a backup.`
                                        : 'No database changes are needed.'}
                                </p>
                                {pending.length > 0 && (
                                    <details className="mt-3 text-sm">
                                        <summary className="cursor-pointer font-medium text-brand-ink">
                                            Show the database changes (
                                            {pending.length})
                                        </summary>
                                        <ul className="mt-2 space-y-1 font-mono text-xs break-all text-muted-foreground">
                                            {pending.map((name) => (
                                                <li key={name}>{name}</li>
                                            ))}
                                        </ul>
                                    </details>
                                )}
                            </div>
                            <Button
                                className="w-full sm:w-auto"
                                onClick={() => setDialog('update')}
                            >
                                <CircleArrowUp />
                                Update system
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div
                        role="status"
                        className="flex items-start gap-3 rounded-xl border border-emerald-600/20 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-400/20 dark:bg-emerald-500/10 dark:text-emerald-200"
                    >
                        <CircleCheck className="mt-0.5 size-4 shrink-0" />
                        <span>
                            <strong className="font-semibold">
                                Lumino HRM is up to date.
                            </strong>{' '}
                            Version {codeVersion}
                            {lastUpdate?.finishedAt
                                ? `, updated ${when(lastUpdate.finishedAt)}${lastUpdate.by ? ` by ${lastUpdate.by}` : ''}`
                                : ''}
                            . When new code arrives, an "Update system" button
                            appears here.
                        </span>
                    </div>
                )}

                {failure && (
                    <div
                        role="alert"
                        className="rounded-xl border border-red-600/20 bg-red-50 p-4 text-sm text-red-900 dark:border-red-400/20 dark:bg-red-500/10 dark:text-red-200"
                    >
                        <div className="flex items-start gap-3">
                            <CircleAlert className="mt-0.5 size-4 shrink-0" />
                            <div className="min-w-0 flex-1">
                                <strong className="font-semibold">
                                    The last update did not finish.
                                </strong>{' '}
                                {failure.backupFailed
                                    ? 'The database backup could not be made, so nothing was changed.'
                                    : 'The reason is in the details below.'}
                                <div className="mt-3 flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        aria-expanded={openLog === 'failure'}
                                        onClick={() => toggleLog('failure')}
                                    >
                                        {openLog === 'failure'
                                            ? 'Hide the details'
                                            : 'Show the details'}
                                    </Button>
                                    {failure.backupFailed && available && (
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                setDialog('without-backup')
                                            }
                                        >
                                            Update without a backup
                                        </Button>
                                    )}
                                </div>
                                {openLog === 'failure' && (
                                    <LogBlock log={failure.log} />
                                )}
                            </div>
                        </div>
                    </div>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Sparkles className="size-4 text-brand-ink" />
                                What's new
                            </CardTitle>
                            <CardDescription>
                                Every version, newest first.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {releases.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No versions listed yet.
                                </p>
                            ) : (
                                <ol className="space-y-6">
                                    {releases.map((release) => (
                                        <li
                                            key={release.version}
                                            className={cn(
                                                'border-l-2 pl-4',
                                                release.state === 'waiting'
                                                    ? 'border-primary'
                                                    : 'border-border',
                                            )}
                                        >
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-semibold">
                                                    {release.title}
                                                </h3>
                                                <span className="rounded-md border px-1.5 py-px font-mono text-xs text-muted-foreground tabular-nums">
                                                    {release.version}
                                                </span>
                                                {release.state ===
                                                    'waiting' && (
                                                    <StatusPill tone="amber">
                                                        Waiting to be applied
                                                    </StatusPill>
                                                )}
                                                {release.state ===
                                                    'installed' && (
                                                    <StatusPill tone="green">
                                                        Installed
                                                    </StatusPill>
                                                )}
                                            </div>
                                            <p className="mt-0.5 text-xs text-muted-foreground">
                                                {release.date}
                                            </p>
                                            <ul className="mt-3 space-y-2">
                                                {release.changes.map(
                                                    (change, i) => (
                                                        <li
                                                            key={i}
                                                            className="flex flex-col gap-1 text-sm sm:flex-row sm:items-start sm:gap-3"
                                                        >
                                                            <StatusPill
                                                                tone={
                                                                    changeTones[
                                                                        change
                                                                            .type
                                                                    ]
                                                                }
                                                                className="w-fit shrink-0"
                                                            >
                                                                {
                                                                    types[
                                                                        change
                                                                            .type
                                                                    ]
                                                                }
                                                            </StatusPill>
                                                            <span className="min-w-0">
                                                                {change.text}
                                                            </span>
                                                        </li>
                                                    ),
                                                )}
                                            </ul>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="self-start">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <History className="size-4 text-brand-ink" />
                                Update history
                            </CardTitle>
                            <CardDescription>
                                When the system was updated, and by whom.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {history.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No update recorded yet. The first time you
                                    click Update system it appears here.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {history.map((entry) => (
                                        <li
                                            key={entry.id}
                                            className="py-3 first:pt-0 last:pb-0"
                                        >
                                            <div className="flex items-start gap-3">
                                                <div className="min-w-0 flex-1">
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="text-sm font-medium">
                                                            {entry.version
                                                                ? `Version ${entry.version}`
                                                                : 'System update'}
                                                        </span>
                                                        <StatusPill
                                                            tone={
                                                                statusTones[
                                                                    entry.status
                                                                ]
                                                            }
                                                        >
                                                            {entry.statusLabel}
                                                        </StatusPill>
                                                    </div>
                                                    <p className="mt-0.5 text-xs text-muted-foreground">
                                                        {when(entry.at)}
                                                        {entry.by
                                                            ? ` · ${entry.by}`
                                                            : ''}
                                                        {entry.status ===
                                                        'success'
                                                            ? ` · ${entry.applied > 0 ? changes(entry.applied) : 'no database changes'}`
                                                            : ''}
                                                    </p>
                                                </div>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    aria-expanded={
                                                        openLog === entry.id
                                                    }
                                                    onClick={() =>
                                                        toggleLog(entry.id)
                                                    }
                                                >
                                                    {openLog === entry.id
                                                        ? 'Hide'
                                                        : 'Details'}
                                                </Button>
                                            </div>
                                            {openLog === entry.id && (
                                                <LogBlock log={entry.log} />
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>

            {dialog !== null && (
                <UpdateDialog
                    mode={dialog}
                    version={codeVersion}
                    pendingCount={pending.length}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

Updates.layout = {
    breadcrumbs: [
        {
            title: 'Updates',
            href: index(),
        },
    ],
};
