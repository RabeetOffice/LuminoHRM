<?php

use App\Actions\Performance\ChangeCycleDueDate;
use App\Actions\Performance\OpenReviewCycle;
use App\Models\ReviewCycle;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/Support/cycle-helpers.php';

// Coordinator ruling (open item 9): HR may change an open cycle's due date; audited "Cycle due date changed" (old / new); the
// date must be on or after the period end.

function ddCycle(): ReviewCycle
{
    return reviewCycleRow(['period_start' => '2030-07-01', 'period_end' => '2030-09-30', 'period_key' => '2030-Q3', 'period_type' => 'quarterly', 'due_on' => '2030-10-14']);
}

it('changes the due date of an open cycle and audits old and new', function () {
    $hr = userWithRole('hr');
    $cycle = ddCycle();

    app(ChangeCycleDueDate::class)->handle($hr, $cycle, '2030-11-01');

    $audit = cyAudit('Cycle due date changed');
    expect($cycle->fresh()->due_on->format('Y-m-d'))->toBe('2030-11-01')
        ->and($audit->subject_id)->toBe($cycle->id)
        ->and($audit->causer_id)->toBe($hr->id)
        ->and($audit->properties['old'])->toBe(['due_on' => '2030-10-14'])
        ->and($audit->properties['attributes'])->toBe(['due_on' => '2030-11-01']);
});

it('allows the period end itself and refuses a day before it, a bad date and the same date', function () {
    $cycle = ddCycle();
    $change = fn (?string $date) => app(ChangeCycleDueDate::class)->handle(userWithRole('hr'), $cycle, $date);

    expect(cyRefusal(fn () => $change('2030-09-29')))->toBe('due_on: The due date cannot be before the period ends (30 Sep 2030).')
        ->and(cyRefusal(fn () => $change('2030-13-01')))->toBe('due_on: '.OpenReviewCycle::BAD_DATE)
        ->and(cyRefusal(fn () => $change('')))->toBe('due_on: '.OpenReviewCycle::BAD_DATE)
        ->and(cyRefusal(fn () => $change('2030-10-14')))->toBe('due_on: The due date is already 14 Oct 2030.')
        ->and(Activity::query()->where('description', 'Cycle due date changed')->count())->toBe(0);

    $change('2030-09-30');

    expect($cycle->fresh()->due_on->format('Y-m-d'))->toBe('2030-09-30');
});

it('refuses on a closed cycle', function () {
    $cycle = ddCycle();
    $cycle->forceFill(['status' => 'closed'])->save();

    expect(cyRefusal(fn () => app(ChangeCycleDueDate::class)->handle(userWithRole('hr'), $cycle, '2030-12-01')))->toBe('cycle: '.ChangeCycleDueDate::CLOSED)
        ->and($cycle->fresh()->due_on->format('Y-m-d'))->toBe('2030-10-14');
});

it('changes through the route, and keeps Manager and Employee out', function () {
    $cycle = ddCycle();

    $this->actingAs(userWithRole('hr'))->put("/performance/cycles/{$cycle->id}/due-date", ['due_on' => '2030-10-31'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('success', 'Due date changed.');
    $this->actingAs(userWithRole('admin'))->put("/performance/cycles/{$cycle->id}/due-date", ['due_on' => '2030-09-01'])
        ->assertSessionHasErrors(['due_on' => 'The due date cannot be before the period ends (30 Sep 2030).']);

    foreach (['manager', 'employee'] as $role) {
        $this->actingAs(userWithRole($role))->put("/performance/cycles/{$cycle->id}/due-date", ['due_on' => '2030-12-31'])->assertForbidden();
    }

    expect($cycle->fresh()->due_on->format('Y-m-d'))->toBe('2030-10-31');
});
