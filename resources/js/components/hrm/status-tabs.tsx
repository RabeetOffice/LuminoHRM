import { useRef } from 'react';
import type { KeyboardEvent } from 'react';
import { nextTabIndex, statusPanelId, statusTabId } from '@/lib/tabs';
import { cn } from '@/lib/utils';

export type StatusTab = {
    key: string;
    label: string;
    /** Shown in a small pill. Pass totals, not the filtered count. */
    count?: number;
};

type Props = {
    tabs: StatusTab[];
    value: string;
    onChange: (key: string) => void;
    /** Accessible name for the tab list, e.g. "Organization sections". */
    label?: string;
    /**
     * Prefix for the generated ids. The tab panel must have
     * id={statusPanelId(idPrefix)} and aria-labelledby={statusTabId(idPrefix, activeKey)}.
     */
    idPrefix: string;
    className?: string;
};

/**
 * Status tabs with counts (All / Active / Inactive ...). Purely presentational:
 * the parent decides what a tab does, usually a server-driven visit.
 * Arrow keys, Home and End move between tabs; focus stays on the newly selected
 * tab, so keep the tab list outside any part of the page that remounts when the
 * tab changes.
 */
export function StatusTabs({
    tabs,
    value,
    onChange,
    label,
    idPrefix,
    className,
}: Props) {
    const refs = useRef<Record<string, HTMLButtonElement | null>>({});

    const onKeyDown = (event: KeyboardEvent, index: number) => {
        const target = nextTabIndex(event.key, index, tabs.length);

        if (target === null) {
            return;
        }

        event.preventDefault();
        const next = tabs[target];

        refs.current[next.key]?.focus();
        onChange(next.key);
    };

    return (
        <div
            role="tablist"
            aria-label={label}
            className={cn(
                'flex gap-1 overflow-x-auto border-b px-2 sm:px-4',
                className,
            )}
        >
            {tabs.map((tab, index) => {
                const active = tab.key === value;

                return (
                    <button
                        key={tab.key}
                        ref={(el) => {
                            refs.current[tab.key] = el;
                        }}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        id={statusTabId(idPrefix, tab.key)}
                        aria-controls={statusPanelId(idPrefix)}
                        tabIndex={active ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        onKeyDown={(event) => onKeyDown(event, index)}
                        className={cn(
                            '-mb-px flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            active
                                ? 'border-primary text-brand-ink'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                        {tab.count !== undefined && (
                            <span
                                className={cn(
                                    'rounded-full px-2 py-0.5 text-xs tabular-nums',
                                    active
                                        ? 'bg-primary/10 text-brand-ink'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {tab.count}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}
