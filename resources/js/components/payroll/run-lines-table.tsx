import { AlertTriangle, Inbox, Search, X } from 'lucide-react';
import { useState } from 'react';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    filterLines,
    formatMoney,
    lineDeductions,
    needsAttention,
} from '@/lib/payroll';
import type { RunLine } from '@/lib/payroll';
import { cn } from '@/lib/utils';

type Props = {
    lines: RunLine[];
    onOpen: (line: RunLine) => void;
};

function LineFlags({ line }: { line: RunLine }) {
    return (
        <span className="flex flex-wrap items-center gap-1.5">
            {line.excluded && <StatusPill tone="grey">Excluded</StatusPill>}
            {line.blocking.length > 0 && !line.excluded && (
                <StatusPill tone="red">Blocks approval</StatusPill>
            )}
            {line.warnings > 0 && !line.excluded && (
                <span className="inline-flex items-center gap-1 text-xs text-amber-700 dark:text-amber-300">
                    <AlertTriangle aria-hidden="true" className="size-3.5" />
                    {line.warnings}{' '}
                    {line.warnings === 1 ? 'warning' : 'warnings'}
                </span>
            )}
            {line.tax_overridden && !line.excluded && (
                <span className="text-xs text-muted-foreground">
                    Tax overridden
                </span>
            )}
        </span>
    );
}

/**
 * The run's lines: searchable by name or code, with a "Needs attention" filter.
 * A table from the md breakpoint up, cards below it. Selecting a person opens
 * the line drawer.
 */
export function RunLinesTable({ lines, onOpen }: Props) {
    const [search, setSearch] = useState('');
    const [attention, setAttention] = useState(false);
    const shown = filterLines(lines, search, attention);
    const attentionCount = lines.filter(needsAttention).length;

    return (
        <section
            aria-labelledby="run-lines-title"
            className="overflow-hidden rounded-xl border bg-card shadow-xs"
        >
            <div className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:p-4">
                <h2 id="run-lines-title" className="font-medium sm:mr-2">
                    People
                </h2>
                <div className="relative w-full sm:max-w-xs">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        type="search"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search by name or code"
                        aria-label="Search people"
                        className="px-9 [&::-webkit-search-cancel-button]:hidden"
                    />
                    {search !== '' && (
                        <button
                            type="button"
                            onClick={() => setSearch('')}
                            aria-label="Clear search"
                            className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
                <Button
                    type="button"
                    variant={attention ? 'default' : 'outline'}
                    aria-pressed={attention}
                    onClick={() => setAttention((a) => !a)}
                    className="sm:ml-auto"
                >
                    <AlertTriangle />
                    Needs attention ({attentionCount})
                </Button>
            </div>

            {shown.length === 0 ? (
                <div className="px-4 py-14 text-center">
                    <Inbox
                        aria-hidden="true"
                        className="mx-auto mb-2 size-8 text-muted-foreground/60"
                    />
                    <p className="text-sm text-muted-foreground">
                        {lines.length === 0
                            ? 'Nobody is in this payroll yet.'
                            : attention && search.trim() === ''
                              ? 'Nobody needs attention.'
                              : 'No one matches this search.'}
                    </p>
                </div>
            ) : (
                <>
                    <div className="hidden md:block">
                        <Table aria-label="Payroll lines">
                            <TableHeader className="bg-muted/50">
                                <TableRow className="hover:bg-transparent">
                                    <TableHead>Employee</TableHead>
                                    <TableHead className="text-right">
                                        Days paid
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Gross
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Attendance
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Other deductions
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Loan
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Tax
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Net
                                    </TableHead>
                                    <TableHead>Flags</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {shown.map((line) => (
                                    <TableRow
                                        key={line.id}
                                        className={cn(
                                            line.excluded &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        <TableCell>
                                            <button
                                                type="button"
                                                onClick={() => onOpen(line)}
                                                className="block rounded-sm text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            >
                                                <span className="block font-medium whitespace-nowrap hover:underline">
                                                    {line.employee.name}
                                                </span>
                                                <span className="block text-xs text-muted-foreground">
                                                    {line.employee.code}
                                                    {line.employee.department
                                                        ? ` · ${line.employee.department}`
                                                        : ''}
                                                </span>
                                            </button>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {line.excluded
                                                ? '—'
                                                : line.days_paid}
                                        </TableCell>
                                        <Money value={line.gross} line={line} />
                                        <Money
                                            value={line.attendance_deduction}
                                            line={line}
                                        />
                                        <Money
                                            value={line.other_deductions}
                                            line={line}
                                        />
                                        <Money
                                            value={line.loan_deduction}
                                            line={line}
                                        />
                                        <Money value={line.tax} line={line} />
                                        <Money
                                            value={line.net}
                                            line={line}
                                            strong
                                        />
                                        <TableCell>
                                            <LineFlags line={line} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>

                    <ul className="divide-y md:hidden">
                        {shown.map((line) => (
                            <li key={line.id}>
                                <button
                                    type="button"
                                    onClick={() => onOpen(line)}
                                    className={cn(
                                        'grid w-full gap-2 px-4 py-3 text-left outline-none focus-visible:bg-muted/50',
                                        line.excluded &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <span className="flex items-start justify-between gap-3">
                                        <span className="min-w-0">
                                            <span className="block font-medium">
                                                {line.employee.name}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {line.employee.code}
                                            </span>
                                        </span>
                                        <span className="text-right">
                                            <span className="block text-xs text-muted-foreground">
                                                Net
                                            </span>
                                            <span className="block font-semibold tabular-nums">
                                                {line.excluded
                                                    ? '—'
                                                    : formatMoney(line.net)}
                                            </span>
                                        </span>
                                    </span>
                                    {!line.excluded && (
                                        <span className="grid grid-cols-3 gap-2 text-xs text-muted-foreground tabular-nums">
                                            <span>
                                                Gross
                                                <span className="block text-sm text-foreground">
                                                    {formatMoney(line.gross)}
                                                </span>
                                            </span>
                                            <span>
                                                Deductions
                                                <span className="block text-sm text-foreground">
                                                    {formatMoney(
                                                        lineDeductions(line),
                                                    )}
                                                </span>
                                            </span>
                                            <span>
                                                Tax
                                                <span className="block text-sm text-foreground">
                                                    {formatMoney(line.tax)}
                                                </span>
                                            </span>
                                        </span>
                                    )}
                                    <LineFlags line={line} />
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </section>
    );
}

function Money({
    value,
    line,
    strong = false,
}: {
    value: number;
    line: RunLine;
    strong?: boolean;
}) {
    return (
        <TableCell
            className={cn(
                'text-right whitespace-nowrap tabular-nums',
                strong && 'font-medium',
            )}
        >
            {line.excluded ? '—' : formatMoney(value)}
        </TableCell>
    );
}
