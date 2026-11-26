import { usePage } from '@inertiajs/react';
import { CircleCheck } from 'lucide-react';
import ClearanceController from '@/actions/App/Http/Controllers/Exits/ClearanceController';
import { ActionError, useExitAction } from '@/components/exit/use-exit-action';
import { StatusPill } from '@/components/hrm/status-pill';
import { Checkbox } from '@/components/ui/checkbox';
import { formatDate } from '@/lib/format-date';

export type ClearanceItem = {
    id: number;
    kind: string;
    label: string;
    cleared: boolean;
    cleared_by: string | null;
    cleared_at: string | null;
    /** Ticked by the system (the assets item): never by hand. */
    automatic: boolean;
    /** Asset codes still held (the assets item). */
    held?: string[] | number | null;
};

export type Clearance = {
    items: ClearanceItem[];
    complete: boolean;
    missing: string[];
    /** True when the viewer may tick items (HR / Admin, not their own exit, settlement not yet approved). */
    editable: boolean;
};

function heldText(held: ClearanceItem['held']): string | null {
    if (Array.isArray(held) && held.length > 0) {
        return `Still held: ${held.join(', ')}`;
    }

    return typeof held === 'number' && held > 0 ? `${held} still held` : null;
}

/**
 * The clearance checklist. HR and Admin tick the hand items (with who and when); "Assets returned" ticks itself when
 * nothing is held. The employee sees the same list read-only. Frozen once the settlement is approved. A refusal of a tick
 * shows above the list.
 */
export function ClearancePanel({
    clearance,
    frozen,
}: {
    clearance: Clearance;
    frozen: boolean;
}) {
    const dateFormat = usePage().props.app.dateFormat;
    const { busy, error, run } = useExitAction();

    return (
        <section
            className="rounded-xl border bg-card shadow-xs"
            aria-label="Clearance"
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b px-5 py-4">
                <h2 className="text-base font-semibold">Clearance</h2>
                {clearance.complete ? (
                    <StatusPill tone="green">Complete</StatusPill>
                ) : (
                    <StatusPill tone="amber">
                        {clearance.missing.length === 1
                            ? '1 item left'
                            : `${clearance.missing.length} items left`}
                    </StatusPill>
                )}
            </div>
            <div className="grid gap-3 p-5">
                <ActionError messages={error} />
                {frozen && (
                    <p className="text-sm text-muted-foreground">
                        Clearance is frozen: the final settlement is approved.
                    </p>
                )}
                <ul role="list" className="divide-y rounded-lg border">
                    {clearance.items.map((item) => {
                        const locked =
                            !clearance.editable ||
                            frozen ||
                            item.automatic ||
                            item.kind === 'assets';
                        const id = `clearance-${item.id}`;
                        const held = heldText(item.held);

                        return (
                            <li
                                key={item.id}
                                className="flex items-start gap-3 px-4 py-3"
                            >
                                {locked ? (
                                    <span
                                        aria-hidden="true"
                                        className={
                                            item.cleared
                                                ? 'mt-0.5 flex size-4 shrink-0 items-center justify-center text-emerald-600 dark:text-emerald-400'
                                                : 'mt-0.5 size-4 shrink-0 rounded-[4px] border border-input'
                                        }
                                    >
                                        {item.cleared && (
                                            <CircleCheck className="size-4" />
                                        )}
                                    </span>
                                ) : (
                                    <Checkbox
                                        id={id}
                                        checked={item.cleared}
                                        disabled={busy !== null}
                                        onCheckedChange={(checked) =>
                                            run(
                                                id,
                                                ClearanceController.update(
                                                    item.id,
                                                ),
                                                {
                                                    data: {
                                                        cleared:
                                                            checked === true,
                                                    },
                                                },
                                            )
                                        }
                                        className="mt-0.5"
                                    />
                                )}
                                <div className="grid min-w-0 flex-1 gap-0.5">
                                    <label
                                        htmlFor={locked ? undefined : id}
                                        className="text-sm font-medium"
                                    >
                                        {item.label}
                                        <span className="sr-only">
                                            {item.cleared
                                                ? ' (done)'
                                                : ' (not done)'}
                                        </span>
                                    </label>
                                    <p className="text-xs text-muted-foreground">
                                        {item.cleared
                                            ? item.automatic ||
                                              item.cleared_by === null
                                                ? item.kind === 'assets' &&
                                                  item.cleared_at === null
                                                    ? 'Nothing held'
                                                    : `Ticked automatically${item.cleared_at ? ` · ${formatDate(item.cleared_at, dateFormat)}` : ''}`
                                                : `${item.cleared_by}${item.cleared_at ? ` · ${formatDate(item.cleared_at, dateFormat)}` : ''}`
                                            : (held ??
                                              (item.kind === 'assets'
                                                  ? 'Ticks itself when nothing is held'
                                                  : 'Not done yet'))}
                                    </p>
                                </div>
                            </li>
                        );
                    })}
                </ul>
                {!clearance.complete && clearance.missing.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        Still to do: {clearance.missing.join(', ')}.
                    </p>
                )}
            </div>
        </section>
    );
}
