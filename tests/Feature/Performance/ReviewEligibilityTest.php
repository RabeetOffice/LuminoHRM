<?php

use App\Models\Designation;
use App\Support\Performance\ReviewEligibility;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/cycle-helpers.php';

it('takes active and probation people and leaves out inactive, resigned and terminated ones', function () {
    $active = cyPerson(['status' => 'active']);
    $probation = cyPerson(['status' => 'probation']);
    cyPerson(['status' => 'inactive']);
    cyPerson(['status' => 'resigned']);
    cyPerson(['status' => 'terminated']);

    $found = ReviewEligibility::forPeriod('2026-09-30');

    expect($found['eligible']->pluck('id')->sort()->values()->all())->toBe(collect([$active->id, $probation->id])->sort()->values()->all())
        ->and($found['no_kpis'])->toBeEmpty();
});

it('leaves out someone who joined after the period end and keeps someone who joined on the last day', function () {
    $lastDay = cyPerson(['joining_date' => '2026-09-30']);
    cyPerson(['joining_date' => '2026-10-01']);

    expect(ReviewEligibility::forPeriod('2026-09-30')['eligible']->pluck('id')->all())->toBe([$lastDay->id]);
});

it('counts designation KPIs and personal KPIs, but not an inactive one', function () {
    $byDesignation = cyPerson();
    $personal = cyPerson(['kpi' => false]);
    kpiRow(['employee' => $personal]);
    $inactiveOnly = cyPerson(['kpi' => false]);
    kpiRow(['employee' => $inactiveOnly, 'is_active' => false]);
    kpiRow(['designation' => Designation::query()->findOrFail($inactiveOnly->designation_id), 'is_active' => false]);
    $nothing = cyPerson(['kpi' => false]);

    $found = ReviewEligibility::forPeriod('2026-09-30');

    expect($found['eligible']->pluck('id')->sort()->values()->all())->toBe(collect([$byDesignation->id, $personal->id])->sort()->values()->all())
        ->and($found['no_kpis']->pluck('id')->sort()->values()->all())->toBe(collect([$inactiveOnly->id, $nothing->id])->sort()->values()->all())
        ->and(array_keys($found['kpis']))->each->toBeIn([$byDesignation->id, $personal->id])
        ->and(ReviewEligibility::activeKpisFor($nothing))->toBeEmpty()
        ->and(ReviewEligibility::activeKpisFor($personal))->toHaveCount(1);
});

it('lists designation KPIs before personal ones, each by name then id', function () {
    $designation = Designation::factory()->create();
    $person = cyPerson(['designation_id' => $designation->id, 'kpi' => false]);
    kpiRow(['employee' => $person, 'name' => 'Alpha']);
    kpiRow(['designation' => $designation, 'name' => 'Zeta']);
    kpiRow(['designation' => $designation, 'name' => 'Beta']);

    expect(ReviewEligibility::activeKpisFor($person)->pluck('name')->all())->toBe(['Beta', 'Zeta', 'Alpha']);
});

it('leaves out people already in the cycle, and limits to the ids given', function () {
    $in = cyPerson();
    $out = cyPerson();
    $other = cyPerson();
    $cycle = reviewCycleRow(['period_end' => '2026-09-30']);
    reviewRow($cycle, $in);

    expect(ReviewEligibility::forPeriod('2026-09-30', $cycle->id)['eligible']->pluck('id')->sort()->values()->all())
        ->toBe(collect([$out->id, $other->id])->sort()->values()->all())
        ->and(ReviewEligibility::forPeriod('2026-09-30', $cycle->id, [$in->id, $out->id])['eligible']->pluck('id')->all())->toBe([$out->id]);
});

it('runs the same number of queries for 5 and 25 people', function () {
    $count = function (int $people): int {
        for ($i = 0; $i < $people; $i++) {
            $person = cyPerson(['kpi' => $i % 3 !== 0]);

            if ($i % 4 === 0) {
                kpiRow(['employee' => $person]);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        ReviewEligibility::forPeriod('2026-09-30', null);
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $five = $count(5);
    $twentyFive = $count(20);

    expect($twentyFive)->toBe($five)->and($five)->toBeLessThanOrEqual(2);
});
