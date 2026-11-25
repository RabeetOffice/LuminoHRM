import { Head, router, usePage } from '@inertiajs/react';
import { Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import HolidayController from '@/actions/App/Http/Controllers/HolidayController';
import { HolidayDialog } from '@/components/holidays/holiday-dialog';
import type { HolidayRow } from '@/components/holidays/holiday-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useCan } from '@/hooks/use-can';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { index } from '@/routes/holidays';

/** A list row: the holiday plus the approved requests whose range covers its date (people this user may see). */
type HolidayListRow = HolidayRow & {
    affected_leave: { request_id: number; employee: string }[];
};

type Props = {
    /** The chosen year's holidays, by date. */
    holidays: HolidayListRow[];
    /** Years with holidays, plus last, this and next year, ascending. */
    years: number[];
    filters: { year: number };
};

export default function Holidays({ holidays, years, filters }: Props) {
    const page = usePage();
    const dateFormat = page.props.app.dateFormat;
    const can = useCan();
    const canEdit = can('settings.attendance-leave');

    const [editing, setEditing] = useState<HolidayRow | 'new' | null>(null);
    // The last row asked about stays set while the dialog animates closed, so its text does not change mid-close.
    const [deleting, setDeleting] = useState<HolidayListRow | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [busy, setBusy] = useState(false);

    const chooseYear = (value: string) => {
        router.get(
            nextTableUrl(page.url, { year: value }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
    };

    const askDelete = (holiday: HolidayListRow) => {
        setDeleting(holiday);
        setConfirmOpen(true);
    };

    const confirmDelete = () => {
        if (!deleting) {
            return;
        }

        router.delete(HolidayController.destroy(deleting.id).url, {
            preserveScroll: true,
            onStart: () => setBusy(true),
            onFinish: () => {
                setBusy(false);
                setConfirmOpen(false);
            },
        });
    };

    const columns: DataTableColumn<HolidayListRow>[] = [
        {
            key: 'date',
            header: 'Date',
            className: 'whitespace-nowrap tabular-nums',
            cell: (holiday) => (
                <div className="grid gap-1">
                    {formatDate(holiday.date, dateFormat)}
                    {/* The Day column is hidden on phones. */}
                    <span className="text-xs text-muted-foreground sm:hidden">
                        {holiday.weekday}
                    </span>
                </div>
            ),
        },
        {
            key: 'day',
            header: 'Day',
            className: 'hidden text-muted-foreground sm:table-cell',
            cell: (holiday) => holiday.weekday,
        },
        {
            key: 'name',
            header: 'Name',
            className: 'font-medium',
            cell: (holiday) => holiday.name,
        },
        {
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'w-24',
            cell: (holiday) =>
                canEdit && (
                    <div className="flex justify-end gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8"
                            aria-label={`Edit ${holiday.name}`}
                            title="Edit"
                            onClick={() => setEditing(holiday)}
                        >
                            <Pencil />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="size-8 text-destructive hover:text-destructive"
                            aria-label={`Delete ${holiday.name}`}
                            title="Delete"
                            onClick={() => askDelete(holiday)}
                        >
                            <Trash2 />
                        </Button>
                    </div>
                ),
        },
    ];

    return (
        <>
            <Head title="Holidays" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Holidays"
                    subtitle="Company holidays for the year. Attendance on these days is marked Holiday, and time worked counts as overtime."
                    actions={
                        canEdit && (
                            <Button onClick={() => setEditing('new')}>
                                <Plus />
                                Add holiday
                            </Button>
                        )
                    }
                />

                <DataTable
                    label={`Holidays in ${filters.year}`}
                    columns={columns}
                    rows={holidays}
                    rowKey={(holiday) => holiday.id}
                    toolbar={
                        <>
                            <p className="text-sm text-muted-foreground">
                                {holidays.length === 1
                                    ? '1 holiday'
                                    : `${holidays.length} holidays`}{' '}
                                in {filters.year}
                            </p>
                            <Select
                                value={String(filters.year)}
                                onValueChange={chooseYear}
                            >
                                <SelectTrigger
                                    className="w-full sm:w-36"
                                    aria-label="Year"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {years.map((year) => (
                                        <SelectItem
                                            key={year}
                                            value={String(year)}
                                        >
                                            {year}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </>
                    }
                    emptyText={`No holidays in ${filters.year} yet.`}
                    emptyAction={
                        canEdit && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setEditing('new')}
                            >
                                <Plus />
                                Add holiday
                            </Button>
                        )
                    }
                />
            </div>

            {editing !== null && (
                <HolidayDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    holiday={editing === 'new' ? null : editing}
                    year={filters.year}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title="Delete holiday?"
                description={
                    deleting ? <DeleteDescription holiday={deleting} /> : ''
                }
                processing={busy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

/**
 * The delete confirmation text. Removing a holiday does not add leave days back (they were never counted), so the
 * people with approved leave around it are named for HR to follow up. Rendered inside the dialog's description
 * paragraph, so only phrasing elements (spans) are used.
 */
function DeleteDescription({ holiday }: { holiday: HolidayListRow }) {
    const people = holiday.affected_leave;

    return (
        <>
            <span className="block">
                &ldquo;{holiday.name}&rdquo; will be removed from the calendar.
                Attendance for that day is recalculated.
            </span>
            {people.length > 0 && (
                <>
                    <span className="mt-3 block">
                        Removing this holiday does not add leave days back.
                        These people had approved leave around it:
                    </span>
                    <span role="list" className="mt-2 block space-y-1">
                        {people.map((person) => (
                            <span
                                key={person.request_id}
                                role="listitem"
                                className="block font-medium text-foreground"
                            >
                                {person.employee}
                            </span>
                        ))}
                    </span>
                    <span className="mt-3 block">
                        Ask them to apply for that day if needed.
                    </span>
                </>
            )}
        </>
    );
}

Holidays.layout = {
    breadcrumbs: [{ title: 'Holidays', href: index() }],
};
