import { Head, Link } from '@inertiajs/react';
import { Plus, Wallet } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/hrm/page-header';
import { StatusPill } from '@/components/hrm/status-pill';
import { GenerateRunDialog } from '@/components/payroll/generate-run-dialog';
import type { GenerateOptions } from '@/components/payroll/generate-run-dialog';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatMoney, runStatusPill } from '@/lib/payroll';
import { index, show } from '@/routes/payroll/runs';

type Run = {
    id: number;
    month: string;
    label: string;
    status: string;
    headcount: number;
    /** Paisa. */
    gross: number;
    net: number;
    generated_by: string | null;
    generated_on: string | null;
    approved_by: string | null;
    approved_on: string | null;
};

type Props = {
    runs: Run[];
    /** Null for people who may not generate payroll. */
    generate: GenerateOptions | null;
};

function Trail({ run }: { run: Run }) {
    return (
        <>
            {run.generated_on && (
                <span className="block">
                    Generated {run.generated_on}
                    {run.generated_by ? ` by ${run.generated_by}` : ''}
                </span>
            )}
            {run.approved_on && (
                <span className="block">
                    Approved {run.approved_on}
                    {run.approved_by ? ` by ${run.approved_by}` : ''}
                </span>
            )}
        </>
    );
}

export default function RunsIndex({ runs, generate }: Props) {
    const [generating, setGenerating] = useState(false);

    return (
        <>
            <Head title="Payroll Runs" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Payroll Runs"
                    subtitle="One payroll per month: calculate it, review the checks, approve it, then mark it paid."
                    actions={
                        generate ? (
                            <Button onClick={() => setGenerating(true)}>
                                <Plus />
                                Generate payroll
                            </Button>
                        ) : undefined
                    }
                />

                {runs.length === 0 ? (
                    <div className="rounded-xl border bg-card px-4 py-14 text-center shadow-xs">
                        <Wallet
                            aria-hidden="true"
                            className="mx-auto mb-2 size-8 text-muted-foreground/60"
                        />
                        <p className="text-sm text-muted-foreground">
                            No payroll has been generated yet.
                        </p>
                        {generate && (
                            <div className="mt-3 flex justify-center">
                                <Button
                                    variant="outline"
                                    onClick={() => setGenerating(true)}
                                >
                                    <Plus />
                                    Generate payroll
                                </Button>
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card shadow-xs">
                        <div className="hidden md:block">
                            <Table aria-label="Payroll runs">
                                <TableHeader className="bg-muted/50">
                                    <TableRow className="hover:bg-transparent">
                                        <TableHead>Month</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            People
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Gross
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Net
                                        </TableHead>
                                        <TableHead>History</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {runs.map((run) => {
                                        const pill = runStatusPill(run.status);

                                        return (
                                            <TableRow key={run.id}>
                                                <TableCell>
                                                    <Link
                                                        href={show(run.id)}
                                                        className="rounded-sm font-medium outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                                    >
                                                        {run.label}
                                                    </Link>
                                                </TableCell>
                                                <TableCell>
                                                    <StatusPill
                                                        tone={pill.tone}
                                                    >
                                                        {pill.label}
                                                    </StatusPill>
                                                </TableCell>
                                                <TableCell className="text-right tabular-nums">
                                                    {run.headcount}
                                                </TableCell>
                                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                    {formatMoney(run.gross)}
                                                </TableCell>
                                                <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                                    {formatMoney(run.net)}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    <Trail run={run} />
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </div>

                        <ul className="divide-y md:hidden">
                            {runs.map((run) => {
                                const pill = runStatusPill(run.status);

                                return (
                                    <li key={run.id}>
                                        <Link
                                            href={show(run.id)}
                                            className="grid gap-2 px-4 py-3 outline-none focus-visible:bg-muted/50"
                                        >
                                            <span className="flex items-center justify-between gap-3">
                                                <span className="font-medium">
                                                    {run.label}
                                                </span>
                                                <StatusPill tone={pill.tone}>
                                                    {pill.label}
                                                </StatusPill>
                                            </span>
                                            <span className="grid grid-cols-3 gap-2 text-xs text-muted-foreground tabular-nums">
                                                <span>
                                                    People
                                                    <span className="block text-sm text-foreground">
                                                        {run.headcount}
                                                    </span>
                                                </span>
                                                <span>
                                                    Gross
                                                    <span className="block text-sm text-foreground">
                                                        {formatMoney(run.gross)}
                                                    </span>
                                                </span>
                                                <span>
                                                    Net
                                                    <span className="block text-sm text-foreground">
                                                        {formatMoney(run.net)}
                                                    </span>
                                                </span>
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                <Trail run={run} />
                                            </span>
                                        </Link>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>
                )}
            </div>

            {generating && generate && (
                <GenerateRunDialog
                    options={generate}
                    onClose={() => setGenerating(false)}
                />
            )}
        </>
    );
}

RunsIndex.layout = {
    breadcrumbs: [{ title: 'Payroll Runs', href: index() }],
};
