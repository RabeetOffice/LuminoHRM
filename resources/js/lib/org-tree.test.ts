import { describe, expect, it } from 'vitest';
import {
    buildTree,
    countReports,
    defaultExpanded,
    findPath,
    flattenTree,
    matchNodes,
} from '@/lib/org-tree';
import type { OrgNode, OrgTreeNode } from '@/lib/org-tree';

function node(
    id: number,
    manager_id: number | null,
    name = `Person ${String(id).padStart(4, '0')}`,
    extra: Partial<OrgNode> = {},
): OrgNode {
    return {
        id,
        name,
        code: `LD-${String(id).padStart(4, '0')}`,
        designation: 'Engineer',
        department: 'Engineering',
        photo_url: null,
        manager_id,
        ...extra,
    };
}

/** id => ids of children, for every node in the tree (iterative so deep trees are fine). */
function shape(roots: OrgTreeNode[]): Record<number, number[]> {
    const out: Record<number, number[]> = {};

    for (const n of flattenTree(roots)) {
        out[n.id] = n.children.map((c) => c.id);
    }

    return out;
}

describe('buildTree', () => {
    it('returns nothing for no people', () => {
        expect(buildTree([])).toEqual([]);
    });

    it('makes one person a root with no children', () => {
        const roots = buildTree([node(1, null, 'Ali Khan')]);

        expect(roots).toHaveLength(1);
        expect(roots[0]).toMatchObject({
            id: 1,
            name: 'Ali Khan',
            children: [],
        });
    });

    it('puts every report under their manager in a wide tree', () => {
        const people = [node(1, null)];

        for (let i = 2; i <= 301; i++) {
            people.push(node(i, 1));
        }

        const roots = buildTree(people);

        expect(roots.map((r) => r.id)).toEqual([1]);
        expect(roots[0].children).toHaveLength(300);
        expect(countReports(roots[0])).toBe(300);
    });

    it('handles a chain 500 levels deep without overflowing the stack', () => {
        const people = [node(1, null)];

        for (let i = 2; i <= 500; i++) {
            people.push(node(i, i - 1));
        }

        const roots = buildTree(people);
        expect(roots).toHaveLength(1);

        let depth = 1;
        let current = roots[0];

        while (current.children.length > 0) {
            current = current.children[0];
            depth++;
        }

        expect(depth).toBe(500);
        expect(current.id).toBe(500);
        expect(flattenTree(roots)).toHaveLength(500);
        expect(findPath(roots, 500)).toHaveLength(500);
    });

    it('makes a person whose manager is missing a root', () => {
        const roots = buildTree([node(1, null), node(2, 99), node(3, 2)]);

        expect(roots.map((r) => r.id)).toEqual([1, 2]);
        expect(shape(roots)[2]).toEqual([3]);
    });

    it('breaks a two-person loop at the lowest id', () => {
        const roots = buildTree([node(7, 4), node(4, 7)]);

        expect(roots.map((r) => r.id)).toEqual([4]);
        expect(shape(roots)).toEqual({ 4: [7], 7: [] });
    });

    it('breaks a three-person loop at the lowest id, whatever the input order', () => {
        const a = [node(5, 9), node(9, 3), node(3, 5), node(10, 9)];

        for (const people of [a, [...a].reverse(), [a[2], a[0], a[3], a[1]]]) {
            const roots = buildTree(people);

            expect(roots.map((r) => r.id)).toEqual([3]);
            // 3 is the root; 9 reports to 3; 5 and 10 report to 9.
            expect(shape(roots)).toEqual({ 3: [9], 9: [5, 10], 5: [], 10: [] });
        }
    });

    it('keeps people hanging off a loop attached to it', () => {
        const roots = buildTree([
            node(2, 1),
            node(1, 2),
            node(3, 2),
            node(4, null),
        ]);

        expect(roots.map((r) => r.id)).toEqual([1, 4]);
        expect(shape(roots)[2]).toEqual([3]);
        expect(flattenTree(roots)).toHaveLength(4);
    });

    it('makes a person who reports to themself a root', () => {
        const roots = buildTree([node(1, 1), node(2, 1)]);

        expect(roots.map((r) => r.id)).toEqual([1]);
        expect(shape(roots)).toEqual({ 1: [2], 2: [] });
    });

    it('orders siblings and roots by name, then id', () => {
        const roots = buildTree([
            node(1, null, 'Zed'),
            node(2, null, 'amy'),
            node(5, 1, 'Sara'),
            node(4, 1, 'Bilal'),
            node(3, 1, 'Sara'),
        ]);

        expect(roots.map((r) => r.name)).toEqual(['amy', 'Zed']);
        expect(roots[1].children.map((c) => c.id)).toEqual([4, 3, 5]);
    });

    it('does not change the input', () => {
        const people = [node(2, 1), node(1, 2)];
        const copy = JSON.parse(JSON.stringify(people));

        buildTree(people);

        expect(people).toEqual(copy);
    });
});

