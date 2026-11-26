<?php

use App\Actions\Performance\ChangeReviewer;
use App\Actions\Performance\CloseReview;
use App\Actions\Performance\ReopenReview;
use App\Actions\Performance\RestoreReview;
use App\Actions\Performance\SendBackReview;
use App\Actions\Performance\SkipReview;
use App\Actions\Performance\SubmitManagerReview;
use App\Actions\Performance\WaiveSelfReview;
use App\Models\Employee;
use App\Models\Review;
use App\Support\Performance\ReviewAccess;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

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

// ---------------------------------------------------------------- M6: the sole manager and a manager as reviewer

it('the sole performance.manage user whose own review has a manager as reviewer cannot rate it, but can close it, flagged own_review', function () {
    $this->admin->forceFill(['is_active' => false])->save();
    $manager = userWithRole('manager');
    $managerEmp = employeeFor($manager, ['first_name' => 'Omar', 'last_name' => 'Khan']);
    $this->hrEmp->forceFill(['manager_id' => $managerEmp->id])->save();
    $own = rvReview($this->hrEmp, 'ready_to_close', ['reviewer_id' => $managerEmp->id, 'manager' => [4, 4, 4]]);

    expect(ReviewAccess::mayRate($this->hr, $own))->toBeFalse()
        ->and(ReviewAccess::mayManage($this->hr, $own))->toBeTrue()
        ->and(ReviewAccess::role($this->hr, $own))->toBe('reviewee');

    $this->actingAs($this->hr)
        ->put(route('performance.reviews.manager-draft', $own->id), ['ratings' => rvRatings($own, [1])])
        ->assertForbidden();

    app(CloseReview::class)->handle($this->hr, $own);

    expect(Review::query()->findOrFail($own->id)->status)->toBe('closed')
        ->and(rvAudit('Review closed')->sole()->properties['own_review'])->toBeTrue();
});

it('an HR user who is the stored reviewer has the role hr and may rate', function () {
    $report = Employee::factory()->create(['manager_id' => $this->hrEmp->id]);
    $review = rvReview($report, 'manager_review', ['reviewer_id' => $this->hrEmp->id]);

    expect(ReviewAccess::role($this->hr, $review))->toBe('hr')
        ->and(ReviewAccess::mayRate($this->hr, $review))->toBeTrue();
});

// ---------------------------------------------------------------- M1: every HR action on one's own review is flagged

/** The six HR actions: [state to start in, review attributes, the audit entry, how to run it as $by on $review]. */
function ownActions(): array
{
    return [
        'waive' => ['self_review', [], 'Self-review waived', fn ($by, $r) => app(WaiveSelfReview::class)->handle($by, $r)],
        'skip' => ['manager_review', [], 'Review skipped', fn ($by, $r) => app(SkipReview::class)->handle($by, $r, 'On leave')],
        'send back' => ['ready_to_close', ['manager' => [4, 4, 4]], 'Review sent back', fn ($by, $r) => app(SendBackReview::class)->handle($by, $r, 'Redo it')],
        'reopen' => ['closed', ['manager' => [4, 4, 4], 'final_score' => 400, 'closed_at' => now()], 'Review reopened', fn ($by, $r) => app(ReopenReview::class)->handle($by, $r)],
        'restore' => ['skipped', ['skipped_from' => 'self_review', 'skip_reason' => 'Away'], 'Review restored', fn ($by, $r) => app(RestoreReview::class)->handle($by, $r)],
        'change reviewer' => ['manager_review', [], 'Review reviewer changed', function ($by, $r) {
            $boss = userWithRole('manager');
            $bossEmp = employeeFor($boss, ['first_name' => 'Omar', 'last_name' => 'Khan']);

            return app(ChangeReviewer::class)->handle($by, $r, $bossEmp->id);
        }],
    ];
}

it('flags own_review: true when the sole performance.manage user runs an HR action on their own review', function (string $action) {
    [$state, $attrs, $entry, $run] = ownActions()[$action];
    $this->admin->forceFill(['is_active' => false])->save();
    $own = rvReview($this->hrEmp, $state, array_merge(['reviewer_id' => null], $attrs));

    $run($this->hr, $own);

    expect(rvAudit($entry)->sole()->properties['own_review'])->toBeTrue();
})->with(['waive', 'skip', 'send back', 'reopen', 'restore', 'change reviewer']);

it('flags own_review: false when another performance.manage user runs the action on that review', function (string $action) {
    [$state, $attrs, $entry, $run] = ownActions()[$action];
    $own = rvReview($this->hrEmp, $state, array_merge(['reviewer_id' => null], $attrs));

    $run($this->admin, $own);

    expect(rvAudit($entry)->sole()->properties['own_review'])->toBeFalse();
})->with(['waive', 'skip', 'send back', 'reopen', 'restore', 'change reviewer']);

it('refuses every one of those actions on their own review once a second performance.manage user is active', function (string $action) {
    [$state, $attrs, , $run] = ownActions()[$action];
    $own = rvReview($this->hrEmp, $state, array_merge(['reviewer_id' => null], $attrs));

    expect(fn () => $run($this->hr, $own))->toThrow(AuthorizationException::class);
})->with(['waive', 'skip', 'send back', 'reopen', 'restore', 'change reviewer']);
