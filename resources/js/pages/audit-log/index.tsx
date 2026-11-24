import { Head, router, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { nextTableUrl } from '@/lib/table-query';
import { cn } from '@/lib/utils';
import { index } from '@/routes/audit-log';

type Change = { key: string; old: string | null; new: string | null };

type Entry = {
    id: number;
    created_at: string | null;
    user: { id: number; name: string } | null;
    area: string;
    area_label: string;
    description: string;
    subject: { type: string; label: string | null } | null;
    changes: Change[];
    details: { label: string; value: string }[];
};

type Props = {
    entries: DataTablePagination & { data: Entry[] };
    areaOptions: { value: string; label: string }[];
    userOptions: { id: number; name: string }[];
    filters: {
        user_id: string;
        area: string;
        from: string;
        to: string;
        search: string;
        per_page: number;
    };
};

const ALL = 'all';
const SYSTEM = 'system';
/** Values longer than this are cut to a few lines in the table until "Show more" is used. */
const LONG_VALUE = 120;

/** One old/new value, as text only. Long values are clamped, with the full text a click away. */
function Value({ text }: { text: string | null }) {
    const [open, setOpen] = useState(false);

    if (text === null) {
        return <span className="text-muted-foreground italic">empty</span>;
    }

    if (text === '') {
        return <span className="text-muted-foreground italic">blank</span>;
    }

    const long = text.length > LONG_VALUE;

    return (
        <div>
            <span
                className={cn(
                    'block break-words whitespace-pre-wrap',
                    long && !open && 'line-clamp-3',
                )}
            >
                {text}
            </span>
            {long && (
                <button
                    type="button"
                    onClick={() => setOpen(!open)}
                    aria-expanded={open}
                    className="mt-1 text-xs font-medium text-brand-ink underline-offset-2 hover:underline"
                >
                    {open ? 'Show less' : 'Show full value'}
                </button>
            )}
        </div>
    );
}

function Details({ entry }: { entry: Entry }) {
    return (
        <div
            id={`audit-detail-${entry.id}`}
            className="mt-3 space-y-3 rounded-lg border bg-muted/30 p-3 text-sm"
        >
            {entry.changes.length > 0 && (
                <div className="overflow-x-auto">
                    <table className="w-full table-fixed text-left">
                        <caption className="sr-only">
                            Old and new values
                        </caption>
                        <thead className="text-xs text-muted-foreground">
                            <tr>
                                <th className="w-[32%] pr-3 pb-1 font-medium">
                                    Field
                                </th>
                                <th className="w-[34%] pr-3 pb-1 font-medium">
                                    Old value
                                </th>
                                <th className="w-[34%] pb-1 font-medium">
                                    New value
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {entry.changes.map((change) => (
                                <tr
                                    key={change.key}
                                    className="border-t align-top"
                                >
                                    <th
                                        scope="row"
                                        className="py-1.5 pr-3 font-medium break-words"
                                    >
                                        {change.key}
                                    </th>
                                    <td className="py-1.5 pr-3">
                                        <Value text={change.old} />
                                    </td>
                                    <td className="py-1.5">
                                        <Value text={change.new} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {entry.details.length > 0 && (
                <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-4 gap-y-1 text-xs text-muted-foreground">
                    {entry.details.map((detail) => (
                        <div key={detail.label} className="contents">
                            <dt className="font-medium">{detail.label}</dt>
                            <dd className="break-words">{detail.value}</dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

export default function AuditLog({
    entries,
    areaOptions,
    userOptions,
    filters,
}: Props) {
    const { url, props } = usePage();
    const timezone = props.app.timezone;
    const [expanded, setExpanded] = useState<Set<number>>(new Set());

    const when = (iso: string | null) =>
        iso
            ? new Intl.DateTimeFormat('en-GB', {
                  day: 'numeric',
                  month: 'short',
                  year: 'numeric',
                  hour: '2-digit',
                  minute: '2-digit',
                  second: '2-digit',
                  timeZone: timezone,
              }).format(new Date(iso))
            : '';

    const toggle = (id: number) => {
        setExpanded((current) => {
            const next = new Set(current);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    };

    const filter = (changes: Record<string, string | null>) => {
        router.get(
            nextTableUrl(url, { ...changes, page: null }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const filtering =
        filters.search !== '' ||
        filters.user_id !== '' ||
        filters.area !== '' ||
        filters.from !== '' ||
        filters.to !== '';

    const columns: DataTableColumn<Entry>[] = [
        {
            key: 'time',
            header: 'Time',
            className:
                'hidden w-44 whitespace-nowrap text-muted-foreground md:table-cell',
            cell: (entry) => when(entry.created_at),
        },
        {
            key: 'user',
            header: 'User',
            className: 'hidden w-40 md:table-cell',
            cell: (entry) =>
                entry.user ? (
                    <span className="font-medium">{entry.user.name}</span>
                ) : (
                    <span className="text-muted-foreground">System</span>
                ),
        },
        {
            key: 'area',
            header: 'Area',
            className: 'hidden w-32 md:table-cell',
            cell: (entry) => (
                <Badge variant="secondary">{entry.area_label}</Badge>
            ),
        },
        {
            key: 'description',
            header: 'What happened',
            cell: (entry) => {
                const hasDetails =
                    entry.changes.length > 0 || entry.details.length > 0;
                const open = expanded.has(entry.id);

                return (
                    <div className="min-w-0">
                        <div className="flex items-start gap-2">
                            {hasDetails ? (
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="-mt-1 size-7 shrink-0"
                                    aria-expanded={open}
                                    aria-controls={`audit-detail-${entry.id}`}
                                    aria-label={`${open ? 'Hide' : 'Show'} details: ${entry.description}`}
                                    onClick={() => toggle(entry.id)}
                                >
                                    <ChevronRight
                                        className={cn(
                                            'transition-transform',
                                            open && 'rotate-90',
                                        )}
                                    />
                                </Button>
                            ) : (
                                <span className="size-7 shrink-0" />
                            )}
                            <div className="min-w-0 flex-1">
                                <div className="break-words">
                                    {entry.description}
                                </div>
                                {entry.subject?.label && (
                                    <div className="text-xs break-words text-muted-foreground">
                                        {entry.subject.type}:{' '}
                                        {entry.subject.label}
                                    </div>
                                )}
                                {/* Phones: time, user and area sit under the description. */}
                                <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground md:hidden">
                                    <span>{when(entry.created_at)}</span>
                                    <span aria-hidden="true">&middot;</span>
                                    <span>
                                        {entry.user
                                            ? entry.user.name
                                            : 'System'}
                                    </span>
                                    <Badge variant="secondary">
                                        {entry.area_label}
                                    </Badge>
                                </div>
                                {open && hasDetails && (
                                    <Details entry={entry} />
                                )}
                            </div>
                        </div>
                    </div>
                );
            },
        },
    ];

    return (
        <>
            <Head title="Audit Log" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Audit Log"
                    subtitle="A read-only record of who changed what, and when. Entries cannot be edited or deleted."
                />

                <DataTable
                    label="Audit log"
                    columns={columns}
                    rows={entries.data}
                    rowKey={(entry) => entry.id}
                    pagination={entries}
                    pageParam="page"
                    search={{
                        value: filters.search,
                        placeholder: 'Search what happened',
                        label: 'Search the audit log',
                    }}
                    toolbar={
                        <>
                            <Select
                                value={
                                    filters.user_id === ''
                                        ? ALL
                                        : filters.user_id
                                }
                                onValueChange={(value) =>
                                    filter({
                                        user_id: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-40"
                                    aria-label="Filter by user"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All users
                                    </SelectItem>
                                    <SelectItem value={SYSTEM}>
                                        System
                                    </SelectItem>
                                    {userOptions.map((user) => (
                                        <SelectItem
                                            key={user.id}
                                            value={String(user.id)}
                                        >
                                            {user.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.area === '' ? ALL : filters.area}
                                onValueChange={(value) =>
                                    filter({
                                        area: value === ALL ? null : value,
                                    })
                                }
                            >
                                <SelectTrigger
                                    className="w-full sm:w-36"
                                    aria-label="Filter by area"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={ALL}>
                                        All areas
                                    </SelectItem>
                                    {areaOptions.map((area) => (
                                        <SelectItem
                                            key={area.value}
                                            value={area.value}
                                        >
                                            {area.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Input
                                type="date"
                                value={filters.from}
                                max={filters.to || undefined}
                                onChange={(e) =>
                                    filter({ from: e.target.value || null })
                                }
                                aria-label="From date"
                                className="w-[calc(50%-0.25rem)] sm:w-36"
                            />
                            <Input
                                type="date"
                                value={filters.to}
                                min={filters.from || undefined}
                                onChange={(e) =>
                                    filter({ to: e.target.value || null })
                                }
                                aria-label="To date"
                                className="w-[calc(50%-0.25rem)] sm:w-36"
                            />
                            {filtering && (
                                <Button
                                    variant="ghost"
                                    onClick={() =>
                                        filter({
                                            user_id: null,
                                            area: null,
                                            from: null,
                                            to: null,
                                            search: null,
                                        })
                                    }
                                >
                                    Clear filters
                                </Button>
                            )}
                        </>
                    }
                    emptyText={
                        filtering
                            ? 'No entries match your filters.'
                            : 'Nothing has been recorded yet.'
                    }
                />
            </div>
        </>
    );
}

AuditLog.layout = {
    breadcrumbs: [
        {
            title: 'Audit Log',
            href: index(),
        },
    ],
};
