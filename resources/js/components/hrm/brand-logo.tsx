import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Props = {
    /** Shown when no logo has been uploaded. */
    fallback: ReactNode;
    className?: string;
};

/**
 * The uploaded company logo, with the dark-theme variant when there is one.
 * With only one of the two uploaded, that one is used in both themes.
 */
export function BrandLogo({ fallback, className }: Props) {
    const { app } = usePage().props;
    const light = app.logoUrl ?? app.logoDarkUrl;
    const dark = app.logoDarkUrl ?? app.logoUrl;

    if (!light || !dark) {
        return <>{fallback}</>;
    }

    return (
        <>
            <img
                src={light}
                alt={app.name}
                className={cn('dark:hidden', className)}
            />
            <img
                src={dark}
                alt={app.name}
                className={cn('hidden dark:block', className)}
            />
        </>
    );
}
