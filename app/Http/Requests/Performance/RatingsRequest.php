<?php

namespace App\Http\Requests\Performance;

use App\Support\Performance\ReviewRatings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Self or manager ratings (draft or submit): ratings.{score row id}.rating (1-5 or null) and .comment (up to 1,000), and for
 * the manager side manager_comment (up to 2,000). Whether the rows belong to the review, and who may rate, is the action's
 * business (ReviewAccess, under the lock).
 */
class RatingsRequest extends FormRequest
{
    /** Access is decided by ReviewAccess in the controller and the action. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'ratings' => ['present', 'array'],
            'ratings.*' => ['array'],
            'ratings.*.rating' => ['nullable', 'integer', 'between:1,5'],
            'ratings.*.comment' => ['nullable', 'string', 'max:1000'],
            'manager_comment' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ratings.*.rating.integer' => ReviewRatings::BAD_RATING,
            'ratings.*.rating.between' => ReviewRatings::BAD_RATING,
            'ratings.*.comment.max' => ReviewRatings::COMMENT_TOO_LONG,
            'manager_comment.max' => ReviewRatings::OVERALL_TOO_LONG,
        ];
    }

    /** @return array<int|string, array<string, mixed>> */
    public function ratings(): array
    {
        $ratings = [];

        foreach ((array) $this->input('ratings', []) as $id => $row) {
            $row = (array) $row;
            $clean = [];

            if (array_key_exists('rating', $row)) {
                $clean['rating'] = $row['rating'] === null || $row['rating'] === '' ? null : (int) $row['rating'];
            }

            if (array_key_exists('comment', $row)) {
                $clean['comment'] = $row['comment'] === null ? null : (string) $row['comment'];
            }

            $ratings[$id] = $clean;
        }

        return $ratings;
    }

    /** Whether the request carries the manager_comment key at all: a missing key keeps the saved overall comment. */
    public function hasOverallComment(): bool
    {
        return $this->has('manager_comment');
    }

    public function overallComment(): ?string
    {
        $comment = $this->input('manager_comment');

        return is_string($comment) ? $comment : null;
    }
}
