import { usePage } from '@inertiajs/react';
import { Download, FileText } from 'lucide-react';
import ExitDocumentController from '@/actions/App/Http/Controllers/Exits/ExitDocumentController';
import { ActionError, useExitAction } from '@/components/exit/use-exit-action';
import { StatusPill } from '@/components/hrm/status-pill';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format-date';

export type Letter = {
    issued: boolean;
    issued_on: string | null;
    issued_by: string | null;
    text: string | null;
    url: string | null;
    can_issue: boolean;
};

/**
 * The experience letter (HR and Admin): issue it from the template in Settings, Exit, see the text that was issued and
 * download the PDF. Issuing again replaces the text. A refusal (not an approved exit, your own letter) shows above the card.
 */
export function LetterCard({
    letter,
    resignationId,
}: {
    letter: Letter;
    resignationId: number;
}) {
    const dateFormat = usePage().props.app.dateFormat;
    const { busy, error, run } = useExitAction();

    return (
        <section
            className="rounded-xl border bg-card shadow-xs"
            aria-label="Experience letter"
        >
            <div className="flex flex-wrap items-center gap-2 border-b px-5 py-4">
                <h2 className="text-base font-semibold">Experience letter</h2>
                <StatusPill tone={letter.issued ? 'green' : 'grey'}>
                    {letter.issued ? 'Issued' : 'Not issued'}
                </StatusPill>
            </div>
            <div className="grid gap-4 p-5">
                <ActionError messages={error} />
                {letter.issued ? (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Issued
                            {letter.issued_by ? ` by ${letter.issued_by}` : ''}
                            {letter.issued_on
                                ? ` on ${formatDate(letter.issued_on, dateFormat)}`
                                : ''}
                            . The employee can download it from My Resignation.
                        </p>
                        {letter.text && (
                            <pre className="max-h-64 overflow-auto rounded-lg bg-muted px-4 py-3 font-sans text-sm break-words whitespace-pre-wrap">
                                {letter.text}
                            </pre>
                        )}
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Issue the letter to give the employee a signed-off
                        statement of their employment. The text comes from the
                        template in Settings &gt; Exit.
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant={letter.issued ? 'outline' : 'default'}
                        disabled={!letter.can_issue || busy !== null}
                        onClick={() =>
                            run(
                                'issue',
                                ExitDocumentController.issue(resignationId),
                            )
                        }
                    >
                        <FileText />{' '}
                        {letter.issued ? 'Issue again' : 'Issue letter'}
                    </Button>
                    {letter.url && (
                        <Button variant="outline" asChild>
                            <a href={letter.url}>
                                <Download /> Download PDF
                            </a>
                        </Button>
                    )}
                </div>
                {!letter.can_issue && (
                    <p className="text-sm text-muted-foreground">
                        You cannot issue this letter: it is not available for
                        your own exit, or for an Admin or HR person unless you
                        are an Admin.
                    </p>
                )}
            </div>
        </section>
    );
}
