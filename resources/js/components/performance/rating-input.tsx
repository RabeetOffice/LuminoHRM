import { cn } from '@/lib/utils';

type Props = {
    value: number | null;
    onChange: (value: number | null) => void;
    /** The accessible name, e.g. "Self rating for Quality of work". */
    label: string;
    disabled?: boolean;
    invalid?: boolean;
    describedBy?: string;
};

const SCALE = [1, 2, 3, 4, 5];

/**
 * A 1 to 5 rating control. Five round buttons in a radio group; pressing the chosen one again clears it. The ratings are
 * whole numbers only, which is also what the server accepts.
 */
export function RatingInput({
    value,
    onChange,
    label,
    disabled,
    invalid,
    describedBy,
}: Props) {
    return (
        <div
            role="radiogroup"
            aria-label={label}
            aria-invalid={invalid || undefined}
            aria-describedby={describedBy}
            className="inline-flex flex-wrap gap-1.5"
        >
            {SCALE.map((n) => {
                const checked = value === n;

                return (
                    <button
                        key={n}
                        type="button"
                        role="radio"
                        aria-checked={checked}
                        aria-label={`${n} of 5`}
                        disabled={disabled}
                        onClick={() => onChange(checked ? null : n)}
                        className={cn(
                            'size-9 rounded-full border text-sm font-medium transition-colors duration-(--duration-fast) outline-none',
                            'focus-visible:ring-[3px] focus-visible:ring-ring/40 disabled:cursor-not-allowed disabled:opacity-50',
                            checked
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-input bg-surface-control text-foreground hover:bg-accent',
                            invalid && !checked && 'border-destructive',
                        )}
                    >
                        {n}
                    </button>
                );
            })}
        </div>
    );
}
