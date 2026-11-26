<?php

use App\Actions\Performance\ChangeReviewer;
use App\Actions\Performance\CloseReview;
use App\Actions\Performance\ReopenReview;
use App\Actions\Performance\RestoreReview;
use App\Actions\Performance\SaveManagerRatings;
use App\Actions\Performance\SaveSelfRatings;
use App\Actions\Performance\SendBackReview;
use App\Actions\Performance\SkipReview;
use App\Actions\Performance\SubmitManagerReview;
use App\Actions\Performance\SubmitSelfReview;
use App\Actions\Performance\WaiveSelfReview;
use App\Models\Employee;
use App\Models\Review;
use App\Models\ReviewScore;
use App\Support\Performance\ReviewLock;
use App\Support\Performance\ReviewStates;
use Carbon\CarbonImmutable;

require_once __DIR__.'/Support/review-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $other = userWithRole('manager');
    $this->newReviewer = employeeFor($other, ['first_name' => 'Bilal', 'last_name' => 'Shah']);
});

const RS_ACTIONS = ['self_draft', 'self_submit', 'waive', 'manager_draft', 'manager_submit', 'send_back', 'close', 'reopen', 'skip', 'restore', 'change_reviewer'];

const RS_STATES = ['self_review', 'manager_review', 'ready_to_close', 'closed', 'skipped'];

/** The spec table (5.5): action => [from => [to, audit description or null]]. Every other cell is refused. */
const RS_ALLOWED = [
    'self_draft' => ['self_review' => ['self_review', null]],
    'self_submit' => ['self_review' => ['manager_review', 'Self-review submitted']],
    'waive' => ['self_review' => ['manager_review', 'Self-review waived']],
    'manager_draft' => ['manager_review' => ['manager_review', null]],
    'manager_submit' => ['manager_review' => ['ready_to_close', 'Manager review submitted']],
    'send_back' => ['ready_to_close' => ['manager_review', 'Review sent back']],
    'close' => ['ready_to_close' => ['closed', 'Review closed']],
    'reopen' => ['closed' => ['ready_to_close', 'Review reopened']],
    'skip' => [
        'self_review' => ['skipped', 'Review skipped'],
        'manager_review' => ['skipped', 'Review skipped'],
        'ready_to_close' => ['skipped', 'Review skipped'],
    ],
    'restore' => ['skipped' => ['manager_review', 'Review restored']],
    'change_reviewer' => [
        'self_review' => ['self_review', 'Review reviewer changed'],
        'manager_review' => ['manager_review', 'Review reviewer changed'],
        'ready_to_close' => ['ready_to_close', 'Review reviewer changed'],
        'skipped' => ['skipped', 'Review reviewer changed'],
    ],
];

/** A review of the report in $state, with self and manager ratings filled in, as the workflow would leave it. */
function rsReviewIn(array $p, string $state, array $attrs = []): Review
{
    return rvReview($p['employeeEmp'], $state, array_merge([
        'reviewer_id' => $p['managerEmp']->id,
        'self' => [5, 5, 5],
        'manager' => [4, 5, 3],
    ], match ($state) {
        'closed' => ['final_score' => 380, 'closed_at' => now(), 'closed_by' => $p['hr']->id],
        'skipped' => ['skipped_from' => 'manager_review', 'skip_reason' => 'On long leave'],
        default => [],
    }, $attrs));
}

/** Runs $action on $review with the right actor for it. */
function rsDo(string $action, array $p, Review $review, Employee $newReviewer): Review
{
    return match ($action) {
        'self_draft' => app(SaveSelfRatings::class)->handle($p['employee'], $review, rvRatings($review, [4])),
        'self_submit' => app(SubmitSelfReview::class)->handle($p['employee'], $review, rvRatings($review, [4, 5, 3])),
        'waive' => app(WaiveSelfReview::class)->handle($p['hr'], $review),
        'manager_draft' => app(SaveManagerRatings::class)->handle($p['manager'], $review, rvRatings($review, [2]), 'Draft'),
        'manager_submit' => app(SubmitManagerReview::class)->handle($p['manager'], $review, rvRatings($review, [4, 5, 3]), 'Solid quarter'),
        'send_back' => app(SendBackReview::class)->handle($p['hr'], $review, 'Please add comments'),
        'close' => app(CloseReview::class)->handle($p['hr'], $review),
        'reopen' => app(ReopenReview::class)->handle($p['hr'], $review),
        'skip' => app(SkipReview::class)->handle($p['hr'], $review, 'On long leave'),
        'restore' => app(RestoreReview::class)->handle($p['hr'], $review),
        'change_reviewer' => app(ChangeReviewer::class)->handle($p['hr'], $review, $newReviewer->id),
    };
}

