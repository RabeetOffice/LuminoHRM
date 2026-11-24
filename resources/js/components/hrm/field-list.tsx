import type { ReactNode } from 'react';
import { EMPTY_VALUE, isEmptyValue } from '@/lib/employee-profile';
import { cn } from '@/lib/utils';

export type FieldItem = {
    label: string;
    /** Text or an element (a link, a pill). Null, undefined or blank text shows "—". */
    value: ReactNode;
    /** Spans every column (a long address, a list). */
    wide?: boolean;
};

type Props = {
    items: FieldItem[];
    /** 2 (default) puts two fields side by side from the small breakpoint up; a phone always gets one column. */
    columns?: 1 | 2;
    className?: string;
};

/** Small grey labels over values, in a grid. An empty value reads "—" so a missing fact is never a blank hole. */
export function FieldList({ items, columns = 2, className }: Props) {
    return (
        <dl
            className={cn(
                'grid gap-x-8 gap-y-5',
                columns === 2 ? 'sm:grid-cols-2' : 'grid-cols-1',
                className,
            )}
        >
            {items.map((item) => (
                <div
                    key={item.label}
                    className={cn(
                        'min-w-0',
                        item.wide && columns === 2 && 'sm:col-span-2',
                    )}
                >
                    <dt className="text-xs text-muted-foreground">
                        {item.label}
                    </dt>
                    <dd className="mt-1 text-sm font-medium break-words">
                        {isEmptyValue(item.value) ? EMPTY_VALUE : item.value}
                    </dd>
                </div>
            ))}
        </dl>
    );
}
