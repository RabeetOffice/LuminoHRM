<?php

use App\Actions\Performance\AddReviewPeople;
use App\Actions\Performance\ChangeCycleDueDate;
use App\Actions\Performance\CloseReviewCycle;
use App\Models\ReviewCycle;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

function ccClose(ReviewCycle $cycle): ReviewCycle
{
    return app(CloseReviewCycle::class)->handle(userWithRole('hr'), $cycle);
}

it('refuses while a review is open, with the count in the message', function (array $states, string $message) {
    $cycle = reviewCycleRow();

    foreach ($states as $state) {
        reviewRow($cycle, cyPerson(), ['status' => $state, 'scores' => []]);
    }

    expect(cyRefusal(fn () => ccClose($cycle)))->toBe("cycle: {$message}")
        ->and($cycle->fresh()->status)->toBe('open');
})->with([
    'one' => [['closed', 'self_review'], '1 review is not finished yet. Close or skip it first.'],
    'three' => [['manager_review', 'ready_to_close', 'self_review', 'skipped'], '3 reviews are not finished yet. Close or skip them first.'],
]);

it('closes when every review is closed or skipped, and audits the counts', function () {
    $hr = userWithRole('hr');
    $cycle = reviewCycleRow();
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    reviewRow($cycle, cyPerson(), ['status' => 'skipped', 'scores' => []]);

    $closed = app(CloseReviewCycle::class)->handle($hr, $cycle);

    expect($closed->status)->toBe('closed')
        ->and($closed->fresh()->closed_by)->toBe($hr->id)
        ->and($closed->fresh()->closed_at)->not->toBeNull();

    $audit = cyAudit('Review cycle closed');
    expect($audit->subject_id)->toBe($cycle->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['closed'])->toBe(2)
        ->and($audit->properties['skipped'])->toBe(1);
});

it('refuses to close twice, and every cycle action refuses afterwards', function () {
    $cycle = reviewCycleRow();
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    $stale = ReviewCycle::query()->findOrFail($cycle->id);

    ccClose($cycle);

    expect(cyRefusal(fn () => ccClose($stale)))->toBe('cycle: '.CloseReviewCycle::ALREADY)
        ->and(cyRefusal(fn () => app(AddReviewPeople::class)->handle(userWithRole('hr'), $stale, [cyPerson()->id])))->toBe('cycle: This cycle is closed.')
        ->and(cyRefusal(fn () => app(ChangeCycleDueDate::class)->handle(userWithRole('hr'), $stale, '2030-01-01')))->toBe('cycle: This cycle is closed.')
        ->and(Activity::query()->where('description', 'Review cycle closed')->count())->toBe(1);
});

it('a late add people committed first makes the close refuse', function () {
    $cycle = reviewCycleRow(['period_end' => '2026-09-30']);
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    $closeScreen = ReviewCycle::query()->findOrFail($cycle->id);

    app(AddReviewPeople::class)->handle(userWithRole('hr'), $cycle, [cyPerson()->id]);

    expect(cyRefusal(fn () => ccClose($closeScreen)))->toBe('cycle: 1 review is not finished yet. Close or skip it first.')
        ->and($cycle->fresh()->status)->toBe('open');
});

it('a close committed first makes the late add people refuse', function () {
    $cycle = reviewCycleRow(['period_end' => '2026-09-30']);
    reviewRow($cycle, cyPerson(), ['status' => 'skipped', 'scores' => []]);
    $addScreen = ReviewCycle::query()->findOrFail($cycle->id);

    ccClose($cycle);

    expect(cyRefusal(fn () => app(AddReviewPeople::class)->handle(userWithRole('hr'), $addScreen, [cyPerson()->id])))->toBe('cycle: This cycle is closed.')
        ->and($cycle->reviews()->count())->toBe(1);
});

it('closes through the route with a plain flash and answers a refusal', function () {
    $open = reviewCycleRow();
    reviewRow($open, cyPerson(), ['status' => 'self_review', 'scores' => []]);
    $done = reviewCycleRow();
    reviewRow($done, cyPerson(), ['status' => 'closed', 'scores' => []]);

    $this->actingAs(userWithRole('hr'))->post("/performance/cycles/{$done->id}/close")
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Cycle closed.');
    $this->actingAs(userWithRole('admin'))->post("/performance/cycles/{$open->id}/close")
        ->assertSessionHasErrors(['cycle' => '1 review is not finished yet. Close or skip it first.']);
});

it('keeps Manager and Employee out of close, and 404s a non-numeric id', function (string $role) {
    $cycle = reviewCycleRow();
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);

    $this->actingAs(userWithRole($role))->post("/performance/cycles/{$cycle->id}/close")->assertForbidden();
    $this->actingAs(userWithRole('hr'))->post('/performance/cycles/abc/close')->assertNotFound();

    expect($cycle->fresh()->status)->toBe('open');
})->with(['manager', 'employee']);
