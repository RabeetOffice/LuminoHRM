import { Head, router, usePage } from '@inertiajs/react';
import { CalendarDays, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import HolidayController from '@/actions/App/Http/Controllers/HolidayController';
import { HolidayDialog } from '@/components/holidays/holiday-dialog';
import type { HolidayRow } from '@/components/holidays/holiday-dialog';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useCan } from '@/hooks/use-can';
import { formatDate } from '@/lib/format-date';
import { nextTableUrl } from '@/lib/table-query';
import { index } from '@/routes/holidays';

type Props = {
    /** The chosen year's holidays, by date. */
    holidays: HolidayRow[];
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
    const [deleting, setDeleting] = useState<HolidayRow | null>(null);
    const [busy, setBusy] = useState(false);

    const chooseYear = (value: string) => {
        router.get(
            nextTableUrl(page.url, { year: value }),
            {},
            { preserveScroll: true, preserveState: true, replace: true },
        );
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
                setDeleting(null);
            },
        });
    };

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

                <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-3 border-b p-4 sm:flex-row sm:items-center sm:justify-between">
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
                                    <SelectItem key={year} value={String(year)}>
                                        {year}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {holidays.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 px-4 py-14 text-center">
                            <CalendarDays
                                aria-hidden="true"
                                className="size-8 text-muted-foreground"
                            />
                            <p className="text-sm text-muted-foreground">
                                No holidays in {filters.year} yet.
                            </p>
                            {canEdit && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => setEditing('new')}
                                >
                                    <Plus />
                                    Add holiday
                                </Button>
                            )}
                        </div>
                    ) : (
                        <Table aria-label={`Holidays in ${filters.year}`}>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Date</TableHead>
                                    <TableHead className="hidden sm:table-cell">
                                        Day
                                    </TableHead>
                                    <TableHead>Name</TableHead>
                                    <TableHead className="w-24">
                                        <span className="sr-only">Actions</span>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {holidays.map((holiday) => (
                                    <TableRow key={holiday.id}>
                                        <TableCell className="whitespace-nowrap tabular-nums">
                                            {formatDate(
                                                holiday.date,
                                                dateFormat,
                                            )}
                                        </TableCell>
                                        <TableCell className="hidden text-muted-foreground sm:table-cell">
                                            {holiday.weekday}
                                        </TableCell>
                                        <TableCell className="font-medium">
                                            {holiday.name}
                                        </TableCell>
                                        <TableCell>
                                            {canEdit && (
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8"
                                                        aria-label={`Edit ${holiday.name}`}
                                                        title="Edit"
                                                        onClick={() =>
                                                            setEditing(holiday)
                                                        }
                                                    >
                                                        <Pencil />
                                                    </Button>
                                                    <Button
                                                        variant="ghost"
                                                        size="icon"
                                                        className="size-8 text-destructive hover:text-destructive"
                                                        aria-label={`Delete ${holiday.name}`}
                                                        title="Delete"
                                                        onClick={() =>
                                                            setDeleting(holiday)
                                                        }
                                                    >
                                                        <Trash2 />
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </div>
            </div>

            {editing !== null && (
                <HolidayDialog
                    key={editing === 'new' ? 'new' : editing.id}
                    holiday={editing === 'new' ? null : editing}
                    onClose={() => setEditing(null)}
                />
            )}

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title="Delete holiday?"
                description={
                    deleting
                        ? `"${deleting.name}" will be removed from the calendar. Attendance for that day is recalculated.`
                        : ''
                }
                processing={busy}
                onConfirm={confirmDelete}
            />
        </>
    );
}

Holidays.layout = {
    breadcrumbs: [{ title: 'Holidays', href: index() }],
};
