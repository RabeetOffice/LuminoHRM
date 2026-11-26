import { formatScore } from '@/lib/performance';

export type ReviewScoreProps = {
    self_score?: number;
    manager_score?: number;
    final_score?: number;
    label?: string;
};

type Props = {
    score: ReviewScoreProps;
    state: string;
};

function Stat({
    title,
    value,
    note,
}: {
    title: string;
    value: string;
    note?: string;
}) {
    return (
        <div className="grid content-start gap-0.5">
            <dt className="text-xs font-medium text-muted-foreground">
                {title}
            </dt>
            <dd className="text-2xl font-semibold tracking-tight tabular-nums">
                {value}
            </dd>
            {note ? (
                <p className="text-sm text-muted-foreground">{note}</p>
            ) : null}
        </div>
    );
}

/**
 * The score card. It draws only the figures the server sent: the self score once submitted, the manager score as a preview
 * while HR or the reviewer look at a review that is ready to close, and the final score with its label once closed.
 */
export function ScoreSummary({ score, state }: Props) {
    const final = score.final_score;
    const manager = score.manager_score;

    if (score.self_score === undefined && manager === undefined) {
        return null;
    }

    return (
        <dl className="grid gap-4 rounded-lg border bg-card p-4 sm:grid-cols-3">
            {score.self_score !== undefined && (
                <Stat
                    title="Self score"
                    value={formatScore(score.self_score)}
                />
            )}
            {final !== undefined ? (
                <Stat
                    title="Final score"
                    value={formatScore(final)}
                    note={score.label}
                />
            ) : manager !== undefined ? (
                <Stat
                    title={
                        state === 'ready_to_close'
                            ? 'Manager score (what closing will record)'
                            : 'Manager score'
                    }
                    value={formatScore(manager)}
                    note={score.label}
                />
            ) : null}
        </dl>
    );
}
