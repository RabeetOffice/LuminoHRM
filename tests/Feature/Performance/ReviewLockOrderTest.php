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
use App\Models\Review;
use App\Models\ReviewCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/review-helpers.php';

// Statement order and locks (the technique of tests/Feature/Assets/AssignmentLockOrderTest.php). SQLite drops the lock
// clause, so while an action runs the grammar writes it as a trailing comment ("/* for update */", "/* shared */").
// Order for every review action: a plain read of the review's cycle id, the cycle row shared, the review row FOR UPDATE.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

/** @return list<string> */
function rloStatements(Closure $run): array
{
    $connection = DB::connection();
    $original = $connection->getQueryGrammar();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        protected function compileLock(Builder $query, $value)
        {
            return match (true) {
                $value === true => '/* for update */',
                $value === false => '/* shared */',
                default => '',
            };
        }
    });

    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    try {
        $run();
    } finally {
        $connection->setQueryGrammar($original);
    }

    return array_values(array_filter($statements, fn (string $sql) => ! str_contains($sql, 'savepoint')
        && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions') && ! str_contains($sql, 'activity_log')));
}

it('compiles the cycle lock to a shared lock and the review lock to FOR UPDATE on MariaDB', function () {
    $grammar = new MySqlGrammar(DB::connection());

    expect($grammar->compileSelect(ReviewCycle::query()->whereKey(1)->sharedLock()->toBase()))
        ->toContain('from `review_cycles` where `review_cycles`.`id` = ?')->toEndWith('lock in share mode')
        ->and($grammar->compileSelect(Review::query()->whereKey(1)->lockForUpdate()->toBase()))
        ->toContain('from `reviews` where `reviews`.`id` = ?')->toEndWith('for update');
});

it('takes the cycle row (shared) before the review row (FOR UPDATE), and no other lock', function (string $action) {
    $p = rvPeople();
    $other = userWithRole('manager');
    $bilal = employeeFor($other);
    $state = match ($action) {
        'self draft', 'self submit', 'waive' => 'self_review',
        'manager draft', 'manager submit', 'skip', 'change reviewer' => 'manager_review',
        'send back', 'close' => 'ready_to_close',
        'reopen' => 'closed',
        'restore' => 'skipped',
    };
    $review = rvReview($p['employeeEmp'], $state, [
        'reviewer_id' => $p['managerEmp']->id, 'manager' => [4, 5, 3], 'skipped_from' => 'manager_review',
        'final_score' => $state === 'closed' ? 380 : null,
    ]);
    $fresh = Review::query()->findOrFail($review->id);
    $one = rvRatings($review, [4]);
    $all = rvRatings($review, [4, 4, 4]);

    $statements = rloStatements(fn () => match ($action) {
        'self draft' => app(SaveSelfRatings::class)->handle($p['employee'], $fresh, $one),
        'self submit' => app(SubmitSelfReview::class)->handle($p['employee'], $fresh, $all),
        'waive' => app(WaiveSelfReview::class)->handle($p['hr'], $fresh),
        'manager draft' => app(SaveManagerRatings::class)->handle($p['manager'], $fresh, $one, 'x'),
        'manager submit' => app(SubmitManagerReview::class)->handle($p['manager'], $fresh, $all, null),
        'send back' => app(SendBackReview::class)->handle($p['hr'], $fresh, 'Redo'),
        'close' => app(CloseReview::class)->handle($p['hr'], $fresh),
        'reopen' => app(ReopenReview::class)->handle($p['hr'], $fresh),
        'skip' => app(SkipReview::class)->handle($p['hr'], $fresh, 'Left'),
        'restore' => app(RestoreReview::class)->handle($p['hr'], $fresh),
        'change reviewer' => app(ChangeReviewer::class)->handle($p['hr'], $fresh, $bilal->id),
    });

    expect($statements[0])->toStartWith('select "review_cycle_id" from "reviews" where "reviews"."id" = ?')->not->toContain('/*')
        ->and($statements[1])->toStartWith('select')->toContain('from "review_cycles" where "review_cycles"."id" = ?')->toEndWith('/* shared */')
        ->and($statements[2])->toStartWith('select')->toContain('from "reviews" where "reviews"."id" = ?')->toEndWith('/* for update */');

    $locking = array_values(array_filter($statements, fn ($sql) => str_contains($sql, '/*')));
    $lockedTables = array_map(fn ($sql) => preg_match('/from "([a-z_]+)"/', $sql, $m) ? $m[1] : '?', $locking);

    // Only the cycle, the review and (for rating writes) the review's own score rows are ever locked.
    expect(array_diff($lockedTables, ['review_cycles', 'reviews', 'review_scores']))->toBe([])
        ->and(array_search('review_cycles', $lockedTables, true))->toBe(0)
        ->and(array_keys($lockedTables, 'review_cycles', true))->toBe([0])
        ->and(array_filter($statements, fn ($sql) => (str_contains($sql, '"employees"') || str_contains($sql, '"payroll_')
            || str_contains($sql, '"leave_') || str_contains($sql, '"asset')) && str_contains($sql, '/*')))->toBeEmpty();
})->with(['self draft', 'self submit', 'waive', 'manager draft', 'manager submit', 'send back', 'close', 'reopen', 'skip', 'restore', 'change reviewer']);
