import { Head, usePage } from '@inertiajs/react';
import { CalendarClock, CircleCheck, CircleX, Info, Undo2 } from 'lucide-react';
import { useState } from 'react';
import { CancelDialog } from '@/components/exit/cancel-dialog';
import { ChangeDayDialog } from '@/components/exit/change-day-dialog';
import { ClearancePanel } from '@/components/exit/clearance-panel';
import type { Clearance } from '@/components/exit/clearance-panel';
import { DecideDialog } from '@/components/exit/decide-dialog';
import { ExitFlow } from '@/components/exit/exit-flow';
import { ExitRequestCard } from '@/components/exit/exit-request-card';
import { LetterCard } from '@/components/exit/letter-card';
import type { Letter } from '@/components/exit/letter-card';
import { SettlementPanel } from '@/components/exit/settlement-panel';
import type { Settlement } from '@/components/exit/settlement-panel';
import { PageHeader } from '@/components/hrm/page-header';
import { Button } from '@/components/ui/button';
import { exitTypeLabel } from '@/lib/exit';
import type { ExitRow, FlowStep } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';
import { index as exitIndex, show } from '@/routes/exit';

type Props = {
    resignation: ExitRow;
    flow: FlowStep[];
    can: {
        decide: 'manager' | 'final' | null;
        change_day: boolean;
        cancel: boolean;
        withdraw: boolean;
    };
    /** Why an action the viewer might expect is unavailable (decide, change_day, cancel). */
    reasons: Partial<Record<'decide' | 'change_day' | 'cancel', string>>;
    own: boolean;
    status_applied?: boolean;
    /** Absent for a manager. */
    clearance?: Clearance;
    /** Absent without exit.clearance + payroll.view; null until the exit is approved. */
    settlement?: Settlement | null;
    letter?: Letter;
    status_line?: {
        applied: boolean;
        on: string | null;
        status: string;
        error: string | null;
    };
    today: string;
};

type Dialog = 'approve' | 'reject' | 'day' | 'cancel' | null;

/**
 * One exit: the request, the flow, the decision actions the viewer may take, then (HR and Admin) clearance, the
 * settlement, the experience letter and what the nightly status change did. A manager sees the request, flow and notice
 * only. Each unavailable action says why under the buttons.
 */
export default function ExitShow({
    resignation,
    flow,
    can,
    reasons,
    own,
    status_applied: statusApplied,
    clearance,
    settlement,
    letter,
    status_line: statusLine,
    today,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const [dialog, setDialog] = useState<Dialog>(null);
    const person = resignation.employee.name;
    const hasDraft =
        settlement?.exists === true && settlement.status === 'draft';
    const frozen = settlement?.exists === true && settlement.status !== 'draft';
    const anyAction = can.decide !== null || can.change_day || can.cancel;
    const notes = [reasons.decide, reasons.change_day, reasons.cancel].filter(
        (text, index, all): text is string =>
            !!text && all.indexOf(text) === index,
    );

    return (
        <>
            <Head title={`Exit · ${person}`} />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    title={person}
                    subtitle={[
                        exitTypeLabel(resignation.type),
                        resignation.employee.designation,
                        resignation.employee.department,
                        resignation.employee.code,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        anyAction && (
                            <>
                                {can.decide !== null && (
                                    <>
                                        <Button
                                            type="button"
                                            onClick={() => setDialog('approve')}
                                        >
                                            <CircleCheck />{' '}
                                            {can.decide === 'manager'
                                                ? 'Approve'
                                                : 'Approve exit'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() => setDialog('reject')}
                                        >
                                            <CircleX /> Reject
                                        </Button>
                                    </>
                                )}
                                {can.change_day && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setDialog('day')}
                                    >
                                        <CalendarClock /> Change last day
                                    </Button>
                                )}
                                {can.cancel && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setDialog('cancel')}
                                    >
                                        <Undo2 /> Cancel exit
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                {(notes.length > 0 || (own && !anyAction)) && (
                    <div
                        className="grid gap-1 text-sm text-muted-foreground"
                        role="note"
                    >
                        {own && (
                            <p className="flex items-start gap-2">
                                <Info
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                This is your own exit. Someone else decides it
                                and handles the clearance and settlement.
                            </p>
                        )}
                        {notes.map((note) => (
                            <p key={note} className="flex items-start gap-2">
                                <Info
                                    className="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {note}
                            </p>
                        ))}
                    </div>
                )}

                <div className="grid gap-6 xl:grid-cols-2">
                    <ExitRequestCard exit={resignation} />
                    <section
                        className="rounded-xl border bg-card shadow-xs"
                        aria-label="Approval flow"
                    >
                        <div className="border-b px-5 py-4">
                            <h2 className="text-base font-semibold">
                                Approval flow
                            </h2>
                        </div>
                        <div className="p-5">
                            <ExitFlow steps={flow} />
                        </div>
                    </section>
                </div>

                {statusLine && (
                    <p className="text-sm text-muted-foreground" role="status">
                        {statusLine.applied
                            ? `Status changed to ${statusLine.status} on ${formatDate(statusLine.on, dateFormat)}; the login is disabled.`
                            : 'The status changes overnight after the last working day.'}
                        {statusLine.error && (
                            <span
                                role="alert"
                                className="mt-1 block text-red-600 dark:text-red-400"
                            >
                                The last status change failed:{' '}
                                {statusLine.error}. It is retried every hour.
                            </span>
                        )}
                    </p>
                )}

                {clearance && (
                    <ClearancePanel clearance={clearance} frozen={frozen} />
                )}
                {settlement && (
                    <SettlementPanel
                        settlement={settlement}
                        resignationId={resignation.id}
                        today={today}
                    />
                )}
                {letter && (
                    <LetterCard
                        letter={letter}
                        resignationId={resignation.id}
                    />
                )}
            </div>

            {(dialog === 'approve' || dialog === 'reject') &&
                can.decide !== null && (
                    <DecideDialog
                        exitId={resignation.id}
                        mode={dialog}
                        stage={can.decide}
                        person={person}
                        lastWorkingDay={resignation.last_working_day}
                        onClose={() => setDialog(null)}
                    />
                )}
            {dialog === 'day' && (
                <ChangeDayDialog
                    exitId={resignation.id}
                    person={person}
                    current={resignation.last_working_day}
                    statusApplied={statusApplied === true}
                    today={today}
                    hasDraft={hasDraft}
                    onClose={() => setDialog(null)}
                />
            )}
            {dialog === 'cancel' && (
                <CancelDialog
                    exitId={resignation.id}
                    person={person}
                    hasDraft={hasDraft}
                    onClose={() => setDialog(null)}
                />
            )}
        </>
    );
}

ExitShow.layout = (props: { resignation?: ExitRow }) => ({
    breadcrumbs: [
        { title: 'Resignations & Clearance', href: exitIndex() },
        ...(props.resignation
            ? [
                  {
                      title: props.resignation.employee.name,
                      href: show(props.resignation.id),
                  },
              ]
            : []),
    ],
});
