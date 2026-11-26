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
    $this->review = rvReview($this->p['employeeEmp'], 'self_review', ['reviewer_id' => $this->p['managerEmp']->id]);
});

it('the employee saves a partial draft any number of times, with no audit entry and no state change', function () {
    $this->actingAs($this->p['employee'])->from('/my/reviews')
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [4], 'Good start')])
        ->assertRedirect('/my/reviews')->assertSessionHasNoErrors();

    $this->actingAs($this->p['employee'])
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [5, 3])])
        ->assertSessionHasNoErrors();

    $rows = rvScoreRows($this->review);
    expect(array_column($rows, 'self_rating'))->toBe([5, 3, null])
        ->and($rows[0]['self_comment'])->toBeNull()
        ->and(Review::query()->findOrFail($this->review->id)->status)->toBe('self_review')
        ->and(rvAudit())->toHaveCount(0);
});

it('keeps a comment when only the rating is sent', function () {
    $this->actingAs($this->p['employee'])
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [4], 'Kept')]);
    $first = $this->review->scores()->first();

    $this->actingAs($this->p['employee'])
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => [$first->id => ['rating' => 2]]])
        ->assertSessionHasNoErrors();

    expect(rvScoreRows($this->review)[0])->toMatchArray(['self_rating' => 2, 'self_comment' => 'Kept']);
});

it('the employee submits: self score stored, state with the manager, one audit entry without ratings or comments', function () {
    $this->actingAs($this->p['employee'])
        ->post(route('performance.reviews.self-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 5, 3], 'Secret words')])
        ->assertSessionHasNoErrors();

    $fresh = Review::query()->findOrFail($this->review->id);
    $entry = rvAudit('Self-review submitted')->sole();

    expect($fresh->status)->toBe('manager_review')
        ->and($fresh->self_score)->toBe(380)
        ->and($fresh->self_submitted_at)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->p['employee']->id)
        ->and($entry->properties->except(['ip', 'user_agent'])->all())->toBe([
            'employee_id' => $this->p['employeeEmp']->id, 'employee' => 'Sara Ahmed',
            'cycle_id' => $this->review->review_cycle_id, 'cycle' => $this->review->cycle->name, 'self_score' => 380,
        ])
        ->and(json_encode($entry->properties))->not->toContain('Secret words');
});

it('submitting with a KPI unrated is refused and keeps nothing', function () {
    $this->actingAs($this->p['employee'])->from('/x')
        ->post(route('performance.reviews.self-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 5])])
        ->assertSessionHasErrors(['ratings' => ReviewRatings::RATE_EVERY]);

    expect(array_column(rvScoreRows($this->review), 'self_rating'))->toBe([null, null, null])
        ->and(Review::query()->findOrFail($this->review->id)->status)->toBe('self_review');
});

it('a draft save after submit refuses with the state message', function () {
    $this->actingAs($this->p['employee'])
        ->post(route('performance.reviews.self-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 5, 3])]);

    $this->actingAs($this->p['employee'])->from('/x')
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [1])])
        ->assertSessionHasErrors(['review' => ReviewStates::refused('manager_review')]);

    expect(array_column(rvScoreRows($this->review), 'self_rating'))->toBe([4, 5, 3]);
});

it('refuses ratings of another review\'s score rows', function () {
    $other = rvReview(Employee::factory()->create(), 'self_review');

    $this->actingAs($this->p['employee'])->from('/x')
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($other, [4])])
        ->assertSessionHasErrors(['ratings' => ReviewRatings::NOT_THIS_REVIEW]);

    expect(array_column(rvScoreRows($other), 'self_rating'))->toBe([null, null, null]);
});

it('validates the rating range and comment length on the request', function () {
    $this->actingAs($this->p['employee'])->from('/x')
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [9])])
        ->assertSessionHasErrors();

    $this->actingAs($this->p['employee'])->from('/x')
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [3], str_repeat('a', 1001))])
        ->assertSessionHasErrors();

    expect(array_column(rvScoreRows($this->review), 'self_rating'))->toBe([null, null, null]);
});

it('only the reviewee rates themselves: the manager, HR and others get 403', function (string $who) {
    $user = match ($who) {
        'manager' => $this->p['manager'],
        'hr' => $this->p['hr'],
        'another employee' => tap(userWithRole('employee'), fn ($u) => employeeFor($u)),
    };

    $this->actingAs($user)
        ->put(route('performance.reviews.self-draft', $this->review->id), ['ratings' => rvRatings($this->review, [4])])
        ->assertForbidden();
    $this->actingAs($user)
        ->post(route('performance.reviews.self-submit', $this->review->id), ['ratings' => rvRatings($this->review, [4, 4, 4])])
        ->assertForbidden();

    expect(array_column(rvScoreRows($this->review), 'self_rating'))->toBe([null, null, null]);
})->with(['manager', 'hr', 'another employee']);

it('answers 403 for an unknown review id, never 404', function () {
    $this->actingAs($this->p['employee'])
        ->put(route('performance.reviews.self-draft', 999999), ['ratings' => []])
        ->assertForbidden();
});
