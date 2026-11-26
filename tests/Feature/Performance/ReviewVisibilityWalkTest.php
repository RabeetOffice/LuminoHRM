<?php

use App\Models\Review;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/Support/review-helpers.php';

/*
 * The anti-goal test (spec 5.7): one review with sentinel values is walked through every state as every kind of viewer, and
 * every Inertia page that can carry a review is requested three ways (full page, X-Inertia JSON, partial reload). Whatever a
 * viewer may not see must appear nowhere in the whole response: not a sentinel text, not a key, not a number.
 *
 * Tasks 6 and 7 extend walkPages() for the profile tab, lists and cards: a page added there is checked against every rule.
 */

const WALK_MANAGER_KEYS = ['manager_rating', 'manager_comment', 'manager_score', 'final_score', 'label'];
const WALK_NOTE_KEYS = ['send_back_note', 'skip_reason'];
const WALK_MANAGER_TEXT = ['MGR-SECRET-COMMENT', 'MGR-KPI-COMMENT'];
const WALK_NOTE_TEXT = ['HR-SECRET-NOTE', 'SKIP-SECRET-REASON'];
const WALK_SELF_TEXT = 'SELF-COMMENT';

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
    $this->p = rvPeople();
    $this->admin = userWithRole('admin');
    $this->adminEmp = employeeFor($this->admin, ['first_name' => 'Asad', 'last_name' => 'Ali']);
    $this->otherManager = userWithRole('manager');
    $this->otherManagerEmp = employeeFor($this->otherManager, ['first_name' => 'Bilal', 'last_name' => 'Shah']);
    $this->otherEmployee = userWithRole('employee');
    employeeFor($this->otherEmployee, ['first_name' => 'Nida', 'last_name' => 'Iqbal', 'manager_id' => $this->otherManagerEmp->id]);
});

/** The review with the sentinels, in $state. Reviewed by $reviewerEmp; manager ratings 5, 1, 5 (score 3.80), stored final 4.12. */
function walkReview($employeeEmp, $reviewerEmp, string $state): Review
{
    $past = $state !== 'self_review';

    $review = rvReview($employeeEmp, in_array($state, ['reopened'], true) ? 'closed' : $state, [
        'reviewer_id' => $reviewerEmp->id,
        'self' => [4, 3, 2],
        'manager' => [5, 1, 5],
        'manager_comment' => 'MGR-SECRET-COMMENT',
        'send_back_note' => 'HR-SECRET-NOTE',
        'skip_reason' => 'SKIP-SECRET-REASON',
        'skipped_from' => $state === 'skipped' ? 'ready_to_close' : null,
        'self_submitted_at' => $past ? now() : null,
        'self_score' => $past ? 270 : null,
        'final_score' => $state === 'reopened' ? 380 : 412,
        'closed_at' => in_array($state, ['closed', 'reopened'], true) ? now() : null,
    ]);

    foreach ($review->scores as $i => $score) {
        $score->forceFill([
            'self_comment' => WALK_SELF_TEXT.'-'.($i + 1),
            'manager_comment' => 'MGR-KPI-COMMENT-'.($i + 1),
        ])->save();
    }

    if ($state === 'reopened') {
        // The real action: reopen as Admin (never the reviewee), which clears the final score.
        test()->actingAs(test()->admin)->post(route('performance.reviews.reopen', $review->id))->assertRedirect();
        auth()->logout();
    }

    return $review->refresh();
}

/** Every page that can carry a review, as [name, url]. Pages another task has not added yet are left out. */
function walkPages(Review $review): array
{
    $pages = [
        ['review', route('performance.reviews.show', $review->id)],
        ['dashboard', '/dashboard'],
        ['profile', route('employees.show', $review->employee_id)],
    ];

    foreach (['my.reviews', 'performance.reviews.index'] as $name) {
        if (Route::has($name)) {
            $pages[] = [$name, route($name)];
        }
    }

    if (Route::has('performance.cycles.show')) {
        $pages[] = ['cycle', route('performance.cycles.show', $review->review_cycle_id)];
    }

    return $pages;
}

/**
 * Request one page as $user three ways and return the decoded props of each (and the raw text) for the scans.
 *
 * @return list<array{text: string, props: array<string, mixed>, status: int}>
 */
