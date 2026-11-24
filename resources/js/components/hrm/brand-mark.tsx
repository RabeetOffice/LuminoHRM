import { cn } from '@/lib/utils';

type Props = {
    /** Edge length of the tile, as Tailwind size classes. */
    size?: 'sm' | 'md' | 'lg';
    /** Show the product name next to (or under) the tile. */
    wordmark?: boolean;
    /** Stack the wordmark under the tile instead of beside it. */
    stacked?: boolean;
    className?: string;
};

const tile = {
    sm: 'size-6 rounded-md text-xs',
    md: 'size-8 rounded-md text-sm',
    lg: 'size-11 rounded-xl text-lg',
} as const;

const word = {
    sm: 'text-sm',
    md: 'text-base',
    lg: 'text-xl',
} as const;

/**
 * The default Lumino HRM mark: an accent tile with "L" and, optionally, the
 * product name. Used wherever no company logo has been uploaded.
 */
export function BrandMark({
    size = 'md',
    wordmark = false,
    stacked = false,
    className,
}: Props) {
    return (
        <span
            className={cn(
                'inline-flex items-center',
                stacked ? 'flex-col gap-3' : 'gap-2',
                className,
            )}
        >
            <span
                aria-hidden={wordmark}
                role={wordmark ? undefined : 'img'}
                aria-label={wordmark ? undefined : 'Lumino HRM'}
                className={cn(
                    'flex aspect-square shrink-0 items-center justify-center bg-primary font-bold text-primary-foreground',
                    tile[size],
                )}
            >
                L
            </span>
            {wordmark && (
                <span
                    className={cn(
                        'truncate font-semibold tracking-tight',
                        word[size],
                    )}
                >
                    Lumino HRM
                </span>
            )}
        </span>
    );
}
