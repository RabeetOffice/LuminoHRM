import { Head } from '@inertiajs/react';
import {
    ChevronsDownUp,
    ChevronsUpDown,
    Network,
    Search,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { OrgChartNode } from '@/components/hrm/org-chart-node';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    buildTree,
    defaultExpanded,
    findPath,
    flattenTree,
    matchNodes,
} from '@/lib/org-tree';
import type { OrgNode } from '@/lib/org-tree';
import { index } from '@/routes/organization-chart';

type Props = {
    /** Everyone the viewer may see (active and probation only), manager_id already pointing at a shown person or null. */
    nodes: OrgNode[];
};

const EMPTY = new Set<number>();

export default function OrganizationChart({ nodes }: Props) {
    const roots = useMemo(() => buildTree(nodes), [nodes]);
    const everyone = useMemo(() => flattenTree(roots), [roots]);
    const initial = useMemo(() => defaultExpanded(roots), [roots]);
    const withReports = useMemo(
        () =>
            new Set(
                everyone.filter((n) => n.children.length > 0).map((n) => n.id),
            ),
        [everyone],
    );

    const [open, setOpen] = useState<Set<number>>(initial);
    const [query, setQuery] = useState('');
    const canvas = useRef<HTMLDivElement>(null);

    // In reading order, so the first match is the one nearest the top.
    const matchIds = useMemo(
        () => matchNodes(everyone, query),
        [everyone, query],
    );
    const matches = useMemo(
        () => (matchIds.length > 0 ? new Set(matchIds) : EMPTY),
        [matchIds],
    );
    const searching = query.trim() !== '';

    // Searching opens the branches leading to every match; clearing the search goes back to the default view.
    const search = (value: string) => {
        setQuery(value);

        if (value.trim() === '') {
            setOpen(initial);
            centreAfterRender();

            return;
        }

        const next = new Set(initial);

        for (const id of matchNodes(everyone, value)) {
            for (const ancestor of findPath(roots, id).slice(0, -1)) {
                next.add(ancestor);
            }
        }

        setOpen(next);
    };

    // A chart wider than the card shows the top of the tree in the middle, not its left edge: on load and after
    // Expand all, Collapse all or clearing the search.
    const centre = useCallback(() => {
        const el = canvas.current;

        if (el && el.scrollWidth > el.clientWidth) {
            el.scrollLeft = (el.scrollWidth - el.clientWidth) / 2;
        }
    }, []);
    const centreAfterRender = () => requestAnimationFrame(centre);

    useLayoutEffect(centre, [centre]);

    const first = matchIds[0];

    useEffect(() => {
        if (first === undefined) {
            return;
        }

        // After the branches above it have opened.
        const frame = requestAnimationFrame(() => {
            canvas.current
                ?.querySelector(`[data-node-id="${first}"]`)
                ?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center',
                    inline: 'center',
                });
        });

        return () => cancelAnimationFrame(frame);
    }, [first]);

    const toggle = useCallback((id: number) => {
        setOpen((previous) => {
            const next = new Set(previous);

            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }

            return next;
        });
    }, []);

    return (
        <>
            <Head title="Organization Chart" />
            <div className="flex min-w-0 flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title="Organization Chart"
                    subtitle="Who reports to whom."
                />

                <div className="flex min-w-0 flex-col overflow-hidden rounded-xl border bg-card shadow-xs">
                    <div className="flex flex-col gap-3 border-b p-3 sm:flex-row sm:items-center sm:p-4">
                        <div className="relative w-full sm:max-w-xs">
                            <Search
                                aria-hidden="true"
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                type="search"
                                value={query}
                                onChange={(e) => search(e.target.value)}
                                placeholder="Find a person"
                                aria-label="Find a person"
                                disabled={nodes.length === 0}
                                className="px-9 [&::-webkit-search-cancel-button]:hidden"
                            />
                            {query !== '' && (
                                <button
                                    type="button"
                                    onClick={() => search('')}
                                    aria-label="Clear search"
                                    className="absolute top-1/2 right-2 flex size-6 -translate-y-1/2 items-center justify-center rounded-sm text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-4" />
                                </button>
                            )}
                        </div>
                        <p
                            aria-live="polite"
                            className="text-sm text-muted-foreground"
                        >
                            {searching &&
                                (matchIds.length === 0
                                    ? 'No one matches.'
                                    : `${matchIds.length} ${matchIds.length === 1 ? 'match' : 'matches'}`)}
                        </p>
                        <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={withReports.size === 0}
                                onClick={() => {
                                    setOpen(new Set(withReports));
                                    centreAfterRender();
                                }}
                            >
                                <ChevronsUpDown />
                                Expand all
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                disabled={withReports.size === 0}
                                onClick={() => {
                                    setOpen(new Set());
                                    centreAfterRender();
                                }}
                            >
                                <ChevronsDownUp />
                                Collapse all
                            </Button>
                        </div>
                    </div>

                    {nodes.length === 0 ? (
                        <div className="px-4 py-16 text-center">
                            <Network
                                aria-hidden="true"
                                className="mx-auto mb-2 size-8 text-muted-foreground/60"
                            />
                            <p className="text-sm text-muted-foreground">
                                No employees yet.
                            </p>
                        </div>
                    ) : (
                        // The chart keeps its shape at every width; the card scrolls both ways instead.
                        <div
                            ref={canvas}
                            className="max-h-[calc(100svh-15rem)] min-h-80 overflow-auto"
                        >
                            <div className="flex w-max min-w-full justify-center p-6">
                                <ul
                                    role="tree"
                                    aria-label="Organization chart"
                                    className="flex items-start gap-6"
                                >
                                    {roots.map((root) => (
                                        <OrgChartNode
                                            key={root.id}
                                            node={root}
                                            level={1}
                                            open={open}
                                            matches={matches}
                                            onToggle={toggle}
                                        />
                                    ))}
                                </ul>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

OrganizationChart.layout = {
    breadcrumbs: [
        {
            title: 'Organization Chart',
            href: index(),
        },
    ],
};
