<?php

use App\Actions\Performance\OpenReviewCycle;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Kpi;
use App\Models\Review;
use App\Models\ReviewCycle;
use App\Models\ReviewScore;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

// ---------------------------------------------------------------- periods

it('opens the current period, which has started and not ended', function () {
    cyPerson();

    $cycle = cyOpen(key: '2026-Q4');

    expect($cycle->name)->toBe('Q4 2026 (Oct–Dec)')
        ->and($cycle->period_type)->toBe('quarterly')
        ->and($cycle->period_key)->toBe('2026-Q4')
        ->and($cycle->period_start->format('Y-m-d'))->toBe('2026-10-01')
        ->and($cycle->period_end->format('Y-m-d'))->toBe('2026-12-31')
        ->and($cycle->due_on->format('Y-m-d'))->toBe('2027-01-14')
        ->and($cycle->status)->toBe('open');
});

it('refuses a future period, an unknown key and a key of the other type', function (string $key, string $message) {
    cyPerson();

    expect(cyRefusal(fn () => cyOpen(key: $key)))->toBe("period: {$message}")
        ->and(ReviewCycle::count())->toBe(0);
})->with([
    'future' => ['2027-Q1', OpenReviewCycle::FUTURE],
    'unknown' => ['2026-Q9', OpenReviewCycle::UNKNOWN],
    'monthly key under quarterly' => ['2026-11', OpenReviewCycle::UNKNOWN],
    'older than offered' => ['2023-Q1', OpenReviewCycle::UNKNOWN],
]);

it('takes monthly keys when the setting is monthly', function () {
    Settings::set('performance', ['review_period' => 'monthly']);
    cyPerson();

    $cycle = cyOpen(key: '2026-10');

    expect($cycle->name)->toBe('October 2026')->and($cycle->period_type)->toBe('monthly')
        ->and(cyRefusal(fn () => cyOpen(key: '2026-Q2')))->toBe('period: '.OpenReviewCycle::UNKNOWN);
});

it('refuses the same period twice', function () {
    cyPerson();
    cyOpen();

    expect(cyRefusal(fn () => cyOpen()))->toBe('period: A cycle already exists for Q3 2026 (Jul–Sep).')
        ->and(ReviewCycle::count())->toBe(1);
});

