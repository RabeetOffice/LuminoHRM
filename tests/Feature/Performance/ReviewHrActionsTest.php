<?php

use App\Actions\Performance\ChangeReviewer;
use App\Actions\Performance\SendBackReview;
use App\Actions\Performance\SkipReview;
use App\Models\Employee;
use App\Models\Review;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $other = userWithRole('manager');
    $this->bilal = employeeFor($other, ['first_name' => 'Bilal', 'last_name' => 'Shah']);
    $this->review = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id, 'manager' => [4, 2]]);
});

/** @return array{0: string, 1: string, 2: array<string, mixed>} [method, route name, payload] */
function rhRoute(string $action): array
{
    return match ($action) {
        'waive' => ['post', 'performance.reviews.waive', []],
        'send back' => ['post', 'performance.reviews.send-back', ['note' => 'Redo']],
        'close' => ['post', 'performance.reviews.close', []],
        'reopen' => ['post', 'performance.reviews.reopen', []],
        'skip' => ['post', 'performance.reviews.skip', ['reason' => 'Leave']],
        'restore' => ['post', 'performance.reviews.restore', []],
        'reviewer' => ['put', 'performance.reviews.reviewer', ['reviewer_id' => null]],
    };
}

it('the HR-only routes answer 403 to the reviewer and the reviewee, writing nothing', function (string $action, string $who) {
    [$method, $name, $payload] = rhRoute($action);
    $user = $who === 'manager' ? $this->p['manager'] : $this->p['employee'];
    $before = Review::query()->findOrFail($this->review->id)->getAttributes();

    $this->actingAs($user)->{$method}(route($name, $this->review->id), $payload)->assertForbidden();

    expect(Review::query()->findOrFail($this->review->id)->getAttributes())->toBe($before)
        ->and(rvAudit())->toHaveCount(0);
})->with(['waive', 'send back', 'close', 'reopen', 'skip', 'restore', 'reviewer'])->with(['manager', 'employee']);

it('HR waives a self-review over HTTP', function () {
    $review = rvReview(Employee::factory()->create(), 'self_review');

    $this->actingAs($this->p['hr'])->post(route('performance.reviews.waive', $review->id))->assertSessionHasNoErrors();

    expect(Review::query()->findOrFail($review->id)->status)->toBe('manager_review')
        ->and(Review::query()->findOrFail($review->id)->self_submitted_at)->toBeNull()
        ->and(rvAudit('Self-review waived')->sole()->properties['employee_id'])->toBe($review->employee_id);
});

it('HR sends back and skips over HTTP, with the request refusing an empty note or reason', function () {
    $ready = rvReview(Employee::factory()->create(), 'ready_to_close', ['manager' => [3, 3, 3]]);

    $this->actingAs($this->p['hr'])->from('/x')->post(route('performance.reviews.send-back', $ready->id), ['note' => ''])
        ->assertSessionHasErrors(['note' => SendBackReview::NOTE_REQUIRED]);
    $this->actingAs($this->p['hr'])->from('/x')->post(route('performance.reviews.skip', $ready->id), ['reason' => ''])
        ->assertSessionHasErrors(['reason' => SkipReview::REASON_REQUIRED]);
    $this->actingAs($this->p['hr'])->from('/x')->post(route('performance.reviews.skip', $ready->id), ['reason' => str_repeat('a', 501)])
        ->assertSessionHasErrors(['reason' => SkipReview::REASON_TOO_LONG]);

    $this->actingAs($this->p['hr'])->post(route('performance.reviews.send-back', $ready->id), ['note' => 'Explain the 3s'])->assertSessionHasNoErrors();
    expect(rvAudit('Review sent back')->sole()->properties['note'])->toBe('Explain the 3s');

    $this->actingAs($this->p['hr'])->post(route('performance.reviews.skip', $ready->id), ['reason' => 'Left'])->assertSessionHasNoErrors();
    $this->actingAs($this->p['hr'])->post(route('performance.reviews.restore', $ready->id))->assertSessionHasNoErrors();

    expect(Review::query()->findOrFail($ready->id)->status)->toBe('manager_review');
});

