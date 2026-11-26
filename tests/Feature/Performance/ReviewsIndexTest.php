<?php

use App\Models\ReviewCycle;
use App\Support\Performance\ReviewStates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

/** A manager with an employee record and `$n` reports, each with a review in $cycle in $state. */
function riTeam(int $n, ?ReviewCycle $cycle = null, string $state = 'manager_review'): array
{
    $manager = userWithRole('manager');
    $managerEmp = employeeFor($manager, ['first_name' => 'Omar', 'last_name' => 'Khan']);
    $cycle ??= reviewCycleRow();
    $reviews = [];

    for ($i = 0; $i < $n; $i++) {
        $person = cyPerson(['manager_id' => $managerEmp->id, 'first_name' => 'Rep'.chr(65 + $i), 'last_name' => 'Person']);
        $reviews[] = reviewRow($cycle, $person, ['status' => $state, 'reviewer_id' => $managerEmp->id]);
    }

    return [$manager, $managerEmp, $cycle, $reviews];
}

// ---------------------------------------------------------------- HR and Admin: the cycles

it('shows HR and Admin the cycles with progress and the periods they can open', function (string $role) {
    $a = cyPerson();
    $b = cyPerson();
    $cycle = cyOpen(null, '2026-Q3', '2026-10-14');
    DB::table('reviews')->where('employee_id', $a->id)->update(['status' => 'closed']);

    $this->actingAs(userWithRole($role))->get('/performance/reviews')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('performance/reviews')
            ->where('view', 'cycles')
            ->has('cycles', 1)
            ->where('cycles.0.id', $cycle->id)
            ->where('cycles.0.name', 'Q3 2026 (Jul–Sep)')
            ->where('cycles.0.status', 'open')
            ->where('cycles.0.due_on', '2026-10-14')
            ->where('cycles.0.overdue', true)
            ->where('cycles.0.total', 2)
            ->where('cycles.0.counts.closed', 1)
            ->where('cycles.0.counts.self_review', 1)
            ->where('cycles.0.progress', '1 of 2 closed')
            ->where('open_options.type', 'quarterly')
            ->where('open_options.periods.0.key', '2026-Q4')
            ->where('open_options.periods.0.due_on', '2027-01-14')
            ->where('can.open', true)
            ->missing('team'));
})->with(['admin', 'hr']);

it('leaves a period that overlaps a cycle out of the choices', function () {
    cyPerson();
    cyOpen();

    $this->actingAs(userWithRole('hr'))->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->where('open_options.periods', fn ($periods) => collect($periods)->pluck('key')->doesntContain('2026-Q3')
            && collect($periods)->pluck('key')->contains('2026-Q4')));
});

it('marks a cycle overdue only after its due date and only while open', function () {
    $today = reviewCycleRow(['period_key' => '2026-10', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'due_on' => '2026-11-26']);
    $late = reviewCycleRow(['period_key' => '2026-09', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'due_on' => '2026-11-25']);
    $lateClosed = reviewCycleRow(['period_key' => '2026-08', 'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'due_on' => '2026-09-14', 'status' => 'closed']);

    $this->actingAs(userWithRole('hr'))->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p
            ->where('cycles', fn ($rows) => collect($rows)->pluck('overdue', 'id')->all() === [$today->id => false, $late->id => true, $lateClosed->id => false]));
});

it('says so when a cycle has no reviews yet', function () {
    $cycle = reviewCycleRow();

    $this->actingAs(userWithRole('hr'))->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->where('cycles.0.progress', 'No reviews yet')->where('cycles.0.total', 0));
});

it('does not count skipped reviews as work to close', function () {
    $a = cyPerson();
    $b = cyPerson();
    cyOpen();
    DB::table('reviews')->where('employee_id', $a->id)->update(['status' => 'skipped']);
    DB::table('reviews')->where('employee_id', $b->id)->update(['status' => 'closed']);

    $this->actingAs(userWithRole('hr'))->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->where('cycles.0.progress', '1 of 1 closed')->where('cycles.0.total', 2)->where('cycles.0.counts.skipped', 1));
});

// ---------------------------------------------------------------- Manager: the team

it('gives a manager only the team list: their reviews in open cycles, no cycles and no buttons', function () {
    [$manager, , $cycle, $reviews] = riTeam(2);
    $other = cyPerson(['first_name' => 'Stranger']);
    reviewRow($cycle, $other, ['status' => 'manager_review']);

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('performance/reviews')
            ->where('view', 'team')
            ->has('team.data', 2)
            ->where('team.total', 2)
            ->where('team.per_page', 25)
            ->where('team.data.0.id', $reviews[0]->id)
            ->where('team.data.0.employee.name', 'RepA Person')
            ->where('team.data.0.cycle', $cycle->name)
            ->where('team.data.0.state', 'manager_review')
            ->where('team.data.0.reviewer', 'Omar Khan')
            ->where('team.data.0.awaiting_me', true)
            ->where('team.data.0.url', "/performance/reviews/{$reviews[0]->id}")
            ->missing('cycles')
            ->missing('open_options')
            ->missing('can'));
});

