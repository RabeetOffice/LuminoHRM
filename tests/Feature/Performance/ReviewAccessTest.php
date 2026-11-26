<?php

use App\Actions\Performance\ChangeReviewer;
use App\Models\Employee;
use App\Models\Review;
use App\Support\Performance\ReviewAccess;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $this->admin = userWithRole('admin');
    $this->adminEmp = employeeFor($this->admin, ['first_name' => 'Asad', 'last_name' => 'Ali']);
    $this->otherManager = userWithRole('manager');
    $this->otherManagerEmp = employeeFor($this->otherManager, ['first_name' => 'Bilal', 'last_name' => 'Shah']);
    $this->otherEmployee = userWithRole('employee');
    employeeFor($this->otherEmployee, ['first_name' => 'Nida', 'last_name' => 'Iqbal', 'manager_id' => $this->otherManagerEmp->id]);
    $this->review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id]);
});

it('answers role, mayRate and mayManage for each viewer', function (string $who, ?string $role, bool $rate, bool $manage) {
    $user = match ($who) {
        'reviewee' => $this->p['employee'],
        'reviewer' => $this->p['manager'],
        'hr' => $this->p['hr'],
        'admin' => $this->admin,
        'another manager' => $this->otherManager,
        'another employee' => $this->otherEmployee,
    };

    expect(ReviewAccess::role($user, $this->review))->toBe($role)
        ->and(ReviewAccess::mayRate($user, $this->review))->toBe($rate)
        ->and(ReviewAccess::mayManage($user, $this->review))->toBe($manage)
        ->and(ReviewAccess::findVisible($user, $this->review->id)?->id)->toBe($role === null ? null : $this->review->id);
})->with([
    ['reviewee', 'reviewee', false, false],
    ['reviewer', 'reviewer', true, false],
    // HR never rates for a manager (open item 11): HR changes the reviewer instead.
    ['hr', 'hr', false, true],
    ['admin', 'hr', false, true],
    ['another manager', null, false, false],
    ['another employee', null, false, false],
]);

it('with the reviewer "HR", only performance.manage users who are not the reviewee may rate', function () {
    $review = rvReview(Employee::factory()->create(), 'manager_review', ['reviewer_id' => null]);
    $ownHr = rvReview($this->p['hrEmp'], 'manager_review', ['reviewer_id' => null]);

    expect(ReviewAccess::mayRate($this->p['hr'], $review))->toBeTrue()
        ->and(ReviewAccess::mayRate($this->admin, $review))->toBeTrue()
        ->and(ReviewAccess::mayRate($this->p['manager'], $review))->toBeFalse()
        ->and(ReviewAccess::mayRate($this->otherEmployee, $review))->toBeFalse()
        // HR's own review (another performance.manage user, the Admin, is active).
        ->and(ReviewAccess::mayRate($this->p['hr'], $ownHr))->toBeFalse()
        ->and(ReviewAccess::mayManage($this->p['hr'], $ownHr))->toBeFalse()
        ->and(ReviewAccess::role($this->p['hr'], $ownHr))->toBe('reviewee')
        ->and(ReviewAccess::mayRate($this->admin, $ownHr))->toBeTrue();
});

it('a reviewer who lost review access can do nothing', function () {
    $this->p['manager']->syncRoles(['employee']);
    $this->p['manager']->refresh();

    expect(ReviewAccess::mayRate($this->p['manager'], $this->review))->toBeFalse()
        ->and(ReviewAccess::role($this->p['manager'], $this->review))->toBeNull()
        ->and(ReviewAccess::findVisible($this->p['manager'], $this->review->id))->toBeNull();
});

it('the previous reviewer is refused at once after ChangeReviewer', function () {
    app(ChangeReviewer::class)->handle($this->p['hr'], $this->review, $this->otherManagerEmp->id);
    $fresh = Review::query()->findOrFail($this->review->id);

    expect(ReviewAccess::mayRate($this->p['manager'], $fresh))->toBeFalse()
        ->and(ReviewAccess::role($this->p['manager'], $fresh))->toBeNull()
        ->and(ReviewAccess::findVisible($this->p['manager'], $fresh->id))->toBeNull()
        ->and(ReviewAccess::mayRate($this->otherManager, $fresh))->toBeTrue();

    $this->actingAs($this->p['manager'])
        ->put(route('performance.reviews.manager-draft', $fresh->id), ['ratings' => rvRatings($fresh, [4])])
        ->assertForbidden();
});

