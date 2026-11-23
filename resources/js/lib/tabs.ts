/** Index of the tab a key press should select, or null when the key is not a tab key. */
export function nextTabIndex(
    key: string,
    index: number,
    count: number,
): number | null {
    if (count === 0) {
        return null;
    }

    switch (key) {
        case 'ArrowRight':
            return index === count - 1 ? 0 : index + 1;
        case 'ArrowLeft':
            return index === 0 ? count - 1 : index - 1;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/** id of a tab button; the panel uses it in aria-labelledby. */
export const statusTabId = (prefix: string, key: string): string =>
    `${prefix}-tab-${key}`;

/** id of the panel the tabs control. */
export const statusPanelId = (prefix: string): string => `${prefix}-panel`;
