import { useRef } from 'react';
import type { KeyboardEvent, ReactNode } from 'react';
import { nextTabIndex } from '@/lib/tabs';
import { cn } from '@/lib/utils';

export type FormTab = {
    key: string;
    label: string;
    /** Shows a red dot (and "has errors" for screen readers) on the tab. */
    hasError?: boolean;
    content: ReactNode;
};

type Props = {
    tabs: FormTab[];
    value: string;
    onChange: (key: string) => void;
    /** Accessible name of the tab list. */
    label: string;
    /** Prefix of the generated ids. */
    idPrefix: string;
    className?: string;
};

export const formTabId = (prefix: string, key: string) =>
    `${prefix}-tab-${key}`;
export const formPanelId = (prefix: string, key: string) =>
    `${prefix}-panel-${key}`;

/**
 * Tabs for one long form. Every panel stays mounted (hidden when not selected), so typed values, errors and the
 * browser's own form state survive switching tabs and a single Save sends everything. Arrow keys, Home and End
 * move between tabs. A tab with `hasError` shows a red dot.
 */
export function FormTabs({
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
        <div className={className}>
            <div
                role="tablist"
                aria-label={label}
                className="flex gap-1 overflow-x-auto border-b px-2 sm:px-4"
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
                            id={formTabId(idPrefix, tab.key)}
                            aria-controls={formPanelId(idPrefix, tab.key)}
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
                            {tab.hasError && (
                                <>
                                    <span
                                        aria-hidden="true"
                                        className="size-2 rounded-full bg-red-500"
                                    />
                                    <span className="sr-only">has errors</span>
                                </>
                            )}
                        </button>
                    );
                })}
            </div>
            {tabs.map((tab) => (
                <div
                    key={tab.key}
                    role="tabpanel"
                    id={formPanelId(idPrefix, tab.key)}
                    aria-labelledby={formTabId(idPrefix, tab.key)}
                    hidden={tab.key !== value}
                    className="p-4 sm:p-6"
                >
                    {tab.content}
                </div>
            ))}
        </div>
    );
}
