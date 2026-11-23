export const PER_PAGE_OPTIONS = [10, 25, 50] as const;

export type QueryChanges = Record<string, string | number | null | undefined>;

/**
 * The URL to visit after changing some query-string parameters of the current
 * one. Everything not mentioned is kept (other tab, filters, the other table's
 * page); a value of null, undefined or '' removes the parameter.
 */
export function nextTableUrl(current: string, changes: QueryChanges): string {
    const [path, query = ''] = current.split('?');
    const params = new URLSearchParams(query);

    for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === undefined || value === '') {
            params.delete(key);
        } else {
            params.set(key, String(value));
        }
    }

    const search = params.toString();

    return search === '' ? path : `${path}?${search}`;
}

export type PageItem = number | 'gap';

/** Page numbers to show: first, last, and the current page with one neighbour each side. */
export function pageItems(current: number, last: number): PageItem[] {
    const wanted = new Set<number>(
        [1, last, current - 1, current, current + 1].filter(
            (p) => p >= 1 && p <= last,
        ),
    );
    const pages = [...wanted].sort((a, b) => a - b);
    const items: PageItem[] = [];

    pages.forEach((page, index) => {
        const previous = pages[index - 1];

        if (previous !== undefined && page - previous > 1) {
            // A gap of exactly one page is cheaper to show than to hide.
            if (page - previous === 2) {
                items.push(previous + 1);
            } else {
                items.push('gap');
            }
        }

        items.push(page);
    });

    return items;
}
