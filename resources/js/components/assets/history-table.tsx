import { usePage } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { DataTable } from '@/components/hrm/data-table';
import type { DataTableColumn } from '@/components/hrm/data-table';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import { assetConditionLabel } from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';

/** One hand-over of an asset, as the asset page sends it (dates are "Y-m-d" text). */
export type AssetHistoryRow = {
    id: number;
    employee: AssetPerson;
    assigned_on: string;
    returned_on: string | null;
    condition_out: string;
    return_condition: string | null;
    note: string | null;
    return_note: string | null;
    assigned_by: string | null;
    returned_by: string | null;
};

/** Every hand-over of the asset, newest first: who had it, from when to when, the condition each way and the notes. */
export function HistoryTable({
    rows,
    onEditNotes,
}: {
    rows: AssetHistoryRow[];
    /** Given to people who may edit notes (assets.assign): adds an "Edit notes" button to each row. */
    onEditNotes?: (row: AssetHistoryRow) => void;
}) {
    const dateFormat = usePage().props.app.dateFormat;

    const columns: DataTableColumn<AssetHistoryRow>[] = [
        {
            key: 'employee',
            header: 'Employee',
            className: 'min-w-44 font-medium',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <span>{row.employee.name}</span>
                    <span className="text-xs font-normal text-muted-foreground">
                        {row.employee.code}
                    </span>
                    {/* The columns hidden below xl (a laptop with the sidebar open has about 650px), stacked under the name. */}
                    <span className="text-xs font-normal text-muted-foreground xl:hidden">
                        {period(row, dateFormat)}
                    </span>
                    <span className="text-xs font-normal text-muted-foreground xl:hidden">
                        {conditionText(row)}
                    </span>
                    {(row.note || row.return_note) && (
                        <span className="grid gap-0.5 text-xs font-normal break-words whitespace-normal text-muted-foreground xl:hidden">
                            {row.note && <span>Out: {row.note}</span>}
                            {row.return_note && (
                                <span>Back: {row.return_note}</span>
                            )}
                        </span>
                    )}
                    <span className="text-xs font-normal text-muted-foreground 2xl:hidden">
                        {recordedBy(row)}
                    </span>
                </div>
            ),
        },
        {
            key: 'period',
            header: 'Held',
            className: 'hidden whitespace-nowrap tabular-nums xl:table-cell',
            cell: (row) => period(row, dateFormat),
        },
        {
            key: 'status',
            header: 'Status',
            cell: (row) =>
                row.returned_on === null ? (
                    <StatusPill tone="blue">With them now</StatusPill>
                ) : (
                    <StatusPill tone="grey">Returned</StatusPill>
                ),
        },
        {
            key: 'condition',
            header: 'Condition',
            className: 'hidden whitespace-nowrap xl:table-cell',
            cell: (row) => conditionText(row),
        },
        {
            key: 'notes',
            header: 'Notes',
            className: 'hidden max-w-64 text-muted-foreground xl:table-cell',
            cell: (row) => (
                <div className="grid gap-1 text-sm break-words whitespace-normal">
                    {row.note && <span>Out: {row.note}</span>}
                    {row.return_note && <span>Back: {row.return_note}</span>}
                    {!row.note && !row.return_note && '–'}
                </div>
            ),
        },
        {
            key: 'by',
            header: 'Recorded by',
            className: 'hidden text-muted-foreground 2xl:table-cell',
            cell: (row) => (
                <div className="grid gap-0.5 text-sm">
                    <span>Out: {row.assigned_by ?? '–'}</span>
                    {row.returned_on !== null && (
                        <span>Back: {row.returned_by ?? '–'}</span>
                    )}
                </div>
            ),
        },
    ];

    if (onEditNotes) {
        columns.push({
            key: 'actions',
            header: <span className="sr-only">Actions</span>,
            className: 'text-right',
            cell: (row) => (
                <Button
                    variant="ghost"
                    size="sm"
                    aria-label={`Edit notes for ${row.employee.name}`}
                    onClick={() => onEditNotes(row)}
                >
                    <Pencil />
                    <span className="hidden sm:inline">Edit notes</span>
                </Button>
            ),
        });
    }

    return (
        <DataTable
            label="Asset history"
            columns={columns}
            rows={rows}
            rowKey={(row) => row.id}
            emptyText="This asset has never been handed out."
        />
    );
}

/** "Good", or "Good to Fair" once it is back. */
function conditionText(row: AssetHistoryRow): string {
    return row.return_condition
        ? `${assetConditionLabel(row.condition_out)} to ${assetConditionLabel(row.return_condition)}`
        : assetConditionLabel(row.condition_out);
}

/** "Out: Sara · Back: Ali" (who handed it over, and who took it back). */
function recordedBy(row: AssetHistoryRow): string {
    const out = `Out: ${row.assigned_by ?? '–'}`;

    return row.returned_on === null
        ? out
        : `${out} · Back: ${row.returned_by ?? '–'}`;
}

/** "20 Nov 2026 to 05 Dec 2026", or "from 20 Nov 2026" while it is still out. */
function period(row: AssetHistoryRow, format: string | undefined) {
    const from = formatDate(row.assigned_on, format);

    return row.returned_on === null
        ? `From ${from}`
        : `${from} to ${formatDate(row.returned_on, format)}`;
}
