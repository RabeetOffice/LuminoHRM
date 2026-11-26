<?php

namespace App\Actions\Performance;

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\Performance\ReviewEligibility;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds people to an open cycle (Add people on the cycle page). One transaction: the cycle row FOR UPDATE first, then each
 * person is judged under that lock with the rules used at opening and their KPIs as they are now. A refused person does not
 * stop the others; the reason comes back per person. Nothing is written when nobody can be added.
 */
class AddReviewPeople
{
    public const CLOSED = 'This cycle is closed.';

    public const NONE = 'Choose at least one person.';

    public const NOT_FOUND = 'That person was not found.';

    public static function alreadyInMessage(string $name): string
    {
        return "{$name} is already in this cycle.";
    }

    public static function noKpisMessage(string $name): string
    {
        return "{$name} has no active KPIs.";
    }

    public static function ineligibleMessage(string $name): string
    {
        return "{$name} cannot be added: not active, or joined after the period ended.";
    }

    /**
     * @param  array<int, mixed>  $employeeIds
     * @return array{added: int, refused: array<int, string>} refused: employee id => reason
     */
    public function handle(User $by, ReviewCycle $cycle, array $employeeIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn ($id) => is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0,
            $employeeIds,
        ), fn (int $id) => $id > 0)));

        if ($ids === []) {
            throw ValidationException::withMessages(['employee_ids' => self::NONE]);
        }

        return DB::transaction(function () use ($by, $cycle, $ids): array {
            $current = ReviewCycle::query()->whereKey($cycle->getKey())->lockForUpdate()->firstOrFail();

            if ($current->status !== 'open') {
                throw ValidationException::withMessages(['cycle' => self::CLOSED]);
            }

            $periodEnd = $current->period_end->format('Y-m-d');
            $found = ReviewEligibility::forPeriod($periodEnd, $current->id, $ids);
            $eligible = $found['eligible']->keyBy('id');
            $noKpis = $found['no_kpis']->keyBy('id');

            $people = Employee::query()->whereKey($ids)->get()->keyBy('id');
            $already = Review::query()->where('review_cycle_id', $current->id)->whereIn('employee_id', $ids)->pluck('employee_id')->flip();

            $refused = [];
            $added = [];

            foreach ($ids as $id) {
                $person = $people->get($id);

                if ($person === null) {
                    $refused[$id] = self::NOT_FOUND;
                } elseif ($already->has($id)) {
                    $refused[$id] = self::alreadyInMessage($person->full_name);
                } elseif ($noKpis->has($id)) {
                    $refused[$id] = self::noKpisMessage($person->full_name);
                } elseif (! $eligible->has($id)) {
                    $refused[$id] = self::ineligibleMessage($person->full_name);
                } else {
                    $added[] = $person;
                }
            }

            if ($added === []) {
                throw ValidationException::withMessages(['employee_ids' => implode(' ', $refused)]);
            }

            foreach ($added as $employee) {
                OpenReviewCycle::createReview($current, $employee, $found['kpis'][$employee->id]);
            }

            Audit::record('performance', 'Review cycle people added', $current, [
                'added' => array_map(fn (Employee $e) => ['id' => $e->id, 'name' => $e->full_name], $added),
                'refused' => count($refused),
            ], $by);

            return ['added' => count($added), 'refused' => $refused];
        }, 3);
    }
}