$cells = [];
foreach (RS_ACTIONS as $action) {
    foreach (RS_STATES as $state) {
        $cells["{$action} from {$state}"] = [$action, $state];
    }
}

it('follows the state table for every action and every state', function (string $action, string $state) {
    $review = rsReviewIn($this->p, $state);
    $before = Review::query()->findOrFail($review->id)->getAttributes();
    $scoresBefore = rvScoreRows($review);
    $allowed = RS_ALLOWED[$action][$state] ?? null;

    expect(ReviewStates::canMove($action, $state))->toBe($allowed !== null);

    if ($allowed === null) {
        $errors = rvRefusal(fn () => rsDo($action, $this->p, $review, $this->newReviewer));

        expect($errors)->toBe($action === 'change_reviewer' && $state === 'closed'
            ? ['reviewer_id' => ChangeReviewer::CLOSED]
            : ['review' => ReviewStates::refused($state)])
            ->and(rvAudit())->toHaveCount(0)
            ->and(Review::query()->findOrFail($review->id)->getAttributes())->toBe($before)
            ->and(rvScoreRows($review))->toBe($scoresBefore);

        return;
    }

    [$to, $description] = $allowed;
    rsDo($action, $this->p, $review, $this->newReviewer);

    $fresh = Review::query()->findOrFail($review->id);
    expect($fresh->status)->toBe($to);

    if ($description === null) {
        expect(rvAudit())->toHaveCount(0);
    } else {
        $entries = rvAudit();
        expect($entries)->toHaveCount(1)
            ->and($entries[0]->description)->toBe($description)
            ->and($entries[0]->subject_type)->toBe(Review::class)
            ->and((int) $entries[0]->subject_id)->toBe($review->id)
            ->and($entries[0]->properties['employee_id'])->toBe($this->p['employeeEmp']->id)
            ->and($entries[0]->properties['employee'])->toBe('Sara Ahmed')
            ->and($entries[0]->properties['cycle_id'])->toBe($review->review_cycle_id);
    }
})->with($cells);

it('refuses to submit a self-review in a cycle with self-review off (the review starts with the manager)', function () {
    $cycle = reviewCycleRow(['self_review_enabled' => false]);
    $review = rvReview($this->p['employeeEmp'], ReviewStates::initial($cycle), ['cycle' => $cycle, 'reviewer_id' => $this->p['managerEmp']->id]);

    expect($review->status)->toBe('manager_review')
        ->and(rvRefusal(fn () => app(SubmitSelfReview::class)->handle($this->p['employee'], $review, rvRatings($review, [4, 4, 4]))))
        ->toBe(['review' => ReviewStates::refused('manager_review')])
        ->and(ReviewStates::initial(reviewCycleRow(['self_review_enabled' => true])))->toBe('self_review');
});

it('sends back keeping the ratings; the reviewer edits and resubmits', function () {
    $review = rsReviewIn($this->p, 'ready_to_close', ['manager_submitted_at' => now()]);

    app(SendBackReview::class)->handle($this->p['hr'], $review, '  Please explain Delivery  ');
    $fresh = Review::query()->findOrFail($review->id);

    expect($fresh->status)->toBe('manager_review')
        ->and($fresh->send_back_note)->toBe('Please explain Delivery')
        ->and($fresh->manager_submitted_at)->toBeNull()
        ->and(array_column(rvScoreRows($review), 'manager_rating'))->toBe([4, 5, 3]);

    app(SaveManagerRatings::class)->handle($this->p['manager'], $fresh, rvRatings($fresh, [null, 4]), 'Better');
    expect(array_column(rvScoreRows($review), 'manager_rating'))->toBe([null, 4, 3]);

    app(SubmitManagerReview::class)->handle($this->p['manager'], $fresh, rvRatings($fresh, [5]), 'Better');
    $done = Review::query()->findOrFail($review->id);

    expect($done->status)->toBe('ready_to_close')
        ->and($done->send_back_note)->toBeNull()
        ->and($done->manager_submitted_at)->not->toBeNull()
        ->and(rvAudit('Manager review submitted')->sole()->properties['manager_score'])->toBe(370);
});

