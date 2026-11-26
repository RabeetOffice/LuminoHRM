<?php

use App\Models\Employee;
use App\Models\Kpi;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

it('has the performance tables and columns', function () {
    expect(Schema::getColumnListing('kpis'))->toEqualCanonicalizing([
        'id', 'name', 'description', 'weight', 'is_active', 'designation_id', 'employee_id', 'created_at', 'updated_at',
    ])
        ->and(Schema::getColumnListing('review_cycles'))->toEqualCanonicalizing([
            'id', 'name', 'period_type', 'period_key', 'period_start', 'period_end', 'due_on', 'status', 'self_review_enabled',
            'opened_by', 'closed_by', 'closed_at', 'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('reviews'))->toEqualCanonicalizing([
            'id', 'review_cycle_id', 'employee_id', 'reviewer_id', 'status', 'self_score', 'final_score', 'manager_comment',
            'send_back_note', 'skip_reason', 'skipped_from', 'self_submitted_at', 'manager_submitted_at', 'closed_at', 'closed_by',
            'created_at', 'updated_at',
        ])
        ->and(Schema::getColumnListing('review_scores'))->toEqualCanonicalizing([
            'id', 'review_id', 'kpi_id', 'name', 'description', 'weight', 'sort_order', 'self_rating', 'manager_rating',
            'self_comment', 'manager_comment', 'created_at', 'updated_at',
        ]);
});

it('keeps the config lists and the settings defaults', function () {
    expect(config('hrm.performance.review_states'))->toBe(['self_review', 'manager_review', 'ready_to_close', 'closed', 'skipped'])
        ->and(config('hrm.performance.cycle_states'))->toBe(['open', 'closed'])
        ->and(config('hrm.performance.period_types'))->toBe(['quarterly', 'monthly'])
        ->and(config('hrm.performance.default_due_days'))->toBe(14)
        ->and(collect(config('hrm.performance.labels'))->pluck('min')->all())->toBe([450, 350, 250, 150, 0])
        ->and(config('hrm.settings_groups.performance'))->toBe(['performance.manage'])
        ->and(config('hrm.defaults.performance'))->toBe(['review_period' => 'quarterly', 'self_review_enabled' => true]);
});

