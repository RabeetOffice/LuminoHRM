/**
 * The review page's layout rules as pure functions. The server decides what a viewer may see (a side the viewer may not
 * see is missing from the props); these decide how the page lays out what it got: which side columns show, and whether a
 * column can be edited. Nothing here widens access: a column the data does not carry is never drawn with data.
 */

export type ReviewRole = 'reviewee' | 'reviewer' | 'hr';

/** The viewer's own actions, as the server worked them out. */
export type ReviewCan = {
    save_self: boolean;
    submit_self: boolean;
    save_manager: boolean;
    submit_manager: boolean;
    send_back: boolean;
    close: boolean;
    reopen: boolean;
    skip: boolean;
    restore: boolean;
    waive: boolean;
    change_reviewer: boolean;
};

export type ColumnMode = 'hidden' | 'read' | 'edit';

export type ReviewColumns = {
    self: ColumnMode;
    manager: ColumnMode;
};

type ColumnInput = {
    role: ReviewRole;
    state: string;
    can: ReviewCan;
    /** The cycle asks people to rate themselves first. */
    selfEnabled: boolean;
    /** The rows carry the self side / the manager side (the server leaves a side out when the viewer may not see it). */
    hasSelfData: boolean;
    hasManagerData: boolean;
};

/**
 * Which of the "Self" and "Manager" columns to draw, and whether each is read-only or editable.
 *
 * A side is editable only when the server said the viewer may save it. A side the rows do not carry is hidden, so the
 * employee before close never gets a manager column (not even an empty one) and the reviewer waiting for the employee gets
 * neither. A cycle without self-reviews hides the self column, except for HR, who read whatever is there.
 */
export function reviewColumns(input: ColumnInput): ReviewColumns {
    const { can, hasSelfData, hasManagerData, selfEnabled, role } = input;

    const self: ColumnMode = can.save_self
        ? 'edit'
        : hasSelfData && (selfEnabled || role === 'hr')
          ? 'read'
          : 'hidden';

    const manager: ColumnMode = can.save_manager
        ? 'edit'
        : hasManagerData
          ? 'read'
          : 'hidden';

    return { self, manager };
}

/** The "n of 5" read-out of a rating, or a dash when nothing was rated. */
export function ratingText(rating: number | null | undefined): string {
    return rating === null || rating === undefined ? '–' : `${rating} of 5`;
}

/**
 * Messages in an Inertia error bag that no field on the page shows: a refused action ("This review is closed ..."), a
 * stale state, "Rate every KPI ...". The per-KPI keys (`ratings.<id>.rating`) and the overall comment show under their
 * own field; everything else goes in the alert at the top, so no refusal is lost.
 */
export function generalErrors(errors: Record<string, string>): string[] {
    return Object.entries(errors)
        .filter(
            ([key]) => !key.startsWith('ratings.') && key !== 'manager_comment',
        )
        .map(([, message]) => message);
}

export type RatingValue = { rating: number | null; comment: string };

/** The ratings in the shape the routes take: { [score id]: { rating, comment } }; a blank comment is sent as null. */
export function ratingsPayload(
    values: Record<number, RatingValue>,
): Record<number, { rating: number | null; comment: string | null }> {
    return Object.fromEntries(
        Object.entries(values).map(([id, row]) => [
            Number(id),
            {
                rating: row.rating,
                comment: row.comment.trim() === '' ? null : row.comment,
            },
        ]),
    );
}