describe('countReports', () => {
    it('counts direct reports only', () => {
        const roots = buildTree([
            node(1, null),
            node(2, 1),
            node(3, 1),
            node(4, 2),
        ]);

        expect(countReports(roots[0])).toBe(2);
        expect(countReports(roots[0].children[0])).toBe(1);
        expect(countReports(roots[0].children[1])).toBe(0);
    });
});

describe('findPath', () => {
    const roots = buildTree([
        node(1, null),
        node(2, 1),
        node(3, 2),
        node(4, null),
        node(5, 4),
    ]);

    it('gives the ids from the root down to the person', () => {
        expect(findPath(roots, 3)).toEqual([1, 2, 3]);
        expect(findPath(roots, 5)).toEqual([4, 5]);
    });

    it('gives just the root for a root', () => {
        expect(findPath(roots, 4)).toEqual([4]);
    });

    it('gives an empty path for someone not in the tree', () => {
        expect(findPath(roots, 42)).toEqual([]);
        expect(findPath([], 1)).toEqual([]);
    });
});

describe('matchNodes', () => {
    const people = [
        node(1, null, 'Ali Khan', { code: 'LD-0001', designation: 'CEO' }),
        node(2, 1, 'Sara Ahmed', {
            code: 'LD-0002',
            designation: 'Head of Sales',
        }),
        node(3, 1, 'Bilal Raza', {
            code: 'LD-0042',
            designation: 'Software Engineer',
        }),
    ];

    it('finds by name, code or designation, ignoring case', () => {
        expect(matchNodes(people, 'khan')).toEqual([1]);
        expect(matchNodes(people, 'ld-0042')).toEqual([3]);
        expect(matchNodes(people, 'SALES')).toEqual([2]);
        expect(matchNodes(people, 'a')).toEqual([1, 2, 3]);
    });

    it('ignores spaces around the query', () => {
        expect(matchNodes(people, '  sara  ')).toEqual([2]);
    });

    it('matches nothing for an empty query or no match', () => {
        expect(matchNodes(people, '')).toEqual([]);
        expect(matchNodes(people, '   ')).toEqual([]);
        expect(matchNodes(people, 'zzz')).toEqual([]);
    });

    it('does not search the department', () => {
        expect(matchNodes(people, 'engineering')).toEqual([]);
    });

    it('keeps the order it was given (tree order when given a flattened tree)', () => {
        const roots = buildTree(people);
        const ordered = flattenTree(roots);

        expect(ordered.map((n) => n.id)).toEqual([1, 3, 2]);
        expect(matchNodes(ordered, 'a')).toEqual([1, 3, 2]);
    });
});

describe('defaultExpanded', () => {
    it('opens the first two levels of people who have reports', () => {
        const roots = buildTree([
            node(1, null),
            node(2, 1),
            node(3, 2),
            node(4, 3),
            node(5, null),
            node(6, 1),
        ]);

        expect([...defaultExpanded(roots)].sort((a, b) => a - b)).toEqual([
            1, 2,
        ]);
    });

    it('is empty for no people', () => {
        expect(defaultExpanded([]).size).toBe(0);
    });
});
