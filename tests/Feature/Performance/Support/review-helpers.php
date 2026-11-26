<?php

// Shared helpers for the review workflow tests (Task 3): required by each file, so they are guarded.

use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewScore;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

if (! function_exists('rvPeople')) {
    /**
     * An HR user, a manager and the manager's report (the reviewee), each with an employee record.
     *
     * @return array{hr: User, hrEmp: Employee, manager: User, managerEmp: Employee, employee: User, employeeEmp: Employee}
     */
    function rvPeople(): array
    {
        $hr = userWithRole('hr');
        $hrEmp = employeeFor($hr, ['first_name' => 'Hina', 'last_name' => 'Raza']);
        $manager = userWithRole('manager');
        $managerEmp = employeeFor($manager, ['first_name' => 'Omar', 'last_name' => 'Khan']);
        $employee = userWithRole('employee');
        $employeeEmp = employeeFor($employee, ['first_name' => 'Sara', 'last_name' => 'Ahmed', 'manager_id' => $managerEmp->id]);

        return compact('hr', 'hrEmp', 'manager', 'managerEmp', 'employee', 'employeeEmp');
    }
}

if (! function_exists('rvReview')) {
    /**
     * A review of $employee in a new open cycle (unless `cycle` is given), with three KPI lines weighted 20, 30 and 50.
     * `self` / `manager`: a list of three ratings written straight onto the lines.
     *
     * @param  array<string, mixed>  $attrs
     */
    function rvReview(Employee $employee, string $status = 'manager_review', array $attrs = []): Review
    {
        $cycle = $attrs['cycle'] ?? reviewCycleRow($attrs['cycle_attrs'] ?? []);
        $self = $attrs['self'] ?? null;
        $manager = $attrs['manager'] ?? null;
        unset($attrs['cycle'], $attrs['cycle_attrs'], $attrs['self'], $attrs['manager']);

        $review = reviewRow($cycle, $employee, array_merge([
            'status' => $status,
            'scores' => [
                ['name' => 'Quality', 'weight' => 20],
                ['name' => 'Delivery', 'weight' => 30],
                ['name' => 'Teamwork', 'weight' => 50],
            ],
        ], $attrs));

        foreach ($review->scores as $i => $score) {
            $score->forceFill([
                'self_rating' => $self[$i] ?? $score->self_rating,
                'manager_rating' => $manager[$i] ?? $score->manager_rating,
            ])->save();
        }

        return $review->refresh();
    }
}

if (! function_exists('rvRatings')) {
    /**
     * Ratings for the review's lines in order: [score id => ['rating' => r, 'comment' => c]].
     *
     * @param  list<int|null>  $values
     * @return array<int, array<string, mixed>>
     */
    function rvRatings(Review $review, array $values, ?string $comment = null): array
    {
        $out = [];

        foreach ($review->scores()->get() as $i => $score) {
            if (array_key_exists($i, $values)) {
                $out[$score->id] = ['rating' => $values[$i], 'comment' => $comment];
            }
        }

        return $out;
    }
}

if (! function_exists('rvRefusal')) {
    /** The ValidationException messages thrown by $run, keyed by field ([] when it did not throw one). */
    function rvRefusal(Closure $run): array
    {
        try {
            $run();
        } catch (ValidationException $e) {
            return array_map(fn (array $m) => $m[0], $e->errors());
        }

        return [];
    }
}

if (! function_exists('rvAudit')) {
    /** @return Collection<int, Activity> the performance audit entries, oldest first */
    function rvAudit(?string $description = null)
    {
        return Activity::query()->where('log_name', 'performance')
            ->when($description !== null, fn ($q) => $q->where('description', $description))
            ->orderBy('id')->get();
    }
}

if (! function_exists('rvScoreRows')) {
    /** @return list<array<string, mixed>> the review's lines as stored (ratings and comments) */
    function rvScoreRows(Review $review): array
    {
        return ReviewScore::query()->where('review_id', $review->id)->orderBy('sort_order')->get()
            ->map(fn (ReviewScore $s) => $s->only(['self_rating', 'self_comment', 'manager_rating', 'manager_comment']))->all();
    }
}
