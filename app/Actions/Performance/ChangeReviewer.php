<?php

namespace App\Actions\Performance;

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Performance\ReviewAccess;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewStates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * HR changes who reviews (ReviewAccess::mayManage: never on one's own review, except for the sole active
 * performance.manage user, and then the audit entry says own_review: true), in any state but closed (a skipped review
 * too). The new reviewer needs an active login holding performance.review-team and is never the reviewee; null means "HR"
 * (any performance.manage user). The previous reviewer loses access at once; saved drafts stay. Audit
 * `Review reviewer changed` (old / attributes: reviewer name or "HR", own_review). Lock order: ReviewLock.
 *
 * The new reviewer's employee and user rows are read with a shared lock, taken by ReviewLock BEFORE the cycle and review
 * rows (never an employee or user lock after a review row). A plain read inside the transaction would come from the
 * snapshot fixed by ReviewLock's first read and could miss a login disabled, or an employee removed, while this call
 * waited for the review lock. Both rows are then held until commit, so the check cannot go stale before the update.
 */
class ChangeReviewer
{
    public const CLOSED = "A closed review's reviewer cannot be changed.";

    public const SELF = 'Nobody can review themselves.';

    public const UNKNOWN = 'Choose a reviewer.';

    public static function noAccessMessage(string $name): string
    {
        return "{$name} cannot review this person: they need an active login with review access.";
    }

    public static function unchangedMessage(string $name): string
    {
        return "The reviewer is already {$name}.";
    }

    public function handle(User $by, Review $review, ?int $reviewerEmployeeId): Review
    {
        $facts = null;

        return ReviewLock::run(
            $review,
            fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $reviewerEmployeeId, $facts),
            $by,
            function () use (&$facts, $reviewerEmployeeId) {
                $facts = $reviewerEmployeeId === null ? null : $this->reviewerFacts($reviewerEmployeeId, lock: true);
            },
        );
    }

    /**
     * @param  array{employee: ?Employee, login: ?User}|null  $facts  the new reviewer's rows as handle() locked them; read plainly when null (a direct call)
     */
    public function within(User $by, ReviewCycle $cycle, Review $locked, ?int $reviewerEmployeeId, ?array $facts = null): Review
    {
        ReviewLock::assertCycleOpen($cycle);

        if (! ReviewAccess::mayManage($by, $locked)) {
            throw new AuthorizationException('Only HR can change who reviews, and never on their own review.');
        }

        if ($locked->status === ReviewStates::CLOSED) {
            throw ValidationException::withMessages(['reviewer_id' => self::CLOSED]);
        }

        ReviewStates::assertCan(ReviewStates::CHANGE_REVIEWER, $locked->status);

        $current = $locked->reviewer_id === null ? null : (int) $locked->reviewer_id;
        $oldName = $this->nameOf($current);
        $newName = 'HR';

        if ($reviewerEmployeeId !== null) {
            if ($reviewerEmployeeId === (int) $locked->employee_id) {
                throw ValidationException::withMessages(['reviewer_id' => self::SELF]);
            }

            $facts ??= $this->reviewerFacts($reviewerEmployeeId, lock: false);
            $employee = $facts['employee'] ?? throw ValidationException::withMessages(['reviewer_id' => self::UNKNOWN]);
            $newName = $employee->full_name;
            $login = $facts['login'];

            if ($reviewerEmployeeId !== $current
                && ($login === null || ! $login->is_active || ! $login->can('performance.review-team'))) {
                throw ValidationException::withMessages(['reviewer_id' => self::noAccessMessage($newName)]);
            }
        }

        if ($reviewerEmployeeId === $current) {
            throw ValidationException::withMessages(['reviewer_id' => self::unchangedMessage($newName)]);
        }

        $locked->forceFill(['reviewer_id' => $reviewerEmployeeId])->save();

        ReviewRatings::audit('Review reviewer changed', $locked, $cycle, [
            'old' => ['reviewer' => $oldName],
            'attributes' => ['reviewer' => $newName],
            'own_review' => ReviewAccess::isReviewee($by, $locked),
        ], $by);

        return $locked;
    }

    /** @return array{employee: ?Employee, login: ?User} */
    private function reviewerFacts(int $employeeId, bool $lock): array
    {
        $employee = Employee::query()->whereKey($employeeId);
        $login = User::query()->where('employee_id', $employeeId);

        if ($lock) {
            $employee->sharedLock();
            $login->sharedLock();
        }

        return ['employee' => $employee->first(), 'login' => $login->first()];
    }

    private function nameOf(?int $employeeId): string
    {
        if ($employeeId === null) {
            return 'HR';
        }

        return Employee::query()->whereKey($employeeId)->first()->full_name ?? 'HR';
    }
}
