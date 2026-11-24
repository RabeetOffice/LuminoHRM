import {
    Avatar as AvatarRoot,
    AvatarFallback,
    AvatarImage,
} from '@/components/ui/avatar';
import { initials, tintFor } from '@/lib/avatar';
import { cn } from '@/lib/utils';

export type AvatarSize = 'sm' | 'md' | 'lg' | 'xl';

const sizes: Record<AvatarSize, string> = {
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-14 text-lg',
    xl: 'size-20 text-2xl',
};

type Props = {
    /** Photo address. Null, empty or a photo that fails to load shows the initials instead. */
    src?: string | null;
    /** The person's name: gives the initials and the background colour (always the same for the same name). */
    name: string;
    /** sm 32 px (default), md 40, lg 56, xl 80. */
    size?: AvatarSize;
    className?: string;
};

/**
 * A round photo, or the person's initials on a soft colour chosen from their name. It is decoration: the
 * name is always written next to it, so it is hidden from screen readers.
 */
export function Avatar({ src, name, size = 'sm', className }: Props) {
    return (
        <AvatarRoot aria-hidden="true" className={cn(sizes[size], className)}>
            {src ? <AvatarImage src={src} alt="" /> : null}
            <AvatarFallback
                className={cn('font-medium select-none', tintFor(name))}
            >
                {initials(name)}
            </AvatarFallback>
        </AvatarRoot>
    );
}
