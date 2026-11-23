import { router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Inbox, Search, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import { PER_PAGE_OPTIONS, nextTableUrl, pageItems } from '@/lib/table-query';
import type { QueryChanges } from '@/lib/table-query';
import { cn } from '@/lib/utils';

export type DataTableColumn<T> = {
    /** Stable key for React. */
    key: string;
    /** Column heading. Use a visually hidden label for icon-only columns. */
    header: ReactNode;
    /** Cell content for a row. */
    cell: (row: T) => ReactNode;
    /** Extra classes for the cells (and the heading unless headerClassName is set). */
    className?: string;
    headerClassName?: string;
};

/** The shape the server sends for each paginated list. */
export type DataTablePagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

type SearchConfig = {
    /** The value the server applied (from the page props). */
    value: string;
    /** Query-string parameter name. Default "search". */
    param?: string;
    placeholder?: string;
    /** Accessible label for the input. Default "Search". */
    label?: string;
};

type Props<T> = {
    columns: DataTableColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    pagination: DataTablePagination;
    /** Query-string parameter holding this table's page number, e.g. "page" or "dept_page". */
    pageParam: string;
    /** Query-string parameter for rows per page. Default "per_page". */
    perPageParam?: string;
    /** Adds a debounced (300 ms) search box. Omit for tables without search. */
    search?: SearchConfig;
    /** Rendered next to the search box: filter dropdowns, view toggles. */
    toolbar?: ReactNode;
    /** Rendered above the toolbar inside the card, e.g. <StatusTabs>. */
    header?: ReactNode;
    /** Shown when there are no rows. */
    emptyText: string;
    /** Accessible name for the table. */
    label?: string;
    className?: string;
};

const SEARCH_DEBOUNCE_MS = 300;

/**
 * Server-driven table in a bordered card: it renders the rows it is given and
 * turns every change (page, rows per page, search) into an Inertia visit to the
 * current URL with that one parameter changed, keeping scroll position, page
 * state and all other query parameters. It knows nothing about the data.
 * On narrow screens the table scrolls sideways inside the card.
 */
export function DataTable<T>({
    columns,
    rows,
    rowKey,
    pagination,
    pageParam,
    perPageParam = 'per_page',
    search,
    toolbar,
    header,
    emptyText,
    label,
    className,
}: Props<T>) {
    const { url } = usePage();
    const [busy, setBusy] = useState(false);

    const visit = (changes: QueryChanges, replace = false) => {
        router.get(
            nextTableUrl(url, changes),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                replace,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    };

    const searchParam = search?.param ?? 'search';
    const serverSearch = search?.value ?? '';
    const [term, setTerm] = useState(serverSearch);
    const lastSent = useRef(serverSearch);

    // Follow changes that did not come from typing here (back button, other links).
    useEffect(() => {
        if (serverSearch !== lastSent.current) {
            lastSent.current = serverSearch;
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setTerm(serverSearch);
        }
    }, [serverSearch]);

    const visitRef = useRef(visit);

    visitRef.current = visit;

    useEffect(() => {
        const wanted = term.trim();

        if (wanted === lastSent.current) {
            return;
        }

        const timer = setTimeout(() => {
            lastSent.current = wanted;
            visitRef.current(
                { [searchParam]: wanted, [pageParam]: null },
                true,
            );
        }, SEARCH_DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [term, searchParam, pageParam]);

    const { current_page: current, last_page: last, total } = pagination;
    const hasToolbar = search !== undefined || toolbar !== undefined;

    return (
        <div
            className={cn(
                'overflow-hidden rounded-xl border bg-card shadow-xs',
                className,
            )}
        >
            {header}

            {hasToolbar && (
                <div className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:p-4">
                    {search && (
                        <div className="relative w-full sm:max-w-xs">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                type="search"
                                value={term}
                                onChange={(e) => setTerm(e.target.value)}
                                placeholder={search.placeholder ?? 'Search'}
                                aria-label={search.label ?? 'Search'}
                                className="px-9 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {term !== '' && (
                                <button
                                    type="button"
                                    onClick={() => setTerm('')}
                                    aria-label="Clear search"
                                    className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                    )}
                    {toolbar && (
                        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                            {toolbar}
                        </div>
                    )}
                </div>
            )}

            <div
                aria-busy={busy}
                className={cn('transition-opacity', busy && 'opacity-60')}
            >
                <Table aria-label={label}>
                    <TableHeader className="bg-muted/50">
                        <TableRow className="hover:bg-transparent">
                            {columns.map((column) => (
                                <TableHead
                                    key={column.key}
                                    className={
                                        column.headerClassName ??
                                        column.className
                                    }
                                >
                                    {column.header}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.length === 0 ? (
                            <TableRow className="hover:bg-transparent">
                                <TableCell
                                    colSpan={columns.length}
                                    className="py-14 text-center"
                                >
                                    <Inbox
                                        aria-hidden="true"
                                        className="mx-auto mb-2 size-8 text-muted-foreground/60"
                                    />
                                    <p className="text-sm text-muted-foreground">
                                        {emptyText}
                                    </p>
                                </TableCell>
                            </TableRow>
                        ) : (
                            rows.map((row) => (
                                <TableRow key={rowKey(row)}>
                                    {columns.map((column) => (
                                        <TableCell
                                            key={column.key}
                                            className={column.className}
                                        >
                                            {column.cell(row)}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </div>

            {total > 0 && (
                <div className="flex flex-col gap-3 border-t p-3 text-sm sm:flex-row sm:items-center sm:justify-between sm:p-4">
                    <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-muted-foreground">
                        <span>
                            Showing {pagination.from ?? 0} to{' '}
                            {pagination.to ?? 0} of {total}
                        </span>
                        <label className="flex items-center gap-2">
                            <span>Rows</span>
                            <Select
                                value={String(pagination.per_page)}
                                onValueChange={(value) =>
                                    visit({
                                        [perPageParam]: value,
                                        [pageParam]: null,
                                    })
                                }
                            >
                                <SelectTrigger
                                    size="sm"
                                    className="w-[72px]"
                                    aria-label="Rows per page"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {PER_PAGE_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option}
                                            value={String(option)}
                                        >
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </label>
                    </div>

                    {last > 1 && (
                        <nav
                            aria-label="Pagination"
                            className="flex items-center gap-1"
                        >
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                aria-label="Previous page"
                                disabled={current <= 1}
                                onClick={() =>
                                    visit({
                                        [pageParam]:
                                            current - 1 <= 1
                                                ? null
                                                : current - 1,
                                    })
                                }
                            >
                                <ChevronLeft />
                            </Button>
                            {pageItems(current, last).map((item, index) =>
                                item === 'gap' ? (
                                    <span
                                        key={`gap-${index}`}
                                        aria-hidden="true"
                                        className="px-1 text-muted-foreground"
                                    >
                                        &hellip;
                                    </span>
                                ) : (
                                    <Button
                                        key={item}
                                        variant={
                                            item === current
                                                ? 'default'
                                                : 'outline'
                                        }
                                        size="icon"
                                        className="size-8 tabular-nums"
                                        aria-label={`Page ${item}`}
                                        aria-current={
                                            item === current
                                                ? 'page'
                                                : undefined
                                        }
                                        onClick={() =>
                                            visit({
                                                [pageParam]:
                                                    item === 1 ? null : item,
                                            })
                                        }
                                    >
                                        {item}
                                    </Button>
                                ),
                            )}
                            <Button
                                variant="outline"
                                size="icon"
                                className="size-8"
                                aria-label="Next page"
                                disabled={current >= last}
                                onClick={() =>
                                    visit({ [pageParam]: current + 1 })
                                }
                            >
                                <ChevronRight />
                            </Button>
                        </nav>
                    )}
                </div>
            )}
        </div>
    );
}
