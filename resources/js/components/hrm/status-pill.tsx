import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type StatusTone = 'green' | 'grey' | 'amber' | 'red' | 'blue';

// Green Active/Approved/Present, grey Inactive/Draft, amber Probation/Pending/Late,
// red Terminated/Rejected/Absent, blue On Leave.
const tones: Record<StatusTone, string> = {
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    grey: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-500/20 dark:text-neutral-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    red: 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    blue: 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-300',
};

const dots: Record<StatusTone, string> = {
    green: 'bg-emerald-500',
    grey: 'bg-neutral-400',
    amber: 'bg-amber-500',
    red: 'bg-red-500',
    blue: 'bg-blue-500',
};

type Props = {
    tone: StatusTone;
    children: ReactNode;
    className?: string;
};

export function StatusPill({ tone, children, className }: Props) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                tones[tone],
                className,
            )}
        >
            <span
                aria-hidden="true"
                className={cn('size-1.5 rounded-full', dots[tone])}
            />
            {children}
        </span>
    );
}
