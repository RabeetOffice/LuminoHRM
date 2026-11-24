/**
 * The organization chart as a tree, built from the flat list of people the server sends. Pure functions with no
 * recursion (a chain hundreds of levels deep must not overflow the stack), tolerant of bad data: a manager who is
 * not in the list makes the person a root, and a reporting loop is broken at its lowest id.
 */

/** One person as the server sends them (OrganizationChartController). */
export type OrgNode = {
    id: number;
    name: string;
    code: string;
    designation: string | null;
    department: string | null;
    photo_url: string | null;
    manager_id: number | null;
};

export type OrgTreeNode = OrgNode & { children: OrgTreeNode[] };

/** How many levels of the chart are open when the page loads. */
export const DEFAULT_OPEN_LEVELS = 2;

const collator = new Intl.Collator('en', {
    sensitivity: 'base',
    numeric: true,
});

function bySiblingOrder(a: OrgTreeNode, b: OrgTreeNode): number {
    return collator.compare(a.name, b.name) || a.id - b.id;
}

/**
 * Roots (people with no manager in the list) with their reports nested in `children`, siblings ordered by name then
 * id. A manager_id that points at nobody in the list, or at the person themself, makes a root. Where manager_ids form
 * a loop, the member of the loop with the lowest id becomes a root (the same whatever the input order). The input is
 * not changed.
 */
export function buildTree(nodes: OrgNode[]): OrgTreeNode[] {
    const byId = new Map<number, OrgTreeNode>();

    for (const n of nodes) {
        if (!byId.has(n.id)) {
            byId.set(n.id, { ...n, children: [] });
        }
    }

    // parent[id] = the manager's id when that manager is in the list and is not the person themself.
    const parent = new Map<number, number | null>();

    for (const n of byId.values()) {
        const m = n.manager_id;
        parent.set(n.id, m !== null && m !== n.id && byId.has(m) ? m : null);
    }

    // Each person has at most one manager, so following managers from anyone either reaches a root or enters a loop.
    // 0 = not seen, 1 = on the walk being followed now, 2 = finished.
    const state = new Map<number, 0 | 1 | 2>();
    const ids = [...byId.keys()].sort((a, b) => a - b);

    for (const start of ids) {
        if ((state.get(start) ?? 0) !== 0) {
            continue;
        }

        const walk: number[] = [];
        let current: number | null = start;

        while (current !== null && (state.get(current) ?? 0) === 0) {
            state.set(current, 1);
            walk.push(current);
            current = parent.get(current) ?? null;
        }

        if (current !== null && state.get(current) === 1) {
            // A loop: from `current` to the end of this walk. Its lowest id becomes a root.
            const loop = walk.slice(walk.indexOf(current));
            parent.set(Math.min(...loop), null);
        }

        for (const id of walk) {
            state.set(id, 2);
        }
    }

    const roots: OrgTreeNode[] = [];

    for (const n of byId.values()) {
        const p = parent.get(n.id) ?? null;

        if (p === null) {
            roots.push(n);
        } else {
            byId.get(p)!.children.push(n);
        }
    }

    for (const n of byId.values()) {
        n.children.sort(bySiblingOrder);
    }

    return roots.sort(bySiblingOrder);
}

/** Every person in the tree, top to bottom in the order the chart reads (a parent before its children). */
export function flattenTree(roots: OrgTreeNode[]): OrgTreeNode[] {
    const out: OrgTreeNode[] = [];
    const stack = [...roots].reverse();

    while (stack.length > 0) {
        const n = stack.pop()!;
        out.push(n);

        for (let i = n.children.length - 1; i >= 0; i--) {
            stack.push(n.children[i]);
        }
    }

    return out;
}

/** The number of direct reports (what the "N reports" button shows). */
export function countReports(node: OrgTreeNode): number {
    return node.children.length;
}

/** The ids from a root down to the person with this id (both included), or [] when they are not in the tree. */
export function findPath(roots: OrgTreeNode[], id: number): number[] {
    const up = new Map<number, number | null>();
    const stack: Array<[OrgTreeNode, number | null]> = roots.map((r) => [
        r,
        null,
    ]);
    let found = false;

    while (stack.length > 0) {
        const [n, parentId] = stack.pop()!;
        up.set(n.id, parentId);

        if (n.id === id) {
            found = true;
            break;
        }

        for (const c of n.children) {
            stack.push([c, n.id]);
        }
    }

    if (!found) {
        return [];
    }

    const path: number[] = [];
    let current: number | null = id;

    while (current !== null) {
        path.push(current);
        current = up.get(current) ?? null;
    }

    return path.reverse();
}

/**
 * The ids of the people whose name, code or designation contains the query (any case), in the order given. Pass a
 * flattened tree to get them in reading order. An empty or blank query matches nobody.
 */
export function matchNodes(nodes: OrgNode[], query: string): number[] {
    const q = query.trim().toLocaleLowerCase();

    if (q === '') {
        return [];
    }

    return nodes
        .filter((n) =>
            [n.name, n.code, n.designation ?? ''].some((v) =>
                v.toLocaleLowerCase().includes(q),
            ),
        )
        .map((n) => n.id);
}

/** The people who start open: those with reports in the first `levels` levels (roots are level 1). */
export function defaultExpanded(
    roots: OrgTreeNode[],
    levels: number = DEFAULT_OPEN_LEVELS,
): Set<number> {
    const open = new Set<number>();
    let level = roots;

    for (let depth = 0; depth < levels && level.length > 0; depth++) {
        const next: OrgTreeNode[] = [];

        for (const n of level) {
            if (n.children.length > 0) {
                open.add(n.id);
                next.push(...n.children);
            }
        }

        level = next;
    }

    return open;
}

/** How the chart scrolls to a search match: never animated for someone who prefers reduced motion. */
export function scrollBehavior(reducedMotion: boolean): 'auto' | 'smooth' {
    return reducedMotion ? 'auto' : 'smooth';
}