it('closes once: the second close is refused, one entry, the score from the first', function () {
    $review = rsReviewIn($this->p, 'ready_to_close');

    app(CloseReview::class)->handle($this->p['hr'], $review);
    expect(rvRefusal(fn () => app(CloseReview::class)->handle($this->p['hr'], $review)))->toBe(['review' => ReviewStates::refused('closed')]);

    $fresh = Review::query()->findOrFail($review->id);
    $entry = rvAudit('Review closed')->sole();

    expect($fresh->final_score)->toBe(380)
        ->and($fresh->closed_by)->toBe($this->p['hr']->id)
        ->and($fresh->closed_at)->not->toBeNull()
        ->and($entry->properties['final_score'])->toBe(380)
        ->and($entry->properties['label'])->toBe('Exceeds')
        ->and($entry->properties['own_review'])->toBeFalse();
});

it('reopen clears the frozen score; closing again freezes a fresh one', function () {
    $review = rsReviewIn($this->p, 'closed');

    app(ReopenReview::class)->handle($this->p['hr'], $review);
    $fresh = Review::query()->findOrFail($review->id);

    expect($fresh->status)->toBe('ready_to_close')
        ->and($fresh->final_score)->toBeNull()
        ->and($fresh->closed_at)->toBeNull()
        ->and($fresh->closed_by)->toBeNull()
        ->and(rvAudit('Review reopened')->sole()->properties['previous_score'])->toBe(380);

    app(SendBackReview::class)->handle($this->p['hr'], $fresh, 'Recheck Teamwork');
    app(SubmitManagerReview::class)->handle($this->p['manager'], $fresh, rvRatings($fresh, [4, 5, 5]), null);
    app(CloseReview::class)->handle($this->p['hr'], $fresh);

    expect(Review::query()->findOrFail($review->id)->final_score)->toBe(480)
        ->and(rvAudit('Review closed')->sole()->properties['label'])->toBe('Outstanding');
});

it('skips from each open state and restores to the state it was skipped from', function (string $from) {
    $review = rsReviewIn($this->p, $from);

    app(SkipReview::class)->handle($this->p['hr'], $review, 'Left mid-quarter');
    $skipped = Review::query()->findOrFail($review->id);

    expect($skipped->status)->toBe('skipped')
        ->and($skipped->skipped_from)->toBe($from)
        ->and($skipped->skip_reason)->toBe('Left mid-quarter')
        ->and(rvAudit('Review skipped')->sole()->properties->only(['reason', 'from'])->all())->toBe(['reason' => 'Left mid-quarter', 'from' => $from]);

    app(RestoreReview::class)->handle($this->p['hr'], $skipped);
    $restored = Review::query()->findOrFail($review->id);

    expect($restored->status)->toBe($from)
        ->and($restored->skipped_from)->toBeNull()
        ->and($restored->skip_reason)->toBeNull()
        ->and(rvAudit('Review restored')->sole()->properties['to'])->toBe($from);
})->with(['self_review', 'manager_review', 'ready_to_close']);

it('refuses a skip or a send back without a note, and one over 500 characters', function () {
    $review = rsReviewIn($this->p, 'ready_to_close');

    expect(rvRefusal(fn () => app(SkipReview::class)->handle($this->p['hr'], $review, '   ')))->toBe(['reason' => SkipReview::REASON_REQUIRED])
        ->and(rvRefusal(fn () => app(SkipReview::class)->handle($this->p['hr'], $review, str_repeat('a', 501))))->toBe(['reason' => SkipReview::REASON_TOO_LONG])
        ->and(rvRefusal(fn () => app(SendBackReview::class)->handle($this->p['hr'], $review, '')))->toBe(['note' => SendBackReview::NOTE_REQUIRED])
        ->and(rvRefusal(fn () => app(SendBackReview::class)->handle($this->p['hr'], $review, str_repeat('a', 501))))->toBe(['note' => SendBackReview::NOTE_TOO_LONG])
        ->and(Review::query()->findOrFail($review->id)->status)->toBe('ready_to_close')
        ->and(rvAudit())->toHaveCount(0);
});

