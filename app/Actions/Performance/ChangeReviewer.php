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
 * HR changes who reviews (ReviewAccess::mayManage, so never on one's own review), in any state but closed (a skipped
 * review too). The new reviewer needs an active login holding performance.review-team and is never the reviewee; null
 * means "HR" (any performance.manage user). The previous reviewer loses access at once; saved drafts stay. Audit
 * `Review reviewer changed` (old / attributes: reviewer name or "HR"). Lock order: ReviewLock.
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
        return ReviewLock::run($review, fn (ReviewCycle $cycle, Review $locked) => $this->within($by, $cycle, $locked, $reviewerEmployeeId));
    }

    public function within(User $by, ReviewCycle $cycle, Review $locked, ?int $reviewerEmployeeId): Review
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

            $employee = Employee::query()->with('user')->find($reviewerEmployeeId)
                ?? throw ValidationException::withMessages(['reviewer_id' => self::UNKNOWN]);
            $newName = $employee->full_name;
            $login = $employee->user;

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
        ], $by);

        return $locked;
    }

    private function nameOf(?int $employeeId): string
    {
        if ($employeeId === null) {
            return 'HR';
        }

        return Employee::query()->whereKey($employeeId)->first()->full_name ?? 'HR';
    }
}
