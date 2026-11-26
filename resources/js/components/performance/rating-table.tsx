import { RatingInput } from '@/components/performance/rating-input';
import InputError from '@/components/input-error';
import { Textarea } from '@/components/ui/textarea';
import { ratingText } from '@/lib/review-view';
import type { RatingValue, ReviewColumns } from '@/lib/review-view';

/** A KPI line as the review page sends it. A side the viewer may not see has no keys at all. */
export type ReviewRow = {
    id: number;
    name: string;
    description: string | null;
    weight: number;
    self_rating?: number | null;
    self_comment?: string | null;
    manager_rating?: number | null;
    manager_comment?: string | null;
};

type Side = 'self' | 'manager';

type Props = {
    rows: ReviewRow[];
    columns: ReviewColumns;
    /** The values being edited, for whichever side is editable. */
    values?: Record<number, RatingValue>;
    onChange?: (id: number, patch: Partial<RatingValue>) => void;
    /** The server's message per KPI (`ratings.<id>.rating` / `.comment`), by score id. */
    errors?: Record<number, string>;
    disabled?: boolean;
};

const TITLES: Record<Side, string> = { self: 'Self', manager: 'Manager' };

/** One side of one KPI: the rating control and comment when editable, or what was written when read-only. */
function Cell({
    side,
    row,
    mode,
    value,
    onChange,
    error,
    disabled,
}: {
    side: Side;
    row: ReviewRow;
    mode: 'read' | 'edit';
    value?: RatingValue;
    onChange?: (patch: Partial<RatingValue>) => void;
    error?: string;
    disabled?: boolean;
}) {
    const id = `${side}-${row.id}`;
    const rating = side === 'self' ? row.self_rating : row.manager_rating;
    const comment = side === 'self' ? row.self_comment : row.manager_comment;

    if (mode === 'edit') {
        return (
            <div className="grid gap-2">
                <p className="text-xs font-medium text-muted-foreground">
                    {TITLES[side]}
                </p>
                <RatingInput
                    label={`${TITLES[side]} rating for ${row.name}`}
                    value={value?.rating ?? null}
                    onChange={(rating) => onChange?.({ rating })}
                    disabled={disabled}
                    invalid={Boolean(error)}
                    describedBy={error ? `${id}-error` : undefined}
                />
                <Textarea
                    id={`${id}-comment`}
                    aria-label={`${TITLES[side]} comment for ${row.name}`}
                    value={value?.comment ?? ''}
                    onChange={(e) => onChange?.({ comment: e.target.value })}
                    maxLength={1000}
                    rows={2}
                    placeholder="Add a comment (optional)"
                    disabled={disabled}
                    className="min-h-16"
                />
                <InputError id={`${id}-error`} role="alert" message={error} />
            </div>
        );
    }

    return (
        <div className="grid content-start gap-1.5">
            <p className="text-xs font-medium text-muted-foreground">
                {TITLES[side]}
            </p>
            <p className="text-sm font-medium">{ratingText(rating)}</p>
            {comment ? (
                <p className="text-sm break-words whitespace-pre-wrap text-muted-foreground">
                    {comment}
                </p>
            ) : null}
        </div>
    );
}

/**
 * The KPI lines with a "Self" and a "Manager" side. A column the viewer may not see (columns.x === 'hidden') is not drawn
 * at all. On a wide screen the sides sit next to each other under the KPI's name; on a phone they stack.
 */
export function RatingTable({
    rows,
    columns,
    values,
    onChange,
    errors,
    disabled,
}: Props) {
    const sides = (['self', 'manager'] as const).filter(
        (side) => columns[side] !== 'hidden',
    );

    return (
        <ul className="grid gap-3" aria-label="KPI ratings">
            {rows.map((row) => (
                <li
                    key={row.id}
                    className="grid gap-3 rounded-lg border bg-card p-4"
                >
                    <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                        <div className="min-w-0">
                            <h3 className="text-sm font-semibold break-words">
                                {row.name}
                            </h3>
                            {row.description ? (
                                <p className="mt-0.5 text-sm break-words text-muted-foreground">
                                    {row.description}
                                </p>
                            ) : null}
                        </div>
                        <span className="shrink-0 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground">
                            Weight {row.weight}
                        </span>
                    </div>

                    {sides.length > 0 && (
                        <div
                            className={
                                sides.length > 1
                                    ? 'grid gap-4 sm:grid-cols-2 sm:gap-6'
                                    : 'grid gap-4'
                            }
                        >
                            {sides.map((side) => (
                                <Cell
                                    key={side}
                                    side={side}
                                    row={row}
                                    mode={columns[side] as 'read' | 'edit'}
                                    value={values?.[row.id]}
                                    onChange={(patch) =>
                                        onChange?.(row.id, patch)
                                    }
                                    error={errors?.[row.id]}
                                    disabled={disabled}
                                />
                            ))}
                        </div>
                    )}
                </li>
            ))}
        </ul>
    );
}
