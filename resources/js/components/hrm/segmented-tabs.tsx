import { useRef } from 'react';
import type { KeyboardEvent } from 'react';
import { nextTabIndex, statusPanelId, statusTabId } from '@/lib/tabs';
import { cn } from '@/lib/utils';

export type SegmentedTab = { key: string; label: string };

type Props = {
    tabs: SegmentedTab[];
    value: string;
    onChange: (key: string) => void;
    /** Accessible name of the tab list. */
    label: string;
    /**
     * Prefix of the generated ids. The one panel under the tabs must have id={statusPanelId(idPrefix)} and
     * aria-labelledby={statusTabId(idPrefix, activeKey)}.
     */
    idPrefix: string;
    className?: string;
};

/**
 * A full-width segmented control: one grey track, the selected tab raised on it. Tabs share the width when they
 * fit and keep a readable minimum on a phone, where the track scrolls sideways. Arrow keys, Home and End move
 * between tabs. Purely presentational: the page decides what a tab shows.
 */
export function SegmentedTabs({
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
                'flex overflow-x-auto rounded-lg bg-muted p-1 text-muted-foreground',
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
                            'min-w-28 flex-1 rounded-md px-3 py-1.5 text-sm font-medium whitespace-nowrap transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                            active
                                ? 'bg-background text-foreground shadow-xs'
                                : 'hover:text-foreground',
                        )}
                    >
                        {tab.label}
                    </button>
                );
            })}
        </div>
    );
}
