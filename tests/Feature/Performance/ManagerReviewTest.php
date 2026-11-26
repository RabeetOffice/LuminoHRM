<?php

use App\Models\Employee;
use App\Models\Review;
use App\Support\Performance\ReviewRatings;
use App\Support\Performance\ReviewStates;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $this->review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id, 'send_back_note' => 'Earlier note']);
});

it('the reviewer saves a draft with an overall comment, unaudited', function () {
    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $this->review->id), [
            'ratings' => rvRatings($this->review, [3], 'Needs work'), 'manager_comment' => '  Overall fine  ',
        ])->assertSessionHasNoErrors();

    $fresh = Review::query()->findOrFail($this->review->id);
    expect($fresh->manager_comment)->toBe('Overall fine')
        ->and($fresh->status)->toBe('manager_review')
        ->and(rvScoreRows($this->review)[0])->toMatchArray(['manager_rating' => 3, 'manager_comment' => 'Needs work'])
        ->and(rvAudit())->toHaveCount(0);
});

it('the reviewer submits: ready to close, the send-back note cleared, audit with the score preview only', function () {
    $this->actingAs($this->p['manager'])
        ->post(route('performance.reviews.manager-submit', $this->review->id), [
            'ratings' => rvRatings($this->review, [4, 5, 3], 'Private remark'), 'manager_comment' => 'Hidden overall',
        ])->assertSessionHasNoErrors();

    $fresh = Review::query()->findOrFail($this->review->id);
    $entry = rvAudit('Manager review submitted')->sole();

    expect($fresh->status)->toBe('ready_to_close')
        ->and($fresh->send_back_note)->toBeNull()
        ->and($fresh->manager_submitted_at)->not->toBeNull()
        ->and($fresh->final_score)->toBeNull()
        ->and($entry->properties['manager_score'])->toBe(380)
        ->and($entry->properties['own_review'])->toBeFalse()
        ->and(json_encode($entry->properties))->not->toContain('Private remark')->not->toContain('Hidden overall');
});

it('refuses an overall comment over 2,000 characters', function () {
    $this->actingAs($this->p['manager'])->from('/x')
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => [], 'manager_comment' => str_repeat('a', 2001)])
        ->assertSessionHasErrors('manager_comment');
});

it('refuses manager ratings while the employee is still rating themselves', function () {
    $self = rvReview(Employee::factory()->create(['manager_id' => $this->p['managerEmp']->id]), 'self_review', ['reviewer_id' => $this->p['managerEmp']->id]);

    $this->actingAs($this->p['manager'])->from('/x')
        ->post(route('performance.reviews.manager-submit', $self->id), ['ratings' => rvRatings($self, [4, 4, 4])])
        ->assertSessionHasErrors(['review' => ReviewStates::refused('self_review')]);
    $this->actingAs($this->p['manager'])->from('/x')
        ->put(route('performance.reviews.manager-draft', $self->id), ['ratings' => rvRatings($self, [4])])
        ->assertSessionHasErrors(['review' => ReviewStates::refused('self_review')]);
});

it('refuses submit with a KPI unrated', function () {
    $this->actingAs($this->p['manager'])->from('/x')
        ->post(route('performance.reviews.manager-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 4])])
        ->assertSessionHasErrors(['ratings' => ReviewRatings::RATE_EVERY]);

    expect(Review::query()->findOrFail($this->review->id)->status)->toBe('manager_review');
});

it('the reviewee, HR (when a manager reviews) and another manager get 403', function (string $who) {
    $user = match ($who) {
        'reviewee' => $this->p['employee'],
        'hr' => $this->p['hr'],
        'another manager' => tap(userWithRole('manager'), fn ($u) => employeeFor($u)),
    };

    $this->actingAs($user)
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => rvRatings($this->review, [1])])
        ->assertForbidden();
    $this->actingAs($user)
        ->post(route('performance.reviews.manager-submit', $this->review->id), ['ratings' => rvRatings($this->review, [1, 1, 1])])
        ->assertForbidden();

    expect(array_column(rvScoreRows($this->review), 'manager_rating'))->toBe([null, null, null]);
})->with(['reviewee', 'hr', 'another manager']);

it('HR rates a review whose reviewer is "HR"', function () {
    $review = rvReview(Employee::factory()->create(), 'manager_review', ['reviewer_id' => null]);

    $this->actingAs($this->p['hr'])
        ->post(route('performance.reviews.manager-submit', $review->id), ['ratings' => rvRatings($review, [5, 5, 5])])
        ->assertSessionHasNoErrors();

    expect(Review::query()->findOrFail($review->id)->status)->toBe('ready_to_close');

    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $review->id), ['ratings' => []])
        ->assertForbidden();
});

it('keeps the saved overall comment when a draft or a submit has no manager_comment key; a key that is present changes it', function () {
    $this->review->forceFill(['manager_comment' => 'Saved overall'])->save();
    $comment = fn () => Review::query()->findOrFail($this->review->id)->manager_comment;

    // A draft with only ratings (an autosave of the changed rows): the comment stays.
    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => rvRatings($this->review, [3])])
        ->assertSessionHasNoErrors();
    expect($comment())->toBe('Saved overall');

    // A key that is present changes it, to a new text...
    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => [], 'manager_comment' => 'Changed overall'])
        ->assertSessionHasNoErrors();
    expect($comment())->toBe('Changed overall');

    // ...and to nothing, when it is sent empty.
    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $this->review->id), ['ratings' => [], 'manager_comment' => null])
        ->assertSessionHasNoErrors();
    expect($comment())->toBeNull();

    // A submit without the key keeps whatever is saved.
    $this->review->forceFill(['manager_comment' => 'Kept on submit'])->save();
    $this->actingAs($this->p['manager'])
        ->post(route('performance.reviews.manager-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 4, 4])])
        ->assertSessionHasNoErrors();

    expect($comment())->toBe('Kept on submit')
        ->and(Review::query()->findOrFail($this->review->id)->status)->toBe('ready_to_close');
});
