import { usePage } from '@inertiajs/react';
import { StatusPill } from '@/components/hrm/status-pill';
import {
    exitStageText,
    exitStageTone,
    exitTypeLabel,
    exitTypeTone,
    noticeTone,
} from '@/lib/exit';
import type { ExitRow } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';

/** The request itself: type, the dates, the notice against what was required, the reason, and the rejection or cancel note. */
export function ExitRequestCard({ exit }: { exit: ExitRow }) {
    const dateFormat = usePage().props.app.dateFormat;

    return (
        <section
            className="rounded-xl border bg-card shadow-xs"
            aria-label="Exit request"
        >
            <div className="flex flex-wrap items-center gap-2 border-b px-5 py-4">
                <h2 className="text-base font-semibold">Request</h2>
                <StatusPill tone={exitTypeTone(exit.type)}>
                    {exitTypeLabel(exit.type)}
                </StatusPill>
                <StatusPill tone={exitStageTone(exit.status, exit.detail)}>
                    {exitStageText(exit)}
                </StatusPill>
            </div>
            <dl className="grid gap-4 p-5 text-sm sm:grid-cols-2">
                <div>
                    <dt className="text-xs text-muted-foreground">Submitted</dt>
                    <dd className="mt-0.5 font-medium">
                        {formatDate(exit.submitted_on, dateFormat)}
                        {exit.recorded_by_hr && (
                            <span className="font-normal text-muted-foreground">
                                {' '}
                                · recorded by HR
                            </span>
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Last working day
                    </dt>
                    <dd className="mt-0.5 font-medium">
                        {formatDate(exit.last_working_day, dateFormat)}
                    </dd>
                </div>
                <div className="sm:col-span-2">
                    <dt className="text-xs text-muted-foreground">Notice</dt>
                    <dd className="mt-0.5">
                        <StatusPill tone={noticeTone(exit.notice)}>
                            {exit.notice.label}
                        </StatusPill>
                    </dd>
                </div>
                <div className="sm:col-span-2">
                    <dt className="text-xs text-muted-foreground">Reason</dt>
                    <dd className="mt-0.5 break-words whitespace-pre-line">
                        {exit.reason}
                    </dd>
                </div>
                {exit.decision_note && (
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-muted-foreground">
                            Rejection note
                        </dt>
                        <dd className="mt-0.5 break-words whitespace-pre-line">
                            {exit.decision_note}
                        </dd>
                    </div>
                )}
                {exit.cancel_reason && (
                    <div className="sm:col-span-2">
                        <dt className="text-xs text-muted-foreground">
                            Reason it was cancelled
                        </dt>
                        <dd className="mt-0.5 break-words whitespace-pre-line">
                            {exit.cancel_reason}
                        </dd>
                    </div>
                )}
            </dl>
        </section>
    );
}
