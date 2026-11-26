import type { ReactNode } from 'react';

type Props = {
    title: string;
    subtitle?: string;
    /** Buttons for the right-hand side, e.g. Export, Import, Add. */
    actions?: ReactNode;
};

export function PageHeader({ title, subtitle, actions }: Props) {
    return (
        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
                <h1 className="text-2xl font-semibold tracking-tight">
                    {title}
                </h1>
                {subtitle && (
                    <p className="mt-1 text-sm text-muted-foreground">
                        {subtitle}
                    </p>
                )}
            </div>
            {actions && (
                <div className="flex flex-wrap items-center gap-2 sm:max-w-[65%] sm:shrink-0 sm:justify-end">
                    {actions}
                </div>
            )}
        </div>
    );
}