it('keeps scores and manager data off every team row that is not closed', function () {
    [$manager, $managerEmp, $cycle] = riTeam(0);

    foreach (['self_review', 'manager_review', 'ready_to_close', 'skipped'] as $state) {
        $person = cyPerson(['manager_id' => $managerEmp->id]);
        reviewRow($cycle, $person, [
            'status' => $state, 'reviewer_id' => $managerEmp->id, 'self_score' => 333, 'final_score' => 444,
            'manager_comment' => 'MGR-SECRET', 'skip_reason' => 'SKIP-SECRET', 'send_back_note' => 'NOTE-SECRET',
        ]);
    }

    $response = $this->actingAs($manager)->get('/performance/reviews')->assertOk();
    $props = json_encode($response->viewData('page')['props']);

    expect($props)->not->toContain('MGR-SECRET')->not->toContain('SKIP-SECRET')->not->toContain('NOTE-SECRET')
        ->not->toContain('final_score')->not->toContain('self_score')->not->toContain('manager_rating')->not->toContain('manager_comment')->not->toContain('manager_score')
        ->not->toContain('333')->not->toContain('444')->not->toContain('"score"')->not->toContain('"label"');
});

it('shows the final score and label on a closed row of a current report', function () {
    [$manager, $managerEmp, $cycle] = riTeam(0, reviewCycleRow(['status' => 'closed']));
    $person = cyPerson(['manager_id' => $managerEmp->id]);
    reviewRow($cycle, $person, ['status' => 'closed', 'reviewer_id' => $managerEmp->id, 'final_score' => 412]);

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->has('team.data', 1)
            ->where('team.data.0.score', 412)
            ->where('team.data.0.label', 'Exceeds')
            ->where('team.data.0.awaiting_me', false));
});

it('leaves the closed review of a former report out of the manager list', function () {
    [$manager, $managerEmp, $cycle] = riTeam(0, reviewCycleRow(['status' => 'closed']));
    $former = cyPerson(['manager_id' => $managerEmp->id]);
    reviewRow($cycle, $former, ['status' => 'closed', 'reviewer_id' => $managerEmp->id, 'final_score' => 300]);
    $former->forceFill(['manager_id' => null])->save();

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->has('team.data', 0));
});

it('leaves the manager own review off the team list', function () {
    [$manager, $managerEmp, $cycle] = riTeam(1);
    reviewRow($cycle, $managerEmp, ['status' => 'self_review', 'reviewer_id' => null]);

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->has('team.data', 1)->where('team.data.0.employee.name', 'RepA Person'));
});

it('narrows the team list by cycle, state and search, and ignores junk filters', function () {
    [$manager, $managerEmp, $cycle] = riTeam(0);
    $second = reviewCycleRow();
    $anna = cyPerson(['manager_id' => $managerEmp->id, 'first_name' => 'Anna', 'last_name' => 'Zed']);
    $bilal = cyPerson(['manager_id' => $managerEmp->id, 'first_name' => 'Bilal', 'last_name' => 'Yar']);
    reviewRow($cycle, $anna, ['status' => 'manager_review', 'reviewer_id' => $managerEmp->id]);
    reviewRow($second, $bilal, ['status' => 'ready_to_close', 'reviewer_id' => $managerEmp->id]);

    $this->actingAs($manager);
    $this->get("/performance/reviews?cycle={$second->id}")->assertInertia(fn ($p) => $p->has('team.data', 1)->where('team.data.0.employee.name', 'Bilal Yar'));
    $this->get('/performance/reviews?state=manager_review')->assertInertia(fn ($p) => $p->has('team.data', 1)->where('team.data.0.employee.name', 'Anna Zed'));
    $this->get('/performance/reviews?search=bil')->assertInertia(fn ($p) => $p->has('team.data', 1)->where('filters.search', 'bil'));
    $this->get('/performance/reviews?state[]=x&cycle=abc&search[]=1')->assertOk()->assertInertia(fn ($p) => $p->has('team.data', 2)->where('filters.state', '')->where('filters.cycle', null));
    $this->get('/performance/reviews?state=nonsense')->assertOk()->assertInertia(fn ($p) => $p->has('team.data', 2));
});

it('offers a manager only the cycles they have reviews in', function () {
    [$manager, , $cycle] = riTeam(1);
    reviewCycleRow(); // one they have nothing in

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->has('options.cycles', 1)->where('options.cycles.0.id', $cycle->id));
});

it('pages the team list 25 at a time and sends a page past the end to the last page', function () {
    [$manager] = riTeam(27);

    $this->actingAs($manager)->get('/performance/reviews')
        ->assertInertia(fn ($p) => $p->has('team.data', 25)->where('team.last_page', 2)->where('team.total', 27));
    $this->get('/performance/reviews?page=2')->assertInertia(fn ($p) => $p->has('team.data', 2));
    $this->get('/performance/reviews?page=9')->assertRedirect('/performance/reviews?page=2');
});

it('runs the same number of queries for 5 and for 25 team reviews', function () {
    [$manager, $managerEmp, $cycle] = riTeam(5);

    $count = function () use ($manager) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($manager)->get('/performance/reviews')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // the first request loads the account's permissions
    $five = $count();

    for ($i = 0; $i < 20; $i++) {
        reviewRow($cycle, cyPerson(['manager_id' => $managerEmp->id]), ['status' => 'manager_review', 'reviewer_id' => $managerEmp->id]);
    }

    expect($count())->toBe($five);
});

// ---------------------------------------------------------------- access

it('keeps Employee out and sends a guest to sign in', function () {
    $this->get('/performance/reviews')->assertRedirect('/login');
    $this->actingAs(userWithRole('employee'))->get('/performance/reviews')->assertForbidden();
});

it('shows a manager without an employee record an empty team list', function () {
    $this->actingAs(userWithRole('manager'))->get('/performance/reviews')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('view', 'team')->has('team.data', 0));
});

it('uses the states the review table knows', function () {
    expect(array_keys(ReviewStates::LABELS))->toBe(config('hrm.performance.review_states'));
});
