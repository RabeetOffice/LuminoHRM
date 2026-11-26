<?php

use App\Actions\Performance\OpenReviewCycle;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\ReviewCycle;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

// Helpers shared by the Task 2 cycle tests (open, add people, close, eligibility, lock order).

if (! function_exists('cyPerson')) {
    /** An active employee with a designation KPI (pass 'kpi' => false for none), joined in 2024 unless overridden. */
    function cyPerson(array $attrs = []): Employee
    {
        $withKpi = $attrs['kpi'] ?? true;
        unset($attrs['kpi']);

        $employee = Employee::factory()->create(array_merge(['joining_date' => '2024-01-15', 'status' => 'active'], $attrs));

        if ($withKpi) {
            kpiRow(['designation' => Designation::query()->findOrFail($employee->designation_id), 'name' => 'Delivery', 'weight' => 40]);
        }

        return $employee;
    }

    function cyOpen(?User $by = null, string $key = '2026-Q3', ?string $due = null): ReviewCycle
    {
        return app(OpenReviewCycle::class)->handle($by ?? userWithRole('hr'), $key, $due);
    }

    /** The first validation message $run raises, as "key: message". */
    function cyRefusal(Closure $run): string
    {
        try {
            $run();
        } catch (ValidationException $e) {
            $key = array_key_first($e->errors());

            return $key.': '.$e->errors()[$key][0];
        }

        throw new RuntimeException('Expected a refusal.');
    }

    function cyAudit(string $description): Activity
    {
        return Activity::query()->where('log_name', 'performance')->where('description', $description)->latest('id')->firstOrFail();
    }
}
