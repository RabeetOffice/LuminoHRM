import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { ReviewRow } from '@/components/performance/rating-table';
import { generalErrors, ratingsPayload } from '@/lib/review-view';
import type { RatingValue } from '@/lib/review-view';

type Side = 'self' | 'manager';

type Target = { url: string; method: 'put' | 'post' };

type FormData = {
    ratings: Record<number, RatingValue>;
    manager_comment: string;
};

/** What the form starts with: the saved draft of the side being edited. */
function initialRatings(
    rows: ReviewRow[],
    side: Side,
): Record<number, RatingValue> {
    return Object.fromEntries(
        rows.map((row) => [
            row.id,
            {
                rating:
                    (side === 'self' ? row.self_rating : row.manager_rating) ??
                    null,
                comment:
                    (side === 'self'
                        ? row.self_comment
                        : row.manager_comment) ?? '',
            },
        ]),
    );
}

/**
 * The state of a self or manager rating form: the ratings (and the overall comment for the manager), a draft save and a
 * submit, and every message the server answers with: the per-KPI ones by KPI, the rest as one list for the page's alert.
 * The server's refusals are never swallowed: whatever no field shows is in `general`.
 */
export function useRatingsForm(
    rows: ReviewRow[],
    side: Side,
    overallComment: string | null,
) {
    const form = useForm<FormData>({
        ratings: initialRatings(rows, side),
        manager_comment: overallComment ?? '',
    });
    const [saved, setSaved] = useState(false);
    const errors = form.errors as Record<string, string>;

    const rowErrors: Record<number, string> = {};

    for (const [key, message] of Object.entries(errors)) {
        const match = /^ratings\.(\d+)\./.exec(key);

        if (match) {
            rowErrors[Number(match[1])] ??= message;
        }
    }

    const change = (id: number, patch: Partial<RatingValue>) => {
        setSaved(false);
        form.setData('ratings', {
            ...form.data.ratings,
            [id]: { ...form.data.ratings[id], ...patch },
        });
    };

    const send = (target: Target, onSuccess?: () => void) => {
        form.transform(
            (current) =>
                ({
                    ratings: ratingsPayload(current.ratings),
                    ...(side === 'manager'
                        ? {
                              manager_comment:
                                  current.manager_comment.trim() === ''
                                      ? null
                                      : current.manager_comment,
                          }
                        : {}),
                }) as unknown as FormData,
        );
        form.submit(target, {
            preserveScroll: true,
            onStart: () => setSaved(false),
            onSuccess: () => {
                setSaved(true);
                onSuccess?.();
            },
        });
    };

    return {
        form,
        values: form.data.ratings,
        change,
        send,
        rowErrors,
        overallError: errors.manager_comment,
        general: generalErrors(errors),
        saved,
    };
}
