import {
    AlertTriangle,
    ChevronDown,
    CircleCheck,
    OctagonX,
} from 'lucide-react';
import { useState } from 'react';
import { peopleCount, sortChecks } from '@/lib/payroll';
import type { RunCheck } from '@/lib/payroll';
import { cn } from '@/lib/utils';

type Props = {
    checks: RunCheck[];
    /** A draft is judged live; an approved run shows the warnings it was approved with. */
    draft: boolean;
};

function CheckRow({ check }: { check: RunCheck }) {
    const [open, setOpen] = useState(false);
    const blocking = check.severity === 'block';
    const Icon = blocking ? OctagonX : AlertTriangle;
    const expandable = check.people.length > 0;

    return (
        <li
            className={cn(
                'rounded-lg border px-3 py-2.5 text-sm',
                blocking
                    ? 'border-red-200 bg-red-50 text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-200'
                    : 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200',
            )}
        >
            <div className="flex items-start gap-2">
                <Icon aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                <p className="min-w-0 flex-1">
                    <span className="sr-only">
                        {blocking ? 'Blocks approval: ' : 'Warning: '}
                    </span>
                    {check.message}
                </p>
                {expandable && (
                    <button
                        type="button"
                        onClick={() => setOpen((o) => !o)}
                        aria-expanded={open}
                        className="flex shrink-0 items-center gap-1 rounded-sm text-xs font-medium underline-offset-2 outline-none hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {peopleCount(check.people.length)}
                        <ChevronDown
                            aria-hidden="true"
                            className={cn(
                                'size-3.5 transition-transform',
                                open && 'rotate-180',
                            )}
                        />
                    </button>
                )}
            </div>
            {expandable && open && (
                <ul className="mt-2 flex flex-wrap gap-1.5 pl-6">
                    {check.people.map((person) => (
                        <li
                            key={person.id}
                            className="rounded-full bg-background/70 px-2.5 py-0.5 text-xs ring-1 ring-current/15"
                        >
                            {person.name}
                        </li>
                    ))}
                </ul>
            )}
        </li>
    );
}

/**
 * What blocks approval (red, first) and what only deserves a look (amber), each
 * expandable to the people it is about.
 */
export function RunChecks({ checks, draft }: Props) {
    const sorted = sortChecks(checks);
    const blocking = sorted.filter((c) => c.severity === 'block').length;

    return (
        <section
            aria-labelledby="run-checks-title"
            className="overflow-hidden rounded-xl border bg-card shadow-xs"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-3">
                <h2 id="run-checks-title" className="font-medium">
                    Checks
                </h2>
                {draft && (
                    <p className="text-sm text-muted-foreground">
                        {blocking > 0
                            ? `${blocking} must be fixed before approval`
                            : 'Nothing blocks approval'}
                    </p>
                )}
            </div>
            {sorted.length === 0 ? (
                <p className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
                    <CircleCheck
                        aria-hidden="true"
                        className="size-4 text-emerald-600"
                    />
                    {draft ? 'All clear.' : 'Approved with no warnings.'}
                </p>
            ) : (
                <ul className="grid gap-2 p-3">
                    {sorted.map((check) => (
                        <CheckRow key={check.key} check={check} />
                    ))}
                </ul>
            )}
        </section>
    );
}
