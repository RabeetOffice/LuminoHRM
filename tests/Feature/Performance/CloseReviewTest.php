<?php

use App\Actions\Performance\ReopenReview;
use App\Models\Review;
use App\Support\Performance\ReviewStates;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $this->review = rvReview($this->p['employeeEmp'], 'ready_to_close', ['reviewer_id' => $this->p['managerEmp']->id, 'manager' => [4, 5, 3]]);
});

it('HR closes: the score freezes with its label, closed_at and closed_by', function () {
    $this->actingAs($this->p['hr'])->post(route('performance.reviews.close', $this->review->id))->assertSessionHasNoErrors();

    $fresh = Review::query()->findOrFail($this->review->id);
    $entry = rvAudit('Review closed')->sole();

    expect($fresh->status)->toBe('closed')
        ->and($fresh->final_score)->toBe(380)
        ->and($fresh->closed_by)->toBe($this->p['hr']->id)
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($entry->properties->only(['final_score', 'label', 'own_review', 'employee_id', 'cycle_id'])->all())->toEqual([
            'final_score' => 380, 'label' => 'Exceeds', 'own_review' => false,
            'employee_id' => $this->p['employeeEmp']->id, 'cycle_id' => $this->review->review_cycle_id,
        ]);
});

it('the score is the one at close: later rating edits are impossible while closed', function () {
    $this->actingAs($this->p['hr'])->post(route('performance.reviews.close', $this->review->id));

    $this->actingAs($this->p['manager'])->from('/x')
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => rvRatings($this->review, [1, 1, 1])])
        ->assertSessionHasErrors(['review' => ReviewStates::refused('closed')]);

    expect(Review::query()->findOrFail($this->review->id)->final_score)->toBe(380);
});

it('HR reopens a closed review while the cycle is open, clearing the score', function () {
    $this->actingAs($this->p['hr'])->post(route('performance.reviews.close', $this->review->id));
    $this->actingAs($this->p['hr'])->post(route('performance.reviews.reopen', $this->review->id))->assertSessionHasNoErrors();

    $fresh = Review::query()->findOrFail($this->review->id);
    expect($fresh->status)->toBe('ready_to_close')
        ->and($fresh->final_score)->toBeNull()
        ->and(rvAudit('Review reopened')->sole()->properties['previous_score'])->toBe(380);
});

it('refuses to reopen in a closed cycle', function () {
    $cycle = reviewCycleRow(['status' => 'closed', 'closed_at' => now()]);
    $closed = rvReview($this->p['employeeEmp'], 'closed', ['cycle' => $cycle, 'final_score' => 300, 'manager' => [3, 3, 3]]);

    $this->actingAs($this->p['hr'])->from('/x')->post(route('performance.reviews.reopen', $closed->id))
        ->assertSessionHasErrors(['review' => ReopenReview::CYCLE_CLOSED]);

    expect(Review::query()->findOrFail($closed->id)->final_score)->toBe(300);
});

it('the reviewer and the reviewee cannot close or reopen (403)', function (string $who) {
    $user = $who === 'reviewer' ? $this->p['manager'] : $this->p['employee'];

    $this->actingAs($user)->post(route('performance.reviews.close', $this->review->id))->assertForbidden();

    expect(Review::query()->findOrFail($this->review->id)->status)->toBe('ready_to_close');
})->with(['reviewer', 'reviewee']);