it('scopes the list per role, with a manager seeing only the closed history of current reports', function () {
    $mine = $this->review;
    // Closed history of a current report, in a closed cycle where someone else reviewed.
    $oldCycle = reviewCycleRow(['status' => 'closed', 'closed_at' => now()]);
    $history = rvReview($this->p['employeeEmp'], 'closed', ['cycle' => $oldCycle, 'reviewer_id' => $this->otherManagerEmp->id, 'final_score' => 300]);
    // A skipped review of a current report in a closed cycle: not history.
    $skippedOld = rvReview(Employee::factory()->create(['manager_id' => $this->p['managerEmp']->id]), 'skipped', ['cycle' => $oldCycle, 'reviewer_id' => null, 'skipped_from' => 'manager_review']);
    // A former report: closed review, the person now reports to someone else.
    $former = Employee::factory()->create(['manager_id' => $this->otherManagerEmp->id]);
    $formerClosed = rvReview($former, 'closed', ['cycle' => $oldCycle, 'reviewer_id' => $this->p['managerEmp']->id, 'final_score' => 400]);
    // The manager reviewed in a closed cycle, but the person is not a current report.
    // The manager's own review.
    $managersOwn = rvReview($this->p['managerEmp'], 'self_review', ['reviewer_id' => null]);
    // Someone else's review in an open cycle.
    $others = rvReview(Employee::factory()->create(['manager_id' => $this->otherManagerEmp->id]), 'manager_review', ['reviewer_id' => $this->otherManagerEmp->id]);

    $ids = fn ($user) => ReviewAccess::scope(Review::query(), $user)->orderBy('id')->pluck('id')->all();
    $all = Review::query()->orderBy('id')->pluck('id')->all();

    expect($ids($this->p['hr']))->toBe($all)
        ->and($ids($this->admin))->toBe($all)
        ->and($ids($this->p['manager']))->toBe([$mine->id, $history->id, $managersOwn->id])
        ->and($ids($this->p['employee']))->toBe([$mine->id, $history->id])
        ->and($ids($this->otherEmployee))->toBe([])
        ->and($ids($this->otherManager))->toBe([$formerClosed->id, $others->id])
        ->and(ReviewAccess::role($this->p['manager'], $history))->toBe('reviewer')
        ->and(ReviewAccess::role($this->p['manager'], $formerClosed))->toBeNull()
        ->and(ReviewAccess::role($this->p['manager'], $skippedOld))->toBeNull()
        ->and(ReviewAccess::role($this->p['manager'], $managersOwn))->toBe('reviewee')
        ->and(ReviewAccess::role($this->otherManager, $history))->toBeNull();
});

it('a reviewer loses the review when its cycle closes, unless it is closed history of a current report', function () {
    $cycle = reviewCycleRow(['status' => 'closed', 'closed_at' => now()]);
    $skipped = rvReview(Employee::factory()->create(['manager_id' => $this->otherManagerEmp->id]), 'skipped', [
        'cycle' => $cycle, 'reviewer_id' => $this->p['managerEmp']->id, 'skipped_from' => 'manager_review',
    ]);

    expect(ReviewAccess::role($this->p['manager'], $skipped))->toBeNull()
        ->and(ReviewAccess::findVisible($this->p['manager'], $skipped->id))->toBeNull();
});

it('a user without an employee record sees no review unless they hold performance.manage', function () {
    $bare = userWithRole('manager');

    expect(ReviewAccess::scope(Review::query(), $bare)->count())->toBe(0)
        ->and(ReviewAccess::role($bare, $this->review))->toBeNull()
        ->and(ReviewAccess::mayRate($bare, $this->review))->toBeFalse();
});

it('soleManager is true only for the one active performance.manage user', function () {
    expect(ReviewAccess::soleManager($this->p['hr']))->toBeFalse();

    $this->admin->forceFill(['is_active' => false])->save();
    expect(ReviewAccess::soleManager($this->p['hr']->refresh()))->toBeTrue()
        ->and(ReviewAccess::soleManager($this->p['manager']))->toBeFalse()
        ->and(ReviewAccess::soleManager($this->admin->refresh()))->toBeFalse();
});