function walkRequest(User $user, string $url): array
{
    $test = test();
    $out = [];

    $html = $test->actingAs($user)->get($url);
    $page = null;

    try {
        $page = $html->viewData('page');
    } catch (Throwable) {
        // Not an Inertia page (a 403, a redirect): only the text is scanned.
    }

    $page = is_array($page) ? $page : null;

    // The page's props as the browser gets them, plus the raw text of the response.
    $out[] = [
        'text' => html_entity_decode($html->getContent()).json_encode($page['props'] ?? []),
        'props' => $page['props'] ?? [],
        'status' => $html->getStatusCode(),
    ];

    if ($page === null) {
        return $out;
    }

    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) ($page['version'] ?? '')];
    $json = $test->actingAs($user)->withHeaders($headers)->get($url);
    $out[] = ['text' => $json->getContent(), 'props' => (array) ($json->json('props') ?? []), 'status' => $json->getStatusCode()];

    $partial = $test->actingAs($user)->withHeaders($headers + [
        'X-Inertia-Partial-Component' => (string) $page['component'],
        'X-Inertia-Partial-Data' => implode(',', array_keys($page['props'] ?? [])),
    ])->get($url);
    $out[] = ['text' => $partial->getContent(), 'props' => (array) ($partial->json('props') ?? []), 'status' => $partial->getStatusCode()];

    return $out;
}

/** Every key and scalar in a nested array. */
function walkCollect(mixed $data, array &$keys, array &$scalars): void
{
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $keys[$key] = true;
            }
            walkCollect($value, $keys, $scalars);
        }

        return;
    }

    $scalars[] = $data;
}

/** The sentinels, keys and numbers found in one response. */
function walkFindings(array $response): array
{
    $keys = [];
    $scalars = [];
    walkCollect($response['props'], $keys, $scalars);
    $text = $response['text'];

    // Keys also by their quoted form in the raw text (a page may embed props another way).
    foreach (array_merge(WALK_MANAGER_KEYS, WALK_NOTE_KEYS, ['self_rating']) as $key) {
        if (str_contains($text, '"'.$key.'":')) {
            $keys[$key] = true;
        }
    }

    $found = [];

    foreach (array_merge(WALK_MANAGER_TEXT, WALK_NOTE_TEXT, [WALK_SELF_TEXT]) as $needle) {
        if (str_contains($text, $needle)) {
            $found[] = $needle;
        }
    }

    foreach ($keys as $key => $_) {
        $found[] = 'key:'.$key;
    }

    // The numbers: the manager score (380 / "3.80") and the stored final score (412 / "4.12").
    foreach ($scalars as $scalar) {
        if (in_array($scalar, [380, 412, '3.80', '4.12'], true)) {
            $found[] = 'number:'.$scalar;
        }
    }

    return $found;
}

/** The rule for each kind of viewer. Returns the findings that must not exist. */
function walkForbidden(string $kind, string $state, array $found): array
{
    $bad = [];

    foreach ($found as $item) {
        $leak = match ($kind) {
            'reviewee' => in_array($item, array_map(fn ($k) => 'key:'.$k, WALK_NOTE_KEYS), true)
                || in_array($item, WALK_NOTE_TEXT, true)
                || ($state !== 'closed' && (
                    in_array($item, array_map(fn ($k) => 'key:'.$k, WALK_MANAGER_KEYS), true)
                    || in_array($item, WALK_MANAGER_TEXT, true)
                    || str_starts_with($item, 'number:')
                )),
            'reviewer' => $state === 'self_review' && ($item === WALK_SELF_TEXT || $item === 'key:self_rating'),
            'none' => true,
            default => false,
        };

        if ($leak) {
            $bad[] = $item;
        }
    }

    return $bad;
}

/** @return array<string, array{0: string, 1: string}> viewer label => [property of the test, kind] for each scenario */
function walkViewers(string $scenario): array
{
    return match ($scenario) {
        'plain' => [
            'the employee' => ['employee', 'reviewee'],
            'the reviewer' => ['manager', 'reviewer'],
            'HR' => ['hr', 'hr'],
            'Admin' => ['admin', 'hr'],
            'another manager' => ['otherManager', 'none'],
            'another employee' => ['otherEmployee', 'none'],
        ],
        'hr is the employee' => [
            'HR on their own review' => ['hr', 'reviewee'],
            'the reviewer' => ['manager', 'reviewer'],
            'Admin' => ['admin', 'hr'],
            'another manager' => ['otherManager', 'none'],
            'another employee' => ['otherEmployee', 'none'],
        ],
        'hr is the reviewer' => [
            'the employee' => ['employee', 'reviewee'],
            'HR who is the reviewer' => ['hr', 'hr'],
            'Admin' => ['admin', 'hr'],
            'another manager' => ['otherManager', 'none'],
        ],
    };
}

