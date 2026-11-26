import { Link } from '@inertiajs/react';
import { BrandLogo, BrandMarkImage } from '@/components/hrm/brand-logo';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="flex min-h-svh">
            {/* Wide screens only: brand side panel. Decorative, so images carry empty alt text. */}
            <aside
                aria-label="About Lumino HRM"
                className="relative hidden w-[42%] max-w-xl shrink-0 flex-col justify-between overflow-hidden bg-[oklch(0.24_0.06_265)] bg-[url('/brand/panel-tall.webp')] bg-cover bg-center p-10 text-white lg:flex xl:p-12"
            >
                <div
                    aria-hidden="true"
                    className="absolute inset-0 bg-gradient-to-b from-black/10 via-transparent to-black/55"
                />
                <div className="relative flex flex-col gap-5 rounded-2xl border border-white/15 bg-white/10 p-6 backdrop-blur-md">
                    <BrandMarkImage alt="" className="size-14" />
                    <p className="text-xl leading-snug font-medium tracking-tight">
                        HR, attendance and payroll for Lumino Digital
                    </p>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col items-center justify-center gap-6 p-4 sm:p-6 md:p-10">
                <div className="w-full max-w-sm animate-page-enter rounded-2xl border border-glass-border glass-strong p-6 shadow-glass-lg sm:p-8">
                    <div className="flex flex-col gap-8">
                        <div className="flex flex-col items-center gap-4">
                            <Link
                                href={home()}
                                className="flex flex-col items-center gap-2 font-medium"
                            >
                                <div className="mb-1 flex items-center justify-center">
                                    <BrandLogo className="h-11 max-w-56 object-contain" />
                                </div>
                                <span className="sr-only">{title}</span>
                            </Link>

                            <div className="space-y-2 text-center">
                                <h1 className="text-xl font-medium">{title}</h1>
                                <p className="text-center text-sm text-muted-foreground">
                                    {description}
                                </p>
                            </div>
                        </div>
                        {children}
                    </div>
                </div>
            </div>
        </div>
    );
}
