<?php

use App\Actions\Performance\CloseReview;
use App\Actions\Performance\SubmitManagerReview;
use App\Models\Employee;
use App\Models\Review;
use App\Support\Performance\ReviewAccess;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

// Nobody rates or closes their own review (spec 5.5), except the sole active performance.manage user, audited own_review.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->hr = userWithRole('hr');
    $this->hrEmp = employeeFor($this->hr, ['first_name' => 'Hina', 'last_name' => 'Raza']);
    $this->admin = userWithRole('admin');
    employeeFor($this->admin, ['first_name' => 'Asad', 'last_name' => 'Ali']);
});

it('an HR user cannot rate or close their own review; another performance.manage user can', function () {
    $own = rvReview($this->hrEmp, 'manager_review', ['reviewer_id' => null]);

    $this->actingAs($this->hr)
        ->post(route('performance.reviews.manager-submit', $own->id), ['ratings' => rvRatings($own, [5, 5, 5])])
        ->assertForbidden();
    $this->actingAs($this->admin)
        ->post(route('performance.reviews.manager-submit', $own->id), ['ratings' => rvRatings($own, [4, 4, 4])])
        ->assertSessionHasNoErrors();

    $this->actingAs($this->hr)->post(route('performance.reviews.close', $own->id))->assertForbidden();
    $this->actingAs($this->admin)->post(route('performance.reviews.close', $own->id))->assertSessionHasNoErrors();

    expect(Review::query()->findOrFail($own->id)->final_score)->toBe(400)
        ->and(rvAudit('Review closed')->sole()->properties['own_review'])->toBeFalse();
});

it('an HR user who is the manager of a report rates that report as reviewer', function () {
    $report = Employee::factory()->create(['manager_id' => $this->hrEmp->id]);
    $review = rvReview($report, 'manager_review', ['reviewer_id' => $this->hrEmp->id]);

    expect(ReviewAccess::mayRate($this->hr, $review))->toBeTrue()
        ->and(ReviewAccess::mayRate($this->admin, $review))->toBeFalse();

    $this->actingAs($this->hr)
        ->post(route('performance.reviews.manager-submit', $review->id), ['ratings' => rvRatings($review, [3, 3, 3])])
        ->assertSessionHasNoErrors();
});

it('the sole active performance.manage user rates and closes their own review, flagged own_review', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $own = rvReview($this->hrEmp, 'manager_review', ['reviewer_id' => null]);

    app(SubmitManagerReview::class)->handle($this->hr, $own, rvRatings($own, [4, 5, 3]), null);
    app(CloseReview::class)->handle($this->hr, $own);

    expect(Review::query()->findOrFail($own->id)->status)->toBe('closed')
        ->and(rvAudit('Manager review submitted')->sole()->properties['own_review'])->toBeTrue()
        ->and(rvAudit('Review closed')->sole()->properties['own_review'])->toBeTrue();
});

it('with a second active HR user the exception no longer applies', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $second = userWithRole('hr');
    $own = rvReview($this->hrEmp, 'ready_to_close', ['reviewer_id' => null, 'manager' => [4, 4, 4]]);

    expect(ReviewAccess::mayManage($this->hr, $own))->toBeFalse();
    $this->actingAs($this->hr)->post(route('performance.reviews.close', $own->id))->assertForbidden();

    $second->forceFill(['is_active' => false])->save();
    expect(ReviewAccess::mayManage($this->hr->refresh(), $own))->toBeTrue();
});

it('nobody reaches their own review through reviewer_id', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $manager = userWithRole('manager');
    $managerEmp = employeeFor($manager);
    $selfReviewed = rvReview($managerEmp, 'manager_review', ['reviewer_id' => $managerEmp->id]);
    $hrSelf = rvReview($this->hrEmp, 'manager_review', ['reviewer_id' => $this->hrEmp->id]);

    expect(ReviewAccess::mayRate($manager, $selfReviewed))->toBeFalse()
        ->and(ReviewAccess::mayRate($this->hr, $hrSelf))->toBeFalse();

    $this->actingAs($manager)
        ->post(route('performance.reviews.manager-submit', $selfReviewed->id), ['ratings' => rvRatings($selfReviewed, [5, 5, 5])])
        ->assertForbidden();
});