it('changes the reviewer to another manager, keeping the drafts', function () {
    $this->actingAs($this->p['hr'])
        ->put(route('performance.reviews.reviewer', $this->review->id), ['reviewer_id' => $this->bilal->id])
        ->assertSessionHasNoErrors();

    $entry = rvAudit('Review reviewer changed')->sole();

    expect(Review::query()->findOrFail($this->review->id)->reviewer_id)->toBe($this->bilal->id)
        ->and(array_column(rvScoreRows($this->review), 'manager_rating'))->toBe([4, 2, null])
        ->and($entry->properties['old'])->toBe(['reviewer' => 'Omar Khan'])
        ->and($entry->properties['attributes'])->toBe(['reviewer' => 'Bilal Shah']);
});

it('changes the reviewer to HR (null) and back', function () {
    app(ChangeReviewer::class)->handle($this->p['hr'], $this->review, null);
    expect(Review::query()->findOrFail($this->review->id)->reviewer_id)->toBeNull()
        ->and(rvAudit('Review reviewer changed')->sole()->properties['attributes'])->toBe(['reviewer' => 'HR']);

    app(ChangeReviewer::class)->handle($this->p['hr'], $this->review, $this->p['managerEmp']->id);
    expect(Review::query()->findOrFail($this->review->id)->reviewer_id)->toBe($this->p['managerEmp']->id);
});

it('changes the reviewer of a skipped review', function () {
    $skipped = rvReview(Employee::factory()->create(), 'skipped', ['skipped_from' => 'self_review']);

    app(ChangeReviewer::class)->handle($this->p['hr'], $skipped, $this->bilal->id);

    expect(Review::query()->findOrFail($skipped->id)->reviewer_id)->toBe($this->bilal->id);
});

it('refuses an unsuitable reviewer', function (string $case) {
    $employeeUser = userWithRole('employee');
    $employeeOnly = employeeFor($employeeUser, ['first_name' => 'Zara', 'last_name' => 'Noor']);
    $noLogin = Employee::factory()->create(['first_name' => 'Kamran', 'last_name' => 'Butt']);
    $disabledUser = userWithRole('manager');
    $disabled = employeeFor($disabledUser, ['first_name' => 'Faisal', 'last_name' => 'Mir']);
    $disabledUser->forceFill(['is_active' => false])->save();

    [$id, $message] = match ($case) {
        'self' => [$this->p['employeeEmp']->id, ChangeReviewer::SELF],
        'no login' => [$noLogin->id, ChangeReviewer::noAccessMessage('Kamran Butt')],
        'disabled login' => [$disabled->id, ChangeReviewer::noAccessMessage('Faisal Mir')],
        'employee role' => [$employeeOnly->id, ChangeReviewer::noAccessMessage('Zara Noor')],
        'unchanged' => [$this->p['managerEmp']->id, ChangeReviewer::unchangedMessage('Omar Khan')],
        'unknown' => [999999, ChangeReviewer::UNKNOWN],
    };

    expect(rvRefusal(fn () => app(ChangeReviewer::class)->handle($this->p['hr'], $this->review, $id)))->toBe(['reviewer_id' => $message])
        ->and(Review::query()->findOrFail($this->review->id)->reviewer_id)->toBe($this->p['managerEmp']->id)
        ->and(rvAudit())->toHaveCount(0);
})->with(['self', 'no login', 'disabled login', 'employee role', 'unchanged', 'unknown']);

it('refuses "already HR" and a closed review', function () {
    $hrReview = rvReview(Employee::factory()->create(), 'manager_review', ['reviewer_id' => null]);
    $closed = rvReview(Employee::factory()->create(), 'closed', ['final_score' => 300]);

    expect(rvRefusal(fn () => app(ChangeReviewer::class)->handle($this->p['hr'], $hrReview, null)))
        ->toBe(['reviewer_id' => ChangeReviewer::unchangedMessage('HR')])
        ->and(rvRefusal(fn () => app(ChangeReviewer::class)->handle($this->p['hr'], $closed, $this->bilal->id)))
        ->toBe(['reviewer_id' => ChangeReviewer::CLOSED]);
});

it('the reviewee of an HR review cannot change their own reviewer', function () {
    userWithRole('admin');
    $own = rvReview($this->p['hrEmp'], 'manager_review', ['reviewer_id' => null]);

    $this->actingAs($this->p['hr'])
        ->put(route('performance.reviews.reviewer', $own->id), ['reviewer_id' => $this->bilal->id])
        ->assertForbidden();

    expect(Review::query()->findOrFail($own->id)->reviewer_id)->toBeNull();
});
