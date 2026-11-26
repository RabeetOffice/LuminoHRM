<?php

namespace App\Support\Performance;

use App\Models\ReviewCycle;
use Illuminate\Validation\ValidationException;

/**
 * The flow of one review (spec 5.5). This is the only copy of the table: every review action asks canMove() after taking
 * its locks and refuses with refused() otherwise.
 *
 *   ReviewStates::canMove(ReviewStates::CLOSE, $review->status);   // true only from ready_to_close
 *   ReviewStates::assertCan(ReviewStates::CLOSE, $review->status); // or throw the refusal
 *
 * Drafts and a reviewer change keep the state (to = from). Restore goes back to the state the review was skipped from
 * (to = RESTORE_TARGET; the action reads skipped_from).
 */
final class ReviewStates
{
    public const SELF_REVIEW = 'self_review';

    public const MANAGER_REVIEW = 'manager_review';

    public const READY_TO_CLOSE = 'ready_to_close';

    public const CLOSED = 'closed';

    public const SKIPPED = 'skipped';

    /** Labels for people (plan, Global Constraints). */
    public const LABELS = [
        self::SELF_REVIEW => 'Self-review',
        self::MANAGER_REVIEW => 'With manager',
        self::READY_TO_CLOSE => 'Ready to close',
        self::CLOSED => 'Closed',
        self::SKIPPED => 'Skipped',
    ];

    /** How a state reads inside the refusal sentence ("This review is {state} and ..."). */
    private const PHRASES = [
        self::SELF_REVIEW => 'in self-review',
        self::MANAGER_REVIEW => 'with the manager',
        self::READY_TO_CLOSE => 'ready to close',
        self::CLOSED => 'closed',
        self::SKIPPED => 'skipped',
    ];

    public const REFUSED = 'This review is {state} and cannot be changed that way.';

    public const SELF_DRAFT = 'self_draft';

    public const SELF_SUBMIT = 'self_submit';

    public const WAIVE = 'waive';

    public const MANAGER_DRAFT = 'manager_draft';

    public const MANAGER_SUBMIT = 'manager_submit';

    public const SEND_BACK = 'send_back';

    public const CLOSE = 'close';

    public const REOPEN = 'reopen';

    public const SKIP = 'skip';

    public const RESTORE = 'restore';

    public const CHANGE_REVIEWER = 'change_reviewer';

    /** The "to" of restore: the review's skipped_from. */
    public const RESTORE_TARGET = '@skipped_from';

    /**
     * action => [from => to].
     *
     * @return array<string, array<string, string>>
     */
    public static function transitions(): array
    {
        $open = [self::SELF_REVIEW, self::MANAGER_REVIEW, self::READY_TO_CLOSE];

        return [
            self::SELF_DRAFT => [self::SELF_REVIEW => self::SELF_REVIEW],
            self::SELF_SUBMIT => [self::SELF_REVIEW => self::MANAGER_REVIEW],
            self::WAIVE => [self::SELF_REVIEW => self::MANAGER_REVIEW],
            self::MANAGER_DRAFT => [self::MANAGER_REVIEW => self::MANAGER_REVIEW],
            self::MANAGER_SUBMIT => [self::MANAGER_REVIEW => self::READY_TO_CLOSE],
            self::SEND_BACK => [self::READY_TO_CLOSE => self::MANAGER_REVIEW],
            self::CLOSE => [self::READY_TO_CLOSE => self::CLOSED],
            self::REOPEN => [self::CLOSED => self::READY_TO_CLOSE],
            self::SKIP => array_fill_keys($open, self::SKIPPED),
            self::RESTORE => [self::SKIPPED => self::RESTORE_TARGET],
            self::CHANGE_REVIEWER => array_combine([...$open, self::SKIPPED], [...$open, self::SKIPPED]),
        ];
    }

    public static function canMove(string $action, string $from): bool
    {
        return isset(self::transitions()[$action][$from]);
    }

    /** The state $action moves $from to (RESTORE_TARGET for restore), or null when it may not. */
    public static function target(string $action, string $from): ?string
    {
        return self::transitions()[$action][$from] ?? null;
    }

    /** The state a new review starts in. */
    public static function initial(ReviewCycle $cycle): string
    {
        return $cycle->self_review_enabled ? self::SELF_REVIEW : self::MANAGER_REVIEW;
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    public static function refused(string $state): string
    {
        return str_replace('{state}', self::PHRASES[$state] ?? $state, self::REFUSED);
    }

    /** @throws ValidationException (key "review") when $action may not move a review that is $from. */
    public static function assertCan(string $action, string $from): void
    {
        if (! self::canMove($action, $from)) {
            throw ValidationException::withMessages(['review' => self::refused($from)]);
        }
    }
}
