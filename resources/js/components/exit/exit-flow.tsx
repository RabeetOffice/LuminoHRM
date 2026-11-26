import { usePage } from '@inertiajs/react';
import { StatusPill } from '@/components/hrm/status-pill';
import { flowText, flowTone } from '@/lib/exit';
import type { FlowStep } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';

/**
 * The approval flow of an exit: submitted, the manager step, the HR step (and withdrawn or cancelled at the end), each with
 * its state, who did it and when. A skipped manager step says so. A rejection shows its note.
 */
export function ExitFlow({ steps }: { steps: FlowStep[] }) {
    const dateFormat = usePage().props.app.dateFormat;

    return (
        <ol aria-label="Approval flow" className="grid gap-0">
            {steps.map((step, index) => (
                <li
                    key={step.key}
                    className="relative flex gap-3 pb-5 last:pb-0"
                >
                    {index < steps.length - 1 && (
                        <span
                            aria-hidden="true"
                            className="absolute top-5 bottom-0 left-[7px] w-px bg-border"
                        />
                    )}
                    <span
                        aria-hidden="true"
                        className={
                            step.state === 'done'
                                ? 'relative mt-1 size-4 shrink-0 rounded-full bg-emerald-500 ring-4 ring-background'
                                : step.state === 'current'
                                  ? 'relative mt-1 size-4 shrink-0 rounded-full bg-amber-500 ring-4 ring-background'
                                  : step.state === 'rejected'
                                    ? 'relative mt-1 size-4 shrink-0 rounded-full bg-red-500 ring-4 ring-background'
                                    : 'relative mt-1 size-4 shrink-0 rounded-full border-2 border-muted-foreground/40 bg-background ring-4 ring-background'
                        }
                    />
                    <div className="grid min-w-0 flex-1 gap-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium">
                                {step.label}
                            </span>
                            <StatusPill tone={flowTone(step.state)}>
                                {flowText(step.state)}
                            </StatusPill>
                        </div>
                        {(step.who || step.on) && (
                            <p className="text-xs text-muted-foreground">
                                {[
                                    step.who,
                                    step.on
                                        ? formatDate(step.on, dateFormat)
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </p>
                        )}
                        {step.state === 'skipped' && (
                            <p className="text-xs text-muted-foreground">
                                No manager decision needed for this exit.
                            </p>
                        )}
                        {step.note && (
                            <p className="rounded-md bg-muted px-3 py-2 text-sm break-words whitespace-pre-line">
                                {step.note}
                            </p>
                        )}
                    </div>
                </li>
            ))}
        </ol>
    );
}