function walkSetup(string $scenario, string $state): Review
{
    $p = test()->p;

    return match ($scenario) {
        'plain' => walkReview($p['employeeEmp'], $p['managerEmp'], $state),
        'hr is the employee' => walkReview($p['hrEmp'], $p['managerEmp'], $state),
        'hr is the reviewer' => walkReview($p['employeeEmp'], $p['hrEmp'], $state),
    };
}

it('shows each viewer only what the matrix allows, on every page, in every state', function (string $scenario, string $state) {
    $review = walkSetup($scenario, $state);
    $checked = 0;

    foreach (walkViewers($scenario) as $label => [$property, $kind]) {
        $user = $property === 'hr' || $property === 'manager' || $property === 'employee'
            ? $this->p[$property]
            : $this->{$property};

        foreach (walkPages($review) as [$name, $url]) {
            foreach (walkRequest($user, $url) as $response) {
                $found = walkFindings($response);

                // "label" is also an ordinary key on pages that are not about scores (dashboard tiles, filters, option lists): there it is judged by the sentinels and numbers. On the review page and My Reviews it IS the score label, so it is checked (the profile page has tab labels; its score label is covered by the sentinels, final_score and the numbers).
                if (! in_array($name, ['review', 'my.reviews'], true)) {
                    $found = array_values(array_diff($found, ['key:label']));
                }

                $bad = walkForbidden($kind, $state, $found);

                expect($bad)->toBe([], "{$label} on {$name} in {$state} ({$scenario}) saw: ".implode(', ', $bad));
                $checked++;
            }
        }

        $reviewPage = $this->actingAs($user)->get(route('performance.reviews.show', $review->id));

        if ($kind === 'none') {
            $reviewPage->assertForbidden();
        } else {
            $reviewPage->assertOk();
        }
    }

    expect($checked)->toBeGreaterThan(20);
})->with(function () {
    foreach (['plain', 'hr is the employee', 'hr is the reviewer'] as $scenario) {
        foreach (['self_review', 'manager_review', 'ready_to_close', 'closed', 'skipped', 'reopened'] as $state) {
            yield "{$scenario}: {$state}" => [$scenario, $state];
        }
    }
});

it('is not vacuous: HR sees everything the employee must not', function () {
    $review = walkReview($this->p['employeeEmp'], $this->p['managerEmp'], 'ready_to_close');

    $found = walkFindings(walkRequest($this->p['hr'], route('performance.reviews.show', $review->id))[0]);

    expect($found)->toContain('MGR-SECRET-COMMENT', 'MGR-KPI-COMMENT', 'HR-SECRET-NOTE', 'SKIP-SECRET-REASON', 'key:manager_rating', 'key:send_back_note', 'key:skip_reason', 'number:380');
});

it('shows the employee the manager side once closed, and hides it again after a reopen', function () {
    $review = walkReview($this->p['employeeEmp'], $this->p['managerEmp'], 'closed');
    $url = route('performance.reviews.show', $review->id);

    $closed = walkFindings(walkRequest($this->p['employee'], $url)[0]);

    expect($closed)->toContain('MGR-SECRET-COMMENT', 'key:manager_rating', 'key:final_score', 'number:412')
        ->and($closed)->not->toContain('HR-SECRET-NOTE')->not->toContain('SKIP-SECRET-REASON');

    $this->actingAs($this->admin)->post(route('performance.reviews.reopen', $review->id))->assertRedirect();

    $reopened = walkFindings(walkRequest($this->p['employee'], $url)[0]);

    expect($reopened)->not->toContain('MGR-SECRET-COMMENT')->not->toContain('key:manager_rating')->not->toContain('key:final_score')
        ->and(array_filter($reopened, fn ($f) => str_starts_with($f, 'number:')))->toBe([]);
});

it('keeps the employee draft away from the reviewer until it is submitted', function () {
    $review = walkReview($this->p['employeeEmp'], $this->p['managerEmp'], 'self_review');
    $url = route('performance.reviews.show', $review->id);

    $draft = walkFindings(walkRequest($this->p['manager'], $url)[0]);

    expect($draft)->not->toContain(WALK_SELF_TEXT)->not->toContain('key:self_rating');

    // The employee submits through the real route; now the reviewer sees the self side.
    $ratings = rvRatings($review, [4, 3, 2], WALK_SELF_TEXT);
    $this->actingAs($this->p['employee'])->post(route('performance.reviews.self-submit', $review->id), ['ratings' => $ratings])->assertRedirect();

    $submitted = walkFindings(walkRequest($this->p['manager'], $url)[0]);

    expect($submitted)->toContain(WALK_SELF_TEXT, 'key:self_rating');
});