it('refuses a period another cycle overlaps, both ways', function () {
    cyPerson();
    reviewCycleRow(['name' => 'August 2026', 'period_key' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_on' => '2026-09-14']);

    expect(cyRefusal(fn () => cyOpen(key: '2026-Q3')))->toBe('period: That period overlaps August 2026.');

    Settings::set('performance', ['review_period' => 'monthly']);
    reviewCycleRow(['name' => 'Q2 2026 (Apr–Jun)', 'period_type' => 'quarterly', 'period_key' => '2026-Q2', 'period_start' => '2026-04-01', 'period_end' => '2026-06-30', 'due_on' => '2026-07-14']);

    expect(cyRefusal(fn () => cyOpen(key: '2026-05')))->toBe('period: That period overlaps Q2 2026 (Apr–Jun).')
        ->and(ReviewCycle::count())->toBe(2);
});

// ---------------------------------------------------------------- due date

it('keeps a due date on or after the period end and refuses one before it or a bad date', function () {
    cyPerson();

    expect(cyRefusal(fn () => cyOpen(due: '2026-09-29')))->toBe('due_on: The due date cannot be before the period ends (30 Sep 2026).')
        ->and(cyRefusal(fn () => cyOpen(due: '2026-02-30')))->toBe('due_on: '.OpenReviewCycle::BAD_DATE)
        ->and(cyRefusal(fn () => cyOpen(due: '30/10/2026')))->toBe('due_on: '.OpenReviewCycle::BAD_DATE)
        ->and(ReviewCycle::count())->toBe(0)
        ->and(cyOpen(due: '2026-09-30')->due_on->format('Y-m-d'))->toBe('2026-09-30');
});

// ---------------------------------------------------------------- settings copied

it('copies the self-review flag and keeps it when the setting changes', function () {
    cyPerson();
    Settings::set('performance', ['self_review_enabled' => false]);

    $cycle = cyOpen();
    Settings::set('performance', ['self_review_enabled' => true, 'review_period' => 'monthly']);

    expect($cycle->fresh()->self_review_enabled)->toBeFalse()
        ->and($cycle->fresh()->period_type)->toBe('quarterly')
        ->and(Review::query()->sole()->status)->toBe('manager_review');
});

it('starts reviews at self_review when the cycle has self-review on', function () {
    cyPerson();

    cyOpen();

    expect(Review::query()->sole()->status)->toBe('self_review');
});

// ---------------------------------------------------------------- nobody

it('refuses to open with nobody eligible and writes nothing', function () {
    cyPerson(['kpi' => false]);
    cyPerson(['status' => 'resigned']);

    expect(cyRefusal(fn () => cyOpen()))->toBe('period: '.OpenReviewCycle::NOBODY)
        ->and(ReviewCycle::count())->toBe(0)
        ->and(Review::count())->toBe(0)
        ->and(DB::table('activity_log')->where('log_name', 'performance')->where('description', 'Review cycle opened')->count())->toBe(0);
});

// ---------------------------------------------------------------- reviews, reviewer, snapshots

it('creates one review per eligible person and lists the no-KPI people', function () {
    $hr = userWithRole('hr');
    $a = cyPerson();
    $b = cyPerson();
    $none = cyPerson(['kpi' => false]);

    $cycle = cyOpen($hr);

    expect(Review::query()->pluck('employee_id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all())
        ->and($cycle->no_kpis->pluck('id')->all())->toBe([$none->id])
        ->and($cycle->opened_by)->toBe($hr->id);

    $audit = cyAudit('Review cycle opened');
    expect($audit->subject_id)->toBe($cycle->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['name'])->toBe('Q3 2026 (Jul–Sep)')
        ->and($audit->properties['period_key'])->toBe('2026-Q3')
        ->and($audit->properties['period_start'])->toBe('2026-07-01')
        ->and($audit->properties['period_end'])->toBe('2026-09-30')
        ->and($audit->properties['due_on'])->toBe('2026-10-14')
        ->and($audit->properties['reviews'])->toBe(2)
        ->and($audit->properties['no_kpis'])->toBe(['count' => 1, 'employee_ids' => [$none->id]])
        ->and($audit->properties['self_review_enabled'])->toBeTrue();
});

it('sets the reviewer to the manager, none without one, none when the manager is the person', function () {
    $boss = cyPerson();
    $report = cyPerson(['manager_id' => $boss->id]);
    $self = cyPerson();
    $self->forceFill(['manager_id' => $self->id])->saveQuietly();

    cyOpen();

    $reviewer = fn (Employee $e) => Review::query()->where('employee_id', $e->id)->value('reviewer_id');

    expect($reviewer($report))->toBe($boss->id)
        ->and($reviewer($boss))->toBeNull()
        ->and($reviewer($self))->toBeNull();
});

it('copies each active KPI with designation ones first, by name then id, and kpi_id set', function () {
    $designation = Designation::factory()->create();
    $person = cyPerson(['designation_id' => $designation->id, 'kpi' => false]);
    $zeta = kpiRow(['designation' => $designation, 'name' => 'Zeta', 'weight' => 10, 'description' => 'Last by name']);
    $alpha = kpiRow(['designation' => $designation, 'name' => 'Alpha', 'weight' => 20]);
    kpiRow(['designation' => $designation, 'name' => 'Off', 'is_active' => false]);
    $own = kpiRow(['employee' => $person, 'name' => 'Aardvark', 'weight' => 50]);

    cyOpen();

    $rows = ReviewScore::query()->orderBy('sort_order')->get();

    expect($rows->pluck('kpi_id')->all())->toBe([$alpha->id, $zeta->id, $own->id])
        ->and($rows->pluck('name')->all())->toBe(['Alpha', 'Zeta', 'Aardvark'])
        ->and($rows->pluck('weight')->all())->toBe([20, 10, 50])
        ->and($rows->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and($rows[1]->description)->toBe('Last by name')
        ->and($rows->pluck('self_rating')->filter()->all())->toBe([]);
});

it('leaves the copies alone when a KPI is edited or deactivated, or the designation changes, afterwards', function () {
    $person = cyPerson();
    $kpi = Kpi::query()->sole();
    $description = $kpi->description;

    cyOpen();

    $kpi->forceFill(['name' => 'Renamed', 'weight' => 99, 'description' => 'New'])->save();
    $kpi->forceFill(['is_active' => false])->save();
    $person->forceFill(['designation_id' => Designation::factory()->create()->id])->save();

    $row = ReviewScore::query()->sole();

    expect($row->name)->toBe('Delivery')->and($row->weight)->toBe(40)->and($row->description)->toBe($description)
        ->and($row->kpi_id)->toBe($kpi->id);
});

// ---------------------------------------------------------------- races and the backstop

it('refuses a second open of the same key from a stale screen', function () {
    cyPerson();
    $hr = userWithRole('hr');

    app(OpenReviewCycle::class)->handle($hr, '2026-Q3', null);

    expect(cyRefusal(fn () => app(OpenReviewCycle::class)->handle($hr, '2026-Q3', null)))->toBe('period: A cycle already exists for Q3 2026 (Jul–Sep).')
        ->and(ReviewCycle::count())->toBe(1);
});

it('turns a unique-index collision (a concurrent open) into the already-exists refusal', function () {
    cyPerson();

    // A concurrent open commits the same period between the check and the insert.
    ReviewCycle::creating(function (ReviewCycle $cycle) {
        if ($cycle->period_key === '2026-Q3') {
            DB::table('review_cycles')->insert([
                'name' => 'Q3 2026 (Jul–Sep)', 'period_type' => 'quarterly', 'period_key' => '2026-Q3', 'period_start' => '2026-07-01',
                'period_end' => '2026-09-30', 'due_on' => '2026-10-14', 'status' => 'open', 'self_review_enabled' => true,
                'opened_by' => userWithRole('hr')->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    expect(cyRefusal(fn () => cyOpen()))->toBe('period: A cycle already exists for Q3 2026 (Jul–Sep).')
        ->and(Review::count())->toBe(0);
});

// ---------------------------------------------------------------- the route

it('opens through the route with a flash naming the counts', function () {
    cyPerson();
    cyPerson();
    cyPerson(['kpi' => false]);

    $this->actingAs(userWithRole('hr'))->post('/performance/cycles', ['period' => '2026-Q3', 'due_on' => ''])
        ->assertRedirect()->assertSessionHasNoErrors()
        ->assertSessionHas('success', 'Q3 2026 (Jul–Sep) opened with 2 reviews. 1 person has no KPIs.');
});

it('says one review without a no-KPI sentence when everyone has KPIs', function () {
    cyPerson();

    $this->actingAs(userWithRole('admin'))->post('/performance/cycles', ['period' => '2026-Q3'])
        ->assertSessionHas('success', 'Q3 2026 (Jul–Sep) opened with 1 review.');
});

it('answers a refusal as an error on the field', function () {
    cyPerson();

    $this->actingAs(userWithRole('hr'))->post('/performance/cycles', ['period' => '2027-Q1'])
        ->assertSessionHasErrors(['period' => OpenReviewCycle::FUTURE]);
    $this->actingAs(userWithRole('hr'))->post('/performance/cycles', [])
        ->assertSessionHasErrors(['period' => OpenReviewCycle::UNKNOWN]);
});

it('keeps Manager and Employee out of open', function (string $role) {
    cyPerson();

    $this->actingAs(userWithRole($role))->post('/performance/cycles', ['period' => '2026-Q3'])->assertForbidden();

    expect(ReviewCycle::count())->toBe(0);
})->with(['manager', 'employee']);

// ---------------------------------------------------------------- races with a delete (M5)

it('refuses with a friendly message, not a 500, when a KPI is deleted while the cycle is being opened', function () {
    cyPerson(['first_name' => 'Ali']);
    $second = cyPerson(['first_name' => 'Zed', 'designation_id' => Designation::factory()->create()->id]);
    $zedKpi = Kpi::query()->where('designation_id', $second->designation_id)->sole();
    $fired = false;

    // The first review insert is the moment the other HR user's delete lands: the KPI is still in the plain read, gone by the copy.
    DB::listen(function ($query) use (&$fired, $zedKpi) {
        if (! $fired && str_contains($query->sql, 'insert into "reviews"')) {
            $fired = true;
            DB::table('kpis')->where('id', $zedKpi->id)->delete();
        }
    });

    expect(cyRefusal(fn () => cyOpen(key: '2026-Q4')))->toBe('period: '.OpenReviewCycle::CHANGED)
        ->and(OpenReviewCycle::CHANGED)->toBe('Something changed while opening the cycle. Try again.')
        ->and(ReviewCycle::count())->toBe(0)
        ->and(Review::count())->toBe(0);
});

it('refuses with the same message when a person is deleted while the cycle is being opened', function () {
    cyPerson(['first_name' => 'Ali']);
    $gone = cyPerson(['first_name' => 'Zed', 'designation_id' => Designation::factory()->create()->id]);
    $fired = false;

    DB::listen(function ($query) use (&$fired, $gone) {
        if (! $fired && str_contains($query->sql, 'insert into "reviews"')) {
            $fired = true;
            DB::table('kpis')->where('designation_id', $gone->designation_id)->delete();
            DB::table('employees')->where('id', $gone->id)->delete();
        }
    });

    expect(cyRefusal(fn () => cyOpen(key: '2026-Q4')))->toBe('period: '.OpenReviewCycle::CHANGED)
        ->and(ReviewCycle::count())->toBe(0);
});