it('refuses every action in a closed cycle', function (string $action, string $state) {
    $cycle = reviewCycleRow(['status' => 'closed', 'closed_at' => now()]);
    $review = rsReviewIn($this->p, $state, ['cycle' => $cycle]);
    $before = Review::query()->findOrFail($review->id)->getAttributes();

    expect(rvRefusal(fn () => rsDo($action, $this->p, $review, $this->newReviewer)))
        ->toBe(['review' => $action === 'reopen' ? ReopenReview::CYCLE_CLOSED : ReviewLock::CYCLE_CLOSED])
        ->and(Review::query()->findOrFail($review->id)->getAttributes())->toBe($before)
        ->and(rvAudit())->toHaveCount(0);
})->with(array_map(fn (string $a) => [$a, RS_ALLOWED[$a] ? array_key_first(RS_ALLOWED[$a]) : 'closed'], array_combine(RS_ACTIONS, RS_ACTIONS)));

it('refuses to submit or close while a KPI is unrated, keeping nothing of the refused save', function () {
    $self = rsReviewIn($this->p, 'self_review', ['self' => [null, null, null]]);

    expect(rvRefusal(fn () => app(SubmitSelfReview::class)->handle($this->p['employee'], $self, rvRatings($self, [4, 5]))))
        ->toBe(['ratings' => 'Rate every KPI from 1 to 5 before submitting.'])
        ->and(array_column(rvScoreRows($self), 'self_rating'))->toBe([null, null, null]);

    $manager = rvReview($this->p['employeeEmp'], 'manager_review', ['reviewer_id' => $this->p['managerEmp']->id, 'cycle_attrs' => []]);
    expect(rvRefusal(fn () => app(SubmitManagerReview::class)->handle($this->p['manager'], $manager, rvRatings($manager, [4]), null)))
        ->toBe(['ratings' => 'Rate every KPI from 1 to 5 before submitting.']);

    $odd = rvReview(Employee::factory()->create(['manager_id' => $this->p['managerEmp']->id]), 'ready_to_close', ['manager' => [4, 4]]);
    expect(rvRefusal(fn () => app(CloseReview::class)->handle($this->p['hr'], $odd)))
        ->toBe(['ratings' => 'Rate every KPI from 1 to 5 before submitting.'])
        ->and(rvAudit())->toHaveCount(0);
});

it('stores the self score on submit and keeps drafts unaudited', function () {
    $review = rsReviewIn($this->p, 'self_review', ['self' => [null, null, null]]);

    app(SaveSelfRatings::class)->handle($this->p['employee'], $review, rvRatings($review, [4, null], 'Draft note'));
    expect(rvAudit())->toHaveCount(0)
        ->and(rvScoreRows($review)[0])->toBe(['self_rating' => 4, 'self_comment' => 'Draft note', 'manager_rating' => 4, 'manager_comment' => null]);

    app(SubmitSelfReview::class)->handle($this->p['employee'], $review, rvRatings($review, [4, 5, 3]));
    $fresh = Review::query()->findOrFail($review->id);

    expect($fresh->self_score)->toBe(380)
        ->and($fresh->self_submitted_at)->not->toBeNull()
        ->and(rvAudit('Self-review submitted')->sole()->properties['self_score'])->toBe(380);
});

it('refuses ratings outside 1 to 5 and comments over 1,000 characters', function () {
    $review = rsReviewIn($this->p, 'self_review', ['self' => [null, null, null]]);

    expect(rvRefusal(fn () => app(SaveSelfRatings::class)->handle($this->p['employee'], $review, rvRatings($review, [6]))))
        ->toBe(['ratings' => 'A rating must be a whole number from 1 to 5.'])
        ->and(rvRefusal(fn () => app(SaveSelfRatings::class)->handle($this->p['employee'], $review, rvRatings($review, [0]))))
        ->toBe(['ratings' => 'A rating must be a whole number from 1 to 5.'])
        ->and(rvRefusal(fn () => app(SaveSelfRatings::class)->handle($this->p['employee'], $review, rvRatings($review, [3], str_repeat('x', 1001)))))
        ->toBe(['ratings' => 'A comment can be at most 1,000 characters.'])
        ->and(array_column(rvScoreRows($review), 'self_rating'))->toBe([null, null, null]);
});

it('never deletes a review KPI line', function () {
    $review = rsReviewIn($this->p, 'manager_review');

    expect(fn () => ReviewScore::query()->where('review_id', $review->id)->first()->delete())->toThrow(LogicException::class)
        ->and(ReviewScore::query()->where('review_id', $review->id)->count())->toBe(3);
});
