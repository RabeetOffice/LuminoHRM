import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertCircle,
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
import { importMethod, index } from '@/routes/employees';
import {
    preview as previewRoute,
    run,
    template,
} from '@/routes/employees/import';

type PreviewRow = {
    line: number;
    name: string;
    email: string;
    department: string;
    designation: string;
    manager: string;
    status: string;
    errors: string[];
};

type Preview = {
    token: string;
    notices: string[];
    totals: { rows: number; ready: number; errors: number };
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
                        The first sheet has the columns in the right order and
                        one grey example row (it is skipped). The Help sheet
                        lists every column, the allowed values and the
                        departments and designations that exist today.
                    </p>
                    <Button asChild variant="outline">
                        <a href={template.url()} download>
                            <Download />
                            Download template
                        </a>
                    </Button>
                </Step>

                <Step number={2} title="Fill in one row per person">
                    <ul className="list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>
                            Columns marked * are required. Departments and
                            designations must already exist under Organization.
                        </li>
                        <li>
                            Manager Email can be an existing employee or another
                            row in the same file.
                        </li>
                        <li>
                            Up to {limits.rows.toLocaleString()} rows and{' '}
                            {limits.megabytes} MB, .xlsx only.
                        </li>
                        <li>
                            No email is sent. Send set-password links from the
                            Employees list afterwards.
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
    tone?: 'green' | 'red';
}) {
    return (
        <div className="rounded-xl border bg-card px-4 py-3 shadow-xs">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd
                className={
                    tone === 'green'
                        ? 'text-2xl font-semibold text-emerald-600 tabular-nums dark:text-emerald-400'
                        : tone === 'red'
                          ? 'text-2xl font-semibold text-red-600 tabular-nums dark:text-red-400'
                          : 'text-2xl font-semibold tabular-nums'
                }
            >
                {value.toLocaleString()}
            </dd>
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
                ? preview.rows.filter((row) => row.errors.length > 0)
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
            <dl className="grid grid-cols-3 gap-3 sm:max-w-xl">
                <Total label="Rows" value={totals.rows} />
                <Total label="Ready" value={totals.ready} tone="green" />
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
                        All {plural(totals.rows, 'employee')} will be saved
                        together. No email is sent; send set-password links from
                        the list afterwards.
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
                            disabled={!blocked}
                        />
                        <Label htmlFor={errorsOnlyId}>Show errors only</Label>
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
                                Import {plural(totals.rows, 'employee')}
                            </Button>
                        </span>
                    </div>
                </div>

                <div className="overflow-x-auto">
                    <table className="w-full min-w-[56rem] text-sm">
                        <caption className="sr-only">
                            Rows of the uploaded file and whether each is ready
                        </caption>
                        <thead className="bg-muted/50 text-left text-xs font-medium text-muted-foreground uppercase">
                            <tr>
                                <th scope="col" className="w-14 px-3 py-2">
                                    Row
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Name
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Email
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Department
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Designation
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Manager
                                </th>
                                <th scope="col" className="px-3 py-2">
                                    Status
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
                                    <td className="px-3 py-2 font-medium whitespace-nowrap">
                                        {row.name || '—'}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {row.email || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.department || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.designation || '—'}
                                    </td>
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {row.manager || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.status || '—'}
                                    </td>
                                    <td className="px-3 py-2">
                                        {row.errors.length === 0 ? (
                                            <StatusPill tone="green">
                                                Ready
                                            </StatusPill>
                                        ) : (
                                            <div className="space-y-1">
                                                <StatusPill tone="red">
                                                    Error
                                                </StatusPill>
                                                <ul className="list-disc space-y-0.5 pl-4 text-red-700 dark:text-red-300">
                                                    {row.errors.map((error) => (
                                                        <li key={error}>
                                                            {error}
                                                        </li>
                                                    ))}
                                                </ul>
                                            </div>
                                        )}
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

export default function EmployeesImport({ preview, expired, limits }: Props) {
    return (
        <>
            <Head title="Import employees" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Import employees"
                    subtitle={
                        preview
                            ? 'Check every row. Nothing is saved until you import.'
                            : 'Add many employees at once from an Excel file.'
                    }
                    actions={
                        <Button asChild variant="ghost">
                            <Link href={index()}>
                                <ArrowLeft />
                                Back to employees
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

EmployeesImport.layout = {
    breadcrumbs: [
        { title: 'Employees', href: index() },
        { title: 'Import', href: importMethod() },
    ],
};
