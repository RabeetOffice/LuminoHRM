import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export const BRAND_ALT = 'Lumino Digital HRM';

type Props = {
    className?: string;
    /**
     * Sidebar use: in the collapsed icon rail show the mark alone instead of
     * the full logo.
     */
    iconRail?: boolean;
};

/** The "L with people" mark on its own. */
export function BrandMarkImage({
    className,
    alt = BRAND_ALT,
}: {
    className?: string;
    alt?: string;
}) {
    return (
        <img
            src="/brand/mark.png"
            alt={alt}
            width={512}
            height={512}
            className={cn('aspect-square w-auto object-contain', className)}
        />
    );
}

/**
 * The uploaded company logo, with the dark-theme variant when there is one.
 * With only one of the two uploaded, that one is used in both themes. Until a
 * logo is uploaded the Lumino Digital logo is shown (light or dark by the
 * `dark` class, no JS).
 */
export function BrandLogo({ className, iconRail = false }: Props) {
    const { app } = usePage().props;
    const light = app.logoUrl ?? app.logoDarkUrl;
    const dark = app.logoDarkUrl ?? app.logoUrl;

    if (light && dark) {
        return (
            <>
                <img
                    src={light}
                    alt={app.name}
                    className={cn(
                        'dark:hidden',
                        iconRail &&
                            'group-data-[collapsible=icon]:w-8 group-data-[collapsible=icon]:object-cover',
                        className,
                    )}
                />
                <img
                    src={dark}
                    alt={app.name}
                    className={cn(
                        'hidden dark:block',
                        iconRail &&
                            'group-data-[collapsible=icon]:w-8 group-data-[collapsible=icon]:object-cover',
                        className,
                    )}
                />
            </>
        );
    }

    return (
        <>
            <img
                src="/brand/logo-light.png"
                alt={BRAND_ALT}
                width={1271}
                height={324}
                className={cn(
                    'w-auto object-contain dark:hidden',
                    iconRail && 'group-data-[collapsible=icon]:hidden',
                    className,
                )}
            />
            <img
                src="/brand/logo-dark.png"
                alt={BRAND_ALT}
                width={849}
                height={232}
                className={cn(
                    'hidden w-auto object-contain dark:block',
                    iconRail && 'group-data-[collapsible=icon]:hidden',
                    className,
                )}
            />
            {iconRail && (
                <BrandMarkImage className="hidden size-8 group-data-[collapsible=icon]:block" />
            )}
        </>
    );
}