it('refuses a second cycle for the same period key or the same dates', function () {
    reviewCycleRow(['period_key' => '2026-Q3', 'period_type' => 'quarterly', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30']);

    expect(fn () => reviewCycleRow(['period_key' => '2026-Q3', 'period_start' => '2026-01-01', 'period_end' => '2026-01-31']))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => reviewCycleRow(['period_key' => '2026-09', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('refuses a second review for the same person in the same cycle', function () {
    $cycle = reviewCycleRow();
    $employee = Employee::factory()->create();
    reviewRow($cycle, $employee);

    expect(fn () => reviewRow($cycle, $employee))->toThrow(UniqueConstraintViolationException::class);

    reviewRow(reviewCycleRow(), $employee);

    expect(Review::query()->where('employee_id', $employee->id)->count())->toBe(2);
});

it('stores the cycle dates as bare dates and score columns as whole numbers', function () {
    $cycle = reviewCycleRow(['period_start' => '2026-07-01', 'period_end' => '2026-09-30', 'due_on' => '2026-10-14', 'period_key' => '2026-Q3']);
    $review = reviewRow($cycle, Employee::factory()->create(), ['self_score' => 412, 'final_score' => 450]);

    expect($cycle->getRawOriginal('period_start'))->toBe('2026-07-01')
        ->and($cycle->getRawOriginal('due_on'))->toBe('2026-10-14')
        ->and(ReviewCycle::query()->onDate('2026-07-01')->count())->toBe(1)
        ->and($review->fresh()->self_score)->toBe(412)
        ->and($review->fresh()->final_score)->toBe(450);
});

it('refuses a KPI with both targets or neither', function () {
    $employee = Employee::factory()->create();

    expect(fn () => Kpi::query()->create(['name' => 'X', 'weight' => 10, 'designation_id' => $employee->designation_id, 'employee_id' => $employee->id]))
        ->toThrow(LogicException::class)
        ->and(fn () => Kpi::query()->create(['name' => 'X', 'weight' => 10]))
        ->toThrow(LogicException::class);

    $kpi = kpiRow();
    $kpi->employee_id = $employee->id;

    expect(fn () => $kpi->save())->toThrow(LogicException::class)
        ->and(Kpi::query()->count())->toBe(1);
});

it('refuses to delete a KPI that a review row uses, but not an unused one', function () {
    $used = kpiRow();
    $unused = kpiRow();
    reviewRow(reviewCycleRow(), Employee::factory()->create(), ['scores' => [['kpi_id' => $used->id, 'name' => 'A', 'weight' => 10]]]);

    expect(fn () => $used->delete())->toThrow(LogicException::class)
        ->and(Kpi::query()->whereKey($used->id)->exists())->toBeTrue();

    $unused->delete();

    expect(Kpi::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('never deletes a cycle or a review', function () {
    $cycle = reviewCycleRow();
    $review = reviewRow($cycle, Employee::factory()->create(), ['scores' => []]);

    expect(fn () => $review->delete())->toThrow(LogicException::class)
        ->and(fn () => $cycle->delete())->toThrow(LogicException::class)
        ->and(Review::query()->count())->toBe(1)
        ->and(ReviewCycle::query()->count())->toBe(1);
});

it('freezes the KPI copy on a score row but lets ratings and comments change', function () {
    $kpi = kpiRow();
    $review = reviewRow(reviewCycleRow(), Employee::factory()->create(), [
        'scores' => [['kpi_id' => $kpi->id, 'name' => 'Quality', 'description' => 'Work quality', 'weight' => 30]],
    ]);
    $score = $review->scores()->sole();

    foreach (['name' => 'Changed', 'description' => 'Changed', 'weight' => 31, 'kpi_id' => null, 'review_id' => reviewRow(reviewCycleRow(), Employee::factory()->create(), ['scores' => []])->id] as $column => $value) {
        $score->{$column} = $value;

        expect(fn () => $score->save())->toThrow(LogicException::class);

        $score->refresh();
    }

    $score->update(['self_rating' => 4, 'manager_rating' => 5, 'self_comment' => 'Good', 'manager_comment' => 'Great', 'sort_order' => 3]);

    $fresh = $score->fresh();

    expect($fresh->name)->toBe('Quality')
        ->and($fresh->weight)->toBe(30)
        ->and($fresh->kpi_id)->toBe($kpi->id)
        ->and($fresh->self_rating)->toBe(4)
        ->and($fresh->manager_rating)->toBe(5)
        ->and($fresh->manager_comment)->toBe('Great');
});

it('builds the review copy from active KPIs, designation first, then personal', function () {
    $employee = Employee::factory()->create();
    kpiRow(['designation' => $employee->designation, 'name' => 'B design', 'weight' => 10]);
    kpiRow(['designation' => $employee->designation, 'name' => 'A design', 'weight' => 20]);
    kpiRow(['employee' => $employee, 'name' => 'Personal', 'weight' => 30]);
    kpiRow(['designation' => $employee->designation, 'name' => 'Off', 'is_active' => false]);
    kpiRow(['name' => 'Somebody else']);

    $review = reviewRow(reviewCycleRow(), $employee);

    expect($review->scores->pluck('name')->all())->toBe(['A design', 'B design', 'Personal'])
        ->and($review->scores->pluck('weight')->all())->toBe([20, 10, 30])
        ->and($review->scores->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($review->employee->reviews()->count())->toBe(1);
});

it('has the Employee relations', function () {
    $employee = Employee::factory()->create();
    kpiRow(['employee' => $employee]);

    expect($employee->kpis()->count())->toBe(1)
        ->and($employee->reviews()->count())->toBe(0)
        ->and(ReviewScore::query()->count())->toBe(0);
});
