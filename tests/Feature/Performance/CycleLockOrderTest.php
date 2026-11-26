<?php

use App\Actions\Performance\AddReviewPeople;
use App\Actions\Performance\ChangeCycleDueDate;
use App\Actions\Performance\CloseReviewCycle;
use App\Actions\Performance\OpenReviewCycle;
use App\Models\Review;
use App\Models\ReviewCycle;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

// Statement order and locks, with the technique of AssignmentLockOrderTest: SQLite drops the lock clause, so while an action
// runs the grammar writes the lock as a trailing comment ("/* for update */", "/* shared */"). MySqlGrammar shows the same
// lock compiles to FOR UPDATE on MariaDB. Order: the cycle row(s) first, then review rows; never a review then a cycle.

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

/** @return list<string> */
function clStatements(Closure $run): array
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
        && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions') && ! str_contains($sql, 'activity_log')
        && ! str_contains($sql, '"settings"')));
}

function clLocks(string $sql): bool
{
    return str_ends_with($sql, '/* for update */');
}

/** Index of the first statement touching $table, or PHP_INT_MAX. */
function clFirst(array $statements, string $table, ?bool $locked = null): int
{
    foreach ($statements as $i => $sql) {
        if (str_contains($sql, "\"{$table}\"") && ($locked === null || clLocks($sql) === $locked)) {
            return $i;
        }
    }

    return PHP_INT_MAX;
}

it('compiles the cycle and review locks to FOR UPDATE', function () {
    $grammar = new MySqlGrammar(DB::connection());

    expect($grammar->compileSelect(ReviewCycle::query()->lockForUpdate()->toBase()))->toContain('from `review_cycles`')->toEndWith('for update')
        ->and($grammar->compileSelect(Review::query()->whereKey([1, 2])->lockForUpdate()->toBase()))->toContain('from `reviews`')->toEndWith('for update');
});

it('open: every cycle row locked first, and no reviews read before it', function () {
    reviewCycleRow();
    cyPerson();
    cyPerson(['kpi' => false]);
    $hr = userWithRole('hr');

    $statements = clStatements(fn () => app(OpenReviewCycle::class)->handle($hr, '2026-Q3', null));

    expect($statements[0])->toStartWith('select')->toContain('from "review_cycles"')->not->toContain('where')->toEndWith('/* for update */')
        ->and(clFirst($statements, 'reviews'))->toBeGreaterThan(0)
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, 'insert into "reviews"')))->toHaveCount(1)
        ->and(array_filter($statements, fn ($sql) => clLocks($sql) && ! str_contains($sql, '"review_cycles"')))->toBeEmpty();
});

it('open refused: still the cycle rows first', function () {
    $hr = userWithRole('hr');

    $statements = clStatements(fn () => rescue(fn () => app(OpenReviewCycle::class)->handle($hr, '2027-Q1', null), report: false));

    expect($statements[0])->toContain('from "review_cycles"')->toEndWith('/* for update */');
});

it('add people: the cycle row FOR UPDATE by id first, then plain reads and inserts', function () {
    $cycle = reviewCycleRow(['period_end' => '2026-09-30']);
    $person = cyPerson();
    $hr = userWithRole('hr');

    $statements = clStatements(fn () => app(AddReviewPeople::class)->handle($hr, $cycle, [$person->id]));

    expect($statements[0])->toStartWith('select')->toContain('from "review_cycles" where "review_cycles"."id" = ?')->toEndWith('/* for update */')
        ->and(array_filter(array_slice($statements, 1), fn ($sql) => clLocks($sql)))->toBeEmpty()
        ->and(array_filter($statements, fn ($sql) => str_contains($sql, 'insert into "reviews"')))->toHaveCount(1);
});

it('close: the cycle row FOR UPDATE, then the review ids, then the review rows FOR UPDATE by id', function () {
    $cycle = reviewCycleRow();
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    reviewRow($cycle, cyPerson(), ['status' => 'skipped', 'scores' => []]);
    $hr = userWithRole('hr');

    $statements = clStatements(fn () => app(CloseReviewCycle::class)->handle($hr, $cycle));

    expect($statements[0])->toContain('from "review_cycles" where "review_cycles"."id" = ?')->toEndWith('/* for update */')
        ->and($statements[1])->toStartWith('select')->toContain('from "reviews" where "review_cycle_id" = ?')
        ->and(clLocks($statements[1]))->toBeFalse()
        ->and($statements[2])->toStartWith('select')->toContain('from "reviews" where "reviews"."id" in')->toEndWith('/* for update */');
});

it('due date: only the cycle row, FOR UPDATE by id', function () {
    // A fixed period: the factory's counter moves the default period_end forward with every cycle made in the process.
    $cycle = reviewCycleRow(['period_end' => '2026-09-30', 'due_on' => '2026-10-15']);
    $hr = userWithRole('hr');

    $statements = clStatements(fn () => app(ChangeCycleDueDate::class)->handle($hr, $cycle, '2026-12-31'));

    expect($statements[0])->toContain('from "review_cycles" where "review_cycles"."id" = ?')->toEndWith('/* for update */')
        ->and(clFirst($statements, 'reviews'))->toBe(PHP_INT_MAX);
});

it('no path locks reviews before review_cycles, and none locks employees, payroll, leave or assets', function (string $path) {
    $cycle = reviewCycleRow(['period_end' => '2026-09-30']);
    reviewRow($cycle, cyPerson(), ['status' => 'closed', 'scores' => []]);
    $person = cyPerson();
    $hr = userWithRole('hr');

    // No rescue(): a refusal would end the path early and the order would be checked on nothing. Each path must succeed.
    $statements = clStatements(fn () => match ($path) {
        'open' => app(OpenReviewCycle::class)->handle($hr, '2026-Q4', null),
        'add' => app(AddReviewPeople::class)->handle($hr, $cycle, [$person->id]),
        'close' => app(CloseReviewCycle::class)->handle($hr, $cycle),
        'due' => app(ChangeCycleDueDate::class)->handle($hr, $cycle, '2026-12-31'),
    });

    $cycleLock = clFirst($statements, 'review_cycles', locked: true);
    $reviewLock = clFirst($statements, 'reviews', locked: true);

    expect($cycleLock)->toBe(0)
        ->and($reviewLock === PHP_INT_MAX || $reviewLock > $cycleLock)->toBeTrue()
        ->and(array_filter($statements, fn ($sql) => clLocks($sql) && preg_match('/"(employees|payroll_\w+|leave_\w+|asset\w*)"/', $sql) === 1))->toBeEmpty();
})->with(['open', 'add', 'close', 'due']);
