import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { memo } from 'react';
import { Avatar } from '@/components/hrm/avatar';
import { countReports } from '@/lib/org-tree';
import type { OrgTreeNode } from '@/lib/org-tree';
import { cn } from '@/lib/utils';
import { show } from '@/routes/employees';

type Props = {
    node: OrgTreeNode;
    /** 1 for a root. Sets aria-level and whether connector lines are drawn above the card. */
    level: number;
    /** Ids of the people whose reports are showing. */
    open: ReadonlySet<number>;
    /** Ids of the people matching the search (their cards are highlighted). */
    matches: ReadonlySet<number>;
    onToggle: (id: number) => void;
};

/** "4 reports" / "1 report". */
export function reportsLabel(count: number): string {
    return `${count} ${count === 1 ? 'report' : 'reports'}`;
}

/**
 * One person on the organization chart and, when open, their reports below. A closed branch is not mounted at all,
 * so a large company costs only what is on screen. Lines between a manager and their reports are CSS borders:
 * the group draws the line down from the manager, and each report draws its half of the bar above it plus the
 * line down to its own card (the first and last report leave off the outer half of the bar).
 */
export const OrgChartNode = memo(function OrgChartNode({
    node,
    level,
    open,
    matches,
    onToggle,
}: Props) {
    const reports = countReports(node);
    const isOpen = reports > 0 && open.has(node.id);
    const matched = matches.has(node.id);

    return (
        <li
            role="treeitem"
            aria-level={level}
            aria-expanded={reports > 0 ? isOpen : undefined}
            className={cn(
                'relative flex flex-col items-center px-2',
                level > 1 &&
                    "pt-6 before:absolute before:top-0 before:right-1/2 before:w-1/2 before:border-t before:border-muted-foreground/30 before:content-[''] after:absolute after:top-0 after:left-1/2 after:h-6 after:w-1/2 after:border-t after:border-l after:border-muted-foreground/30 after:content-[''] first:before:border-t-0 last:after:border-t-0",
            )}
        >
            <div
                data-node-id={node.id}
                data-match={matched ? 'true' : undefined}
                className={cn(
                    'flex w-52 flex-col items-center gap-1.5 rounded-xl border bg-card px-3 pt-4 pb-3 text-center shadow-xs transition-colors',
                    matched &&
                        'border-primary bg-primary/5 ring-2 ring-primary/40',
                )}
            >
                <Avatar src={node.photo_url} name={node.name} size="md" />
                <Link
                    href={show(node.id)}
                    title={node.name}
                    className="max-w-full truncate rounded-sm text-sm font-semibold text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {node.name}
                </Link>
                {node.designation && (
                    <span
                        title={node.designation}
                        className="max-w-full truncate rounded-md bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700 dark:bg-blue-500/15 dark:text-blue-300"
                    >
                        {node.designation}
                    </span>
                )}
                {node.department && (
                    <span
                        title={node.department}
                        className="max-w-full truncate text-xs text-muted-foreground"
                    >
                        {node.department}
                    </span>
                )}
                {reports > 0 && (
                    <button
                        type="button"
                        onClick={() => onToggle(node.id)}
                        aria-expanded={isOpen}
                        aria-label={`${isOpen ? 'Hide' : 'Show'} ${reportsLabel(reports)} of ${node.name}`}
                        className={cn(
                            'mt-1 inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                            isOpen
                                ? 'border-primary/40 bg-primary/10 text-primary'
                                : 'text-muted-foreground',
                        )}
                    >
                        {reportsLabel(reports)}
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 transition-transform',
                                isOpen && 'rotate-180',
                            )}
                        />
                    </button>
                )}
            </div>

            {isOpen && (
                <ul
                    role="group"
                    className="relative flex pt-6 before:absolute before:top-0 before:left-1/2 before:h-6 before:border-l before:border-muted-foreground/30 before:content-['']"
                >
                    {node.children.map((child) => (
                        <OrgChartNode
                            key={child.id}
                            node={child}
                            level={level + 1}
                            open={open}
                            matches={matches}
                            onToggle={onToggle}
                        />
                    ))}
                </ul>
            )}
        </li>
    );
});
