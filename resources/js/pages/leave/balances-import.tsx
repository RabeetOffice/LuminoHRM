import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Info,
    Upload,
} from 'lucide-react';
import { useId, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { importMethod, index } from '@/routes/leave/balances';
import {
    preview as previewRoute,
    run,
    template,
} from '@/routes/leave/balances/import';

type PreviewRow = {
    line: number;
    code: string;
    name: string;
    type: string;
    year: string;
    days: string;
    errors: string[];
    warnings: string[];
    /** The stored figure already equals this row: nothing changes. */
    unchanged: boolean;
};

type Preview = {
    token: string;
    notices: string[];
    totals: {
        rows: number;
        ready: number;
        errors: number;
        warnings: number;
        unchanged: number;
    };
    rows: PreviewRow[];
};

type Props = {
    preview: Preview | null;
    /** A token was given but its upload is gone (expired, used, someone else's). */
    expired: boolean;
    limits: { rows: number; megabytes: number };
};

const plural = (count: number, word: string) =>
    `${count.toLocaleString()} ${word}${count === 1 ? '' : 's'}`;

function Step({
    number,
    title,
    children,
}: {
    number: number;
    title: string;
    children: ReactNode;
}) {
    return (
        <li className="flex gap-4">
            <span
                aria-hidden="true"
                className="flex size-7 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary"
            >
                {number}
            </span>
            <div className="min-w-0 flex-1 space-y-2">
                <h2 className="font-medium">{title}</h2>
                {children}
            </div>
        </li>
    );
}

function UploadStep({ limits, expired }: Pick<Props, 'limits' | 'expired'>) {
    const inputId = useId();
    const errorId = useId();
    const form = useForm<{ file: File | null }>({ file: null });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(previewRoute.url(), { forceFormData: true });
    };

    return (
        <div className="space-y-4">
            {expired && (
                <Alert variant="destructive">
                    <AlertCircle />
                    <AlertTitle>This preview is no longer available</AlertTitle>
                    <AlertDescription>
                        It may have expired (after 60 minutes), been imported
                        already or belong to someone else. Upload the file
                        again.
                    </AlertDescription>
                </Alert>
            )}

            <ol className="space-y-6 rounded-xl border bg-card p-4 shadow-xs sm:p-6">
                <Step number={1} title="Download the template">
                    <p className="text-sm text-muted-foreground">
                        Four columns: Employee Code, Leave Type, Year and Days
                        Used. The Help sheet lists the leave types and years
                        accepted.
                    </p>
                    <Button asChild variant="outline">
                        <a href={template.url()} download>
                            <Download />
                            Download template
                        </a>
                    </Button>
                </Step>

                <Step number={2} title="Fill in the days already used">
                    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>
                            One row per person, paid leave type and year: the
                            days they used before this app, in whole or half
                            days.
                        </li>
                        <li>
                            The figure replaces what is stored (it is not
                            added), so importing the same file again changes
                            nothing.
                        </li>
                        <li>
                            Up to {limits.rows.toLocaleString()} rows and{' '}
                            {limits.megabytes} MB, .xlsx only.
                        </li>
                    </ul>
                </Step>

                <Step number={3} title="Upload the file">
                    <form
                        onSubmit={submit}
                        className="flex flex-col gap-3 sm:flex-row sm:items-center"
                    >
                        <Label htmlFor={inputId} className="sr-only">
                            Excel file
                        </Label>
                        <input
                            id={inputId}
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            aria-invalid={form.errors.file ? true : undefined}
                            aria-describedby={
                                form.errors.file ? errorId : undefined
                            }
                            onChange={(event) =>
                                form.setData(
                                    'file',
                                    event.target.files?.[0] ?? null,
                                )
                            }
                            className="block w-full min-w-0 rounded-md border border-input bg-background text-sm file:mr-3 file:border-0 file:border-r file:bg-muted file:px-3 file:py-2 file:text-sm file:font-medium focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:max-w-md"
                        />
                        <Button
                            type="submit"
                            disabled={
                                form.data.file === null || form.processing
                            }
                        >
                            {form.processing ? <Spinner /> : <Upload />}
                            Upload and preview
                        </Button>
                    </form>
                    {form.errors.file && (
                        <Alert variant="destructive" id={errorId}>
                            <AlertCircle />
                            <AlertTitle>The file was not accepted</AlertTitle>
                            <AlertDescription>
                                {form.errors.file}
                            </AlertDescription>
                        </Alert>
                    )}
                </Step>
            </ol>
        </div>
    );
}

