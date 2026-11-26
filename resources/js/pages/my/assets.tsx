import { Head, usePage } from '@inertiajs/react';
import { Package } from 'lucide-react';
import { DataTable } from '@/components/hrm/data-table';
import type {
    DataTableColumn,
    DataTablePagination,
} from '@/components/hrm/data-table';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { assetConditionLabel } from '@/lib/assets';
import { formatDate } from '@/lib/format-date';
import { assets as myAssets } from '@/routes/my';

type MyAssetRow = {
    /** The assignment row. */
    id: number;
    code: string;
    name: string;
    category: string | null;
    serial_number: string | null;
    /** "Y-m-d". */
    assigned_on: string;
    returned_on: string | null;
    condition_out: string;
    return_condition: string | null;
    note: string | null;
    return_note: string | null;
};

type Props = {
    /** The viewer's own record; null when the account is not linked to an employee. */
    employee: { name: string } | null;
    holding: MyAssetRow[];
    history: DataTablePagination & { data: MyAssetRow[] };
};

function CodeBadge({ code }: { code: string }) {
    return (
        <span className="rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium whitespace-nowrap text-blue-700 dark:bg-blue-500/15 dark:text-blue-300">
            {code}
        </span>
    );
}

/** The signed-in person's own assets: what they hold now and what they have given back. */
export default function MyAssets({ employee, holding, history }: Props) {
    const { props } = usePage();
    const dateFormat = props.app.dateFormat;

    const columns: DataTableColumn<MyAssetRow>[] = [
        {
            key: 'asset',
            header: 'Asset',
            cell: (row) => (
                <div className="grid gap-0.5">
                    <div className="flex flex-wrap items-center gap-x-2">
                        <span className="font-medium">{row.name}</span>
                        <CodeBadge code={row.code} />
                    </div>
                    {row.category && (
                        <span className="text-xs text-muted-foreground">
                            {row.category}
                        </span>
                    )}
                </div>
            ),
        },
        {
            key: 'given',
            header: 'Given',
            className: 'whitespace-nowrap',
            cell: (row) => formatDate(row.assigned_on, dateFormat),
        },
        {
            key: 'returned',
            header: 'Returned',
            className: 'whitespace-nowrap',
            cell: (row) => formatDate(row.returned_on, dateFormat),
        },
        {
            key: 'condition',
            header: 'Condition',
            cell: (row) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusPill tone="grey">
                        Out: {assetConditionLabel(row.condition_out)}
                    </StatusPill>
                    {row.return_condition && (
                        <StatusPill tone="grey">
                            Back: {assetConditionLabel(row.return_condition)}
                        </StatusPill>
                    )}
                </div>
            ),
        },
        {
            key: 'notes',
            header: 'Notes',
            cell: (row) => {
                const notes = [row.note, row.return_note].filter(Boolean);

                return notes.length === 0 ? (
                    <span className="text-muted-foreground">–</span>
                ) : (
                    <div className="grid gap-0.5 text-sm">
                        {notes.map((note, index) => (
                            <span key={index}>{note}</span>
                        ))}
                    </div>
                );
            },
        },
    ];

    return (
        <>
            <Head title="My Assets" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="My Assets"
                    subtitle={
                        employee
                            ? 'What the company has given you, and what you have returned.'
                            : undefined
                    }
                />

                {employee === null ? (
                    <NotLinked />
                ) : (
                    <>
                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">
                                Holding now
                            </h2>
                            {holding.length === 0 ? (
                                <p className="rounded-xl border bg-card px-4 py-6 text-sm text-muted-foreground shadow-xs">
                                    Nothing assigned to you right now.
                                </p>
                            ) : (
                                <ul
                                    role="list"
                                    className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3"
                                >
                                    {holding.map((asset) => (
                                        <li
                                            key={asset.id}
                                            className="grid gap-2 rounded-xl border bg-card p-4 shadow-xs"
                                        >
                                            <div className="flex flex-wrap items-center gap-x-2">
                                                <span className="font-medium">
                                                    {asset.name}
                                                </span>
                                                <CodeBadge code={asset.code} />
                                            </div>
                                            <dl className="grid gap-1 text-sm">
                                                {asset.category && (
                                                    <Line
                                                        label="Category"
                                                        value={asset.category}
                                                    />
                                                )}
                                                {asset.serial_number && (
                                                    <Line
                                                        label="Serial number"
                                                        value={
                                                            asset.serial_number
                                                        }
                                                    />
                                                )}
                                                <Line
                                                    label="Given"
                                                    value={formatDate(
                                                        asset.assigned_on,
                                                        dateFormat,
                                                    )}
                                                />
                                                <Line
                                                    label="Condition"
                                                    value={assetConditionLabel(
                                                        asset.condition_out,
                                                    )}
                                                />
                                                {asset.note && (
                                                    <Line
                                                        label="Note"
                                                        value={asset.note}
                                                    />
                                                )}
                                            </dl>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="grid gap-3">
                            <h2 className="text-base font-semibold">
                                Returned
                            </h2>
                            <DataTable
                                label="Returned assets"
                                columns={columns}
                                rows={history.data}
                                rowKey={(row) => row.id}
                                pagination={history}
                                emptyText="You have not returned any assets."
                            />
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

function Line({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex gap-2">
            <dt className="w-28 shrink-0 text-muted-foreground">{label}</dt>
            <dd className="min-w-0 break-words">{value}</dd>
        </div>
    );
}

function NotLinked() {
    return (
        <section className="flex flex-col items-center rounded-xl border bg-card px-6 py-16 text-center shadow-xs">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Package aria-hidden="true" className="size-6" />
            </div>
            <p className="mt-4 text-base font-medium">
                Your account is not linked to an employee record yet.
            </p>
            <p className="mt-1 text-sm text-muted-foreground">
                Your assets appear here once HR links it.
            </p>
        </section>
    );
}

MyAssets.layout = {
    breadcrumbs: [{ title: 'My Assets', href: myAssets() }],
};
