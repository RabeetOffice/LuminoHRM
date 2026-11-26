<?php

namespace App\Actions\Performance;

use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes an open cycle's due date (coordinator ruling on open item 9: HR may change it while the cycle is open). The date
 * must be a real date on or after the period end; it only drives the "overdue" badge. One transaction: the cycle row FOR
 * UPDATE, the status re-checked under it, audited as "Cycle due date changed" with the old and new values.
 */
class ChangeCycleDueDate
{
    public const CLOSED = 'This cycle is closed.';

    public static function unchangedMessage(string $dueOn): string
    {
        return 'The due date is already '.OpenReviewCycle::displayDate($dueOn).'.';
    }

    public function handle(User $by, ReviewCycle $cycle, ?string $dueOn): ReviewCycle
    {
        if ($dueOn === null || trim($dueOn) === '') {
            throw ValidationException::withMessages(['due_on' => OpenReviewCycle::BAD_DATE]);
        }

        return DB::transaction(function () use ($by, $cycle, $dueOn): ReviewCycle {
            $current = ReviewCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== 'open') {
                throw ValidationException::withMessages(['cycle' => self::CLOSED]);
            }

            $new = OpenReviewCycle::dueDate($dueOn, $current->period_end->format('Y-m-d'));
            $old = $current->due_on->format('Y-m-d');

            if ($new === $old) {
                throw ValidationException::withMessages(['due_on' => self::unchangedMessage($old)]);
            }

            $current->forceFill(['due_on' => $new])->save();

            Audit::record('performance', 'Cycle due date changed', $current, [
                'old' => ['due_on' => $old],
                'attributes' => ['due_on' => $new],
            ], $by);

            return $current;
        }, 3);
    }
}
