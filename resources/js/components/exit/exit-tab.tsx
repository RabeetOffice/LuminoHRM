import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, DoorOpen } from 'lucide-react';
import { useState } from 'react';
import { RecordExitDialog } from '@/components/exit/record-exit-dialog';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import {
    exitStageText,
    exitStageTone,
    exitTypeLabel,
    exitTypeTone,
    settlementNetText,
    settlementStatusLabel,
    settlementStatusTone,
} from '@/lib/exit';
import type { ExitRow } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';

/** What the Exit tab of the profile receives (EmployeeProfileResource::exit). */
export type ProfileExit = {
    current: ExitRow | null;
    history: ExitRow[];
    can_record: boolean;
    /** The attendance day "Y-m-d" and the exit types, for the record dialog. */
    today: string;
    types: string[];
};

function ExitRowItem({ row }: { row: ExitRow }) {
    const dateFormat = usePage().props.app.dateFormat;

    return (
        <li className="grid gap-2 px-4 py-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <StatusPill tone={exitTypeTone(row.type)}>
                    {exitTypeLabel(row.type)}
                </StatusPill>
                <StatusPill tone={exitStageTone(row.status, row.detail)}>
                    {exitStageText(row)}
                </StatusPill>
                {row.settlement && (
                    <StatusPill tone={settlementStatusTone(row.settlement)}>
                        Settlement{' '}
                        {settlementStatusLabel(row.settlement).toLowerCase()}
                    </StatusPill>
                )}
            </div>
            <p className="text-muted-foreground">
                Submitted {formatDate(row.submitted_on, dateFormat)} · Last
                working day {formatDate(row.last_working_day, dateFormat)} ·{' '}
                {row.notice.label}
            </p>
            {typeof row.net === 'number' && (
                <p className="font-medium tabular-nums">
                    {settlementNetText(row.net)}
                </p>
            )}
            {row.decision_note && (
                <p className="break-words whitespace-pre-line">
                    Note: {row.decision_note}
                </p>
            )}
            {row.cancel_reason && (
                <p className="break-words whitespace-pre-line">
                    Cancelled: {row.cancel_reason}
                </p>
            )}
            <div>
                <Link
                    href={row.url}
                    className="inline-flex items-center gap-1 text-sm font-medium text-brand-ink hover:underline"
                >
                    Open <ArrowRight aria-hidden="true" className="size-3.5" />
                </Link>
            </div>
        </li>
    );
}

/**
 * The Exit tab of a profile: the open exit and the history. HR and Admin can record an exit from here when the person has
 * none open; settlement figures appear only with payroll.view (the server sends them to nobody else).
 */
export function ExitTab({
    exit,
    person,
}: {
    exit: ProfileExit;
    person: { id: number; code: string; name: string };
}) {
    const [recording, setRecording] = useState(false);
    const empty = exit.current === null && exit.history.length === 0;

    return (
        <div className="grid gap-5">
            {exit.can_record && (
                <div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => setRecording(true)}
                    >
                        <DoorOpen /> Record exit
                    </Button>
                </div>
            )}
            {empty ? (
                <p className="text-sm text-muted-foreground">
                    No exit has been recorded for this person.
                </p>
            ) : (
                <>
                    {exit.current && (
                        <section className="grid gap-2">
                            <h3 className="text-sm font-semibold">Open exit</h3>
                            <ul role="list" className="rounded-lg border">
                                <ExitRowItem row={exit.current} />
                            </ul>
                        </section>
                    )}
                    {exit.history.length > 0 && (
                        <section className="grid gap-2">
                            <h3 className="text-sm font-semibold">History</h3>
                            <ul
                                role="list"
                                className="divide-y rounded-lg border"
                            >
                                {exit.history.map((row) => (
                                    <ExitRowItem key={row.id} row={row} />
                                ))}
                            </ul>
                        </section>
                    )}
                </>
            )}
            {recording && (
                <RecordExitDialog
                    today={exit.today}
                    types={exit.types}
                    person={person}
                    onClose={() => setRecording(false)}
                />
            )}
        </div>
    );
}
