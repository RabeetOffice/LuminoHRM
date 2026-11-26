<?php

use App\Actions\Performance\AddReviewPeople;
use App\Actions\Performance\OpenReviewCycle;
use App\Models\Designation;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

function apAdd(ReviewCycle $cycle, array $ids): array
{
    return app(AddReviewPeople::class)->handle(userWithRole('hr'), $cycle, $ids);
}

it('adds a person with the KPIs they have now, including one added after the open', function () {
    cyPerson();
    $late = cyPerson(['kpi' => false]);
    $cycle = cyOpen();
    $kpi = kpiRow(['employee' => $late, 'name' => 'Added later', 'weight' => 25]);

    $result = apAdd($cycle, [$late->id]);

    $review = Review::query()->where('employee_id', $late->id)->sole();
    expect($result)->toBe(['added' => 1, 'refused' => []])
        ->and($review->status)->toBe('self_review')
        ->and(ReviewScore::query()->where('review_id', $review->id)->pluck('kpi_id')->all())->toBe([$kpi->id])
        ->and(ReviewScore::query()->where('review_id', $review->id)->value('weight'))->toBe(25);
});

it('copies the manager as reviewer and starts at manager_review when the cycle has self-review off', function () {
    $boss = cyPerson();
    $person = cyPerson(['manager_id' => $boss->id]);
    $cycle = reviewCycleRow(['self_review_enabled' => false, 'period_end' => '2026-09-30', 'period_start' => '2026-07-01', 'period_key' => '2026-Q3', 'period_type' => 'quarterly']);

    apAdd($cycle, [$person->id]);

    $review = Review::query()->sole();
    expect($review->reviewer_id)->toBe($boss->id)->and($review->status)->toBe('manager_review');
});

it('refuses an existing review, a person with no KPIs and an ineligible one with exact messages while adding the rest', function () {
    $in = cyPerson(['first_name' => 'Ali', 'last_name' => 'Khan']);
    $cycle = cyOpen();
    $ok = cyPerson();
    $noKpis = cyPerson(['first_name' => 'Sara', 'last_name' => 'Malik', 'kpi' => false]);
    $left = cyPerson(['first_name' => 'Omar', 'last_name' => 'Raza', 'status' => 'resigned']);
    $late = cyPerson(['first_name' => 'Hina', 'last_name' => 'Ali', 'joining_date' => '2026-10-01']);

    $result = apAdd($cycle, [$in->id, $ok->id, $noKpis->id, $left->id, $late->id]);

    expect($result['added'])->toBe(1)
        ->and($result['refused'])->toBe([
            $in->id => 'Ali Khan is already in this cycle.',
            $noKpis->id => 'Sara Malik has no active KPIs.',
            $left->id => 'Omar Raza cannot be added: not active, or joined after the period ended.',
            $late->id => 'Hina Ali cannot be added: not active, or joined after the period ended.',
        ])
        ->and(Review::query()->where('review_cycle_id', $cycle->id)->count())->toBe(2);

    $audit = cyAudit('Review cycle people added');
    expect($audit->subject_id)->toBe($cycle->id)
        ->and($audit->properties['added'])->toBe([['id' => $ok->id, 'name' => $ok->full_name]])
        ->and($audit->properties['refused'])->toBe(4);
});

it('writes nothing when nobody can be added', function () {
    $in = cyPerson(['first_name' => 'Ali', 'last_name' => 'Khan']);
    $cycle = cyOpen();
    $noKpis = cyPerson(['first_name' => 'Sara', 'last_name' => 'Malik', 'kpi' => false]);

    expect(cyRefusal(fn () => apAdd($cycle, [$in->id, $noKpis->id])))->toBe('employee_ids: Ali Khan is already in this cycle. Sara Malik has no active KPIs.')
        ->and(cyRefusal(fn () => apAdd($cycle, [])))->toBe('employee_ids: '.AddReviewPeople::NONE)
        ->and(Review::count())->toBe(1)
        ->and(Activity::query()->where('description', 'Review cycle people added')->count())->toBe(0);
});

it('refuses a closed cycle', function () {
    $person = cyPerson();
    $cycle = reviewCycleRow(['status' => 'closed']);

    expect(cyRefusal(fn () => apAdd($cycle, [$person->id])))->toBe('cycle: '.AddReviewPeople::CLOSED)
        ->and(Review::count())->toBe(0);
});

it('refuses on a stale model whose cycle closed meanwhile', function () {
    $person = cyPerson();
    $cycle = reviewCycleRow();
    $stale = ReviewCycle::query()->findOrFail($cycle->id);
    ReviewCycle::query()->whereKey($cycle->id)->update(['status' => 'closed']);

    expect($stale->status)->toBe('open')
        ->and(cyRefusal(fn () => apAdd($stale, [$person->id])))->toBe('cycle: '.AddReviewPeople::CLOSED);
});

it('adds through the route with a plain flash, and answers refusals', function () {
    cyPerson();
    $cycle = cyOpen();
    $a = cyPerson();
    $b = cyPerson();
    $c = cyPerson(['designation_id' => Designation::factory()->create()->id, 'kpi' => false, 'first_name' => 'Zara', 'last_name' => 'Noor']);

    $this->actingAs(userWithRole('hr'))->post("/performance/cycles/{$cycle->id}/people", ['employee_ids' => [$a->id, $b->id]])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', '2 people added.');

    $this->actingAs(userWithRole('hr'))->post("/performance/cycles/{$cycle->id}/people", ['employee_ids' => [$c->id]])
        ->assertSessionHasErrors(['employee_ids' => 'Zara Noor has no active KPIs.']);

    $this->actingAs(userWithRole('hr'))->post("/performance/cycles/{$cycle->id}/people", ['employee_ids' => []])
        ->assertSessionHasErrors(['employee_ids' => AddReviewPeople::NONE]);
});

it('keeps Manager and Employee out of add people', function (string $role) {
    $cycle = reviewCycleRow();
    $person = cyPerson();

    $this->actingAs(userWithRole($role))->post("/performance/cycles/{$cycle->id}/people", ['employee_ids' => [$person->id]])->assertForbidden();

    expect(Review::count())->toBe(0);
})->with(['manager', 'employee']);

it('refuses with a friendly message, not a 500, when a KPI or a person is deleted while people are added', function (string $what) {
    cyPerson();
    $cycle = cyOpen();
    $a = cyPerson(['first_name' => 'Ali']);
    $b = cyPerson(['first_name' => 'Zed', 'designation_id' => Designation::factory()->create()->id]);
    $fired = false;

    DB::listen(function ($query) use (&$fired, $what, $b) {
        if (! $fired && str_contains($query->sql, 'insert into "reviews"')) {
            $fired = true;
            DB::table('kpis')->where('designation_id', $b->designation_id)->delete();

            if ($what === 'person') {
                DB::table('employees')->where('id', $b->id)->delete();
            }
        }
    });

    expect(cyRefusal(fn () => apAdd($cycle, [$a->id, $b->id])))->toBe('cycle: '.OpenReviewCycle::CHANGED)
        ->and(Review::query()->whereIn('employee_id', [$a->id, $b->id])->count())->toBe(0)
        ->and(Activity::query()->where('description', 'Review cycle people added')->count())->toBe(0);
})->with(['a KPI' => 'kpi', 'a person' => 'person']);