function Total({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'green' | 'red' | 'amber';
}) {
    const colour =
        tone === 'green'
            ? 'text-emerald-600 dark:text-emerald-400'
            : tone === 'red'
              ? 'text-red-600 dark:text-red-400'
              : tone === 'amber'
                ? 'text-amber-600 dark:text-amber-400'
                : '';

    return (
        <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className={`text-2xl font-semibold tabular-nums ${colour}`}>
                {value.toLocaleString()}
            </dd>
        </div>
    );
}

function Result({ row }: { row: PreviewRow }) {
    if (row.errors.length > 0) {
        return (
            <div className="space-y-1">
                <StatusPill tone="red">Error</StatusPill>
                <ul className="list-disc space-y-0.5 pl-4 text-red-700 dark:text-red-300">
                    {row.errors.map((error) => (
                        <li key={error}>{error}</li>
                    ))}
                </ul>
            </div>
        );
    }

    return (
        <div className="space-y-1">
            {row.unchanged ? (
                <StatusPill tone="grey">No change</StatusPill>
            ) : (
                <StatusPill tone="green">Ready</StatusPill>
            )}
            {row.warnings.length > 0 && (
                <ul className="list-disc space-y-0.5 pl-4 text-amber-700 dark:text-amber-300">
                    {row.warnings.map((warning) => (
                        <li key={warning}>{warning}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function PreviewStep({ preview }: { preview: Preview }) {
    const [errorsOnly, setErrorsOnly] = useState(false);
    const [running, setRunning] = useState(false);
    const errorsOnlyId = useId();
    const { totals } = preview;
    const blocked = totals.errors > 0;

    const rows = useMemo(
        () =>
            errorsOnly
                ? preview.rows.filter(
                      (row) => row.errors.length > 0 || row.warnings.length > 0,
                  )
                : preview.rows,
        [errorsOnly, preview.rows],
    );

    const startImport = () =>
        router.post(
            run.url(),
            { token: preview.token },
            {
                onStart: () => setRunning(true),
                onFinish: () => setRunning(false),
            },
        );

    return (
        <div className="space-y-4">
            <dl className="grid grid-cols-2 gap-3 sm:max-w-2xl sm:grid-cols-4">
                <Total label="Rows" value={totals.rows} />
                <Total label="Ready" value={totals.ready} tone="green" />
                <Total
                    label="Warnings"
                    value={totals.warnings}
                    tone={totals.warnings > 0 ? 'amber' : undefined}
                />
                <Total
                    label="Errors"
                    value={totals.errors}
                    tone={blocked ? 'red' : undefined}
                />
            </dl>

            {blocked ? (
                <Alert variant="destructive">
                    <AlertCircle />
                    <AlertTitle>
                        {plural(totals.errors, 'row')}{' '}
                        {totals.errors === 1 ? 'needs' : 'need'} fixing
                    </AlertTitle>
                    <AlertDescription>
                        Nothing is saved until every row is ready. Correct the
                        rows marked below in your file and upload it again.
                    </AlertDescription>
                </Alert>
            ) : (
                <Alert>
                    <CheckCircle2 />
                    <AlertTitle>Every row is ready</AlertTitle>
                    <AlertDescription>
                        All {plural(totals.rows, 'row')} will be saved together
                        {totals.unchanged > 0
                            ? ` (${totals.unchanged.toLocaleString()} already match what is stored).`
                            : '.'}
                    </AlertDescription>
                </Alert>
            )}

            {totals.warnings > 0 && (
                <Alert>
                    <AlertTriangle />
                    <AlertTitle>
                        {plural(totals.warnings, 'row')} will leave someone
                        overdrawn
                    </AlertTitle>
                    <AlertDescription>
                        These rows are saved as they are and shown in red on
                        Balances, so you can adjust them afterwards.
                    </AlertDescription>
                </Alert>
            )}

            {preview.notices.length > 0 && (
                <Alert>
                    <Info />
                    <AlertTitle>Notes about this file</AlertTitle>
                    <AlertDescription>
                        <ul className="list-disc pl-5">
                            {preview.notices.map((notice) => (
                                <li key={notice}>{notice}</li>
                            ))}
                        </ul>
                    </AlertDescription>
                </Alert>
            )}

            <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                <div className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:justify-between sm:p-4">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={errorsOnlyId}
                            checked={errorsOnly}
                            onCheckedChange={(value) =>
                                setErrorsOnly(value === true)
                            }
                            disabled={totals.errors + totals.warnings === 0}
                        />
                        <Label htmlFor={errorsOnlyId}>
                            Show errors and warnings only
                        </Label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button asChild variant="outline">
                            <Link href={importMethod()}>
                                <FileSpreadsheet />
                                Upload a different file
                            </Link>
                        </Button>
                        <span
                            title={
                                blocked
                                    ? 'Fix every row with an error first'
                                    : undefined
                            }
                        >
                            <Button
                                onClick={startImport}
                                disabled={blocked || running}
                            >
                                {running ? <Spinner /> : <Upload />}
                                Import {plural(totals.rows, 'row')}
                            </Button>
                        </span>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[44rem] text-sm">
                        <caption className="sr-only">
                            Rows of the uploaded file and whether each is ready
                        </caption>
                        <thead className="bg-muted/50 text-left text-xs font-medium text-muted-foreground uppercase">
                            <tr>
                                <th scope="col" className="w-14 px-3 py-2">
                                    Row
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Employee
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Leave type
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Year
                                </th>
                                <th
                                    scope="col"
                                    className="px-3 py-2 text-right"
                                >
                                    Days used
                                </th>
                                <th scope="col" className="min-w-64 px-3 py-2">
                                    Result
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {rows.map((row) => (
                                <tr
                                    key={row.line}
                                    className={
                                        row.errors.length > 0
                                            ? 'bg-red-50/50 align-top dark:bg-red-500/5'
                                            : 'align-top'
                                    }
                                >
                                    <td className="px-3 py-2 text-muted-foreground tabular-nums">
                                        {row.line}
                                    </td>
                                    <td className="px-3 py-2">
                                        <p className="font-medium whitespace-nowrap">
                                            {row.name || row.code || '—'}
                                        </p>
                                        {row.name && (
                                            <p className="text-xs text-muted-foreground">
                                                {row.code}
                                            </p>
                                        )}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.type || '—'}
                                    </td>
                                    <td className="px-3 py-2 tabular-nums">
                                        {row.year || '—'}
                                    </td>
                                    <td className="px-3 py-2 text-right tabular-nums">
                                        {row.days || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <Result row={row} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}

export default function BalancesImport({ preview, expired, limits }: Props) {
    return (
        <>
            <Head title="Import opening balances" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Import opening balances"
                    subtitle={
                        preview
                            ? 'Check every row. Nothing is saved until you import.'
                            : 'The leave each person used this year before the app, from an Excel file.'
                    }
                    actions={
                        <Button asChild variant="ghost">
                            <Link href={index()}>
                                <ArrowLeft />
                                Back to balances
                            </Link>
                        </Button>
                    }
                />

                {preview ? (
                    <PreviewStep preview={preview} />
                ) : (
                    <UploadStep limits={limits} expired={expired} />
                )}
            </div>
        </>
    );
}

BalancesImport.layout = {
    breadcrumbs: [
        { title: 'Leave Balances', href: index() },
        { title: 'Import', href: importMethod() },
    ],
};
