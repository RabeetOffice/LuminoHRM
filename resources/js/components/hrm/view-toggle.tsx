import { LayoutGrid, List } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ViewMode = 'list' | 'grid';

type Props = {
    value: ViewMode;
    onChange: (value: ViewMode) => void;
    className?: string;
};

const options: { value: ViewMode; label: string; icon: typeof List }[] = [
    { value: 'list', label: 'List view', icon: List },
    { value: 'grid', label: 'Grid view', icon: LayoutGrid },
];

/** Two icon buttons that switch between a list and a grid. The page decides what a change does. */
export function ViewToggle({ value, onChange, className }: Props) {
    return (
        <div
            role="group"
            aria-label="View"
            className={cn(
                'inline-flex rounded-md border bg-background p-0.5',
                className,
            )}
        >
            {options.map(({ value: option, label, icon: Icon }) => {
                const active = option === value;

                return (
                    <button
                        key={option}
                        type="button"
                        aria-pressed={active}
                        aria-label={label}
                        title={label}
                        onClick={() => !active && onChange(option)}
                        className={cn(
                            'flex size-8 items-center justify-center rounded-sm transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 [&_svg]:size-4',
                            active
                                ? 'bg-primary/10 text-brand-ink'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        <Icon aria-hidden="true" />
                    </button>
                );
            })}
        </div>
    );
}
