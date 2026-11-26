<?php

use App\Models\Department;
use App\Models\Review;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Support/cycle-helpers.php';

beforeEach(function () {
    $this->withoutVite(); // the page is built once by the coordinator, not by this task
    $this->travelTo(CarbonImmutable::parse('2026-11-26 14:00:00', 'Asia/Karachi'));
});

/** A person who can review: a manager account with an employee record. */
function cpReviewer(array $attrs = []): array
{
    $user = userWithRole('manager');
    $emp = employeeFor($user, array_merge(['first_name' => 'Omar', 'last_name' => 'Khan'], $attrs));

    return [$user, $emp];
}

// ---------------------------------------------------------------- access

it('opens the cycle page for Admin and HR and keeps everyone else out', function () {
    cyPerson();
    $cycle = cyOpen();

    foreach (['admin', 'hr'] as $role) {
        $this->actingAs(userWithRole($role))->get("/performance/cycles/{$cycle->id}")->assertOk()
            ->assertInertia(fn ($p) => $p->component('performance/cycle')->where('cycle.id', $cycle->id));
    }

    foreach (['manager', 'employee'] as $role) {
        $this->actingAs(userWithRole($role))->get("/performance/cycles/{$cycle->id}")->assertForbidden();
    }

    auth()->logout();
    $this->get("/performance/cycles/{$cycle->id}")->assertRedirect('/login');
});

it('answers 404 for a cycle that does not exist and for a non-numeric id', function () {
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get('/performance/cycles/9999')->assertNotFound();
    $this->actingAs($hr)->get('/performance/cycles/abc')->assertNotFound();
});

// ---------------------------------------------------------------- header, counts, progress

it('sends the cycle header with period, due date, self-review flag and the unfinished hint', function () {
    cyPerson();
    cyPerson();
    $cycle = cyOpen(null, '2026-Q3', '2026-10-14');

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p
            ->where('cycle.name', 'Q3 2026 (Jul–Sep)')
            ->where('cycle.period_label', 'Q3 2026 (Jul–Sep)')
            ->where('cycle.period_start', '2026-07-01')
            ->where('cycle.period_end', '2026-09-30')
            ->where('cycle.due_on', '2026-10-14')
            ->where('cycle.status', 'open')
            ->where('cycle.overdue', true)
            ->where('cycle.self_review_enabled', true)
            ->where('cycle.total', 2)
            ->where('cycle.unfinished', 2)
            ->where('cycle.close_hint', '2 reviews are not finished yet. Close or skip them first.')
            ->where('can.close', false)
            ->where('can.add_people', true));
});

it('uses the singular in the hint and lets the cycle close once every review is closed or skipped', function () {
    $a = cyPerson();
    $b = cyPerson();
    $cycle = cyOpen();
    DB::table('reviews')->where('employee_id', $a->id)->update(['status' => 'skipped']);
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->where('cycle.close_hint', '1 review is not finished yet. Close or skip it first.')->where('can.close', false));

    DB::table('reviews')->where('employee_id', $b->id)->update(['status' => 'closed']);

    $this->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->where('cycle.close_hint', null)->where('cycle.unfinished', 0)->where('can.close', true));
});

it('counts add up to the total, whatever the filters say', function () {
    $people = [cyPerson(), cyPerson(), cyPerson(), cyPerson(), cyPerson()];
    $cycle = cyOpen();
    foreach (['manager_review', 'ready_to_close', 'closed', 'skipped'] as $i => $state) {
        DB::table('reviews')->where('employee_id', $people[$i]->id)->update(['status' => $state]);
    }

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}?state=closed&search=zzz")
        ->assertInertia(fn ($p) => $p
            ->where('counts', ['self_review' => 1, 'manager_review' => 1, 'ready_to_close' => 1, 'closed' => 1, 'skipped' => 1])
            ->where('cycle.total', 5)
            ->where('reviews.total', 0));
});

// ---------------------------------------------------------------- rows

it('lists the reviews with the people, reviewer, state and scores, and the final score only when closed', function () {
    [, $boss] = cpReviewer();
    $a = cyPerson(['manager_id' => $boss->id, 'first_name' => 'Anna', 'last_name' => 'Zed']);
    $b = cyPerson(['manager_id' => $boss->id, 'first_name' => 'Bilal', 'last_name' => 'Yar']);
    $c = cyPerson(['first_name' => 'Cara', 'last_name' => 'Xu', 'manager_id' => null]);
    $cycle = cyOpen();
    Review::query()->where('employee_id', $a->id)->update(['status' => 'manager_review', 'self_score' => 380, 'final_score' => 999]);
    Review::query()->where('employee_id', $b->id)->update(['status' => 'closed', 'self_score' => 400, 'final_score' => 412]);
    Review::query()->where('employee_id', $c->id)->update(['status' => 'skipped', 'skip_reason' => 'On long leave']);

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->has('reviews.data', 3)
            ->where('reviews.data.0.employee.name', 'Anna Zed')
            ->where('reviews.data.0.employee.code', $a->employee_code)
            ->where('reviews.data.0.department', $a->department->name)
            ->where('reviews.data.0.reviewer', 'Omar Khan')
            ->where('reviews.data.0.state', 'manager_review')
            ->where('reviews.data.0.self_score', 380)
            ->missing('reviews.data.0.final_score')
            ->missing('reviews.data.0.label')
            ->missing('reviews.data.0.skip_reason')
            ->where('reviews.data.0.url', '/performance/reviews/'.Review::query()->where('employee_id', $a->id)->value('id'))
            ->where('reviews.data.1.state', 'closed')
            ->where('reviews.data.1.final_score', 412)
            ->where('reviews.data.1.label', 'Exceeds')
            ->where('reviews.data.2.reviewer', 'HR')
            ->where('reviews.data.2.skip_reason', 'On long leave')
            ->missing('reviews.data.2.final_score'));
});

it('flags a reviewer with no login or a disabled one, and not one with access', function () {
    [, $good] = cpReviewer(['first_name' => 'Good', 'last_name' => 'Boss']);
    $noLogin = cyPerson(['first_name' => 'No', 'last_name' => 'Login']);
    $off = userWithRole('manager');
    $disabledEmp = employeeFor($off, ['first_name' => 'Disabled', 'last_name' => 'Boss']);
    $off->forceFill(['is_active' => false])->save();
    $employeeRole = userWithRole('employee');
    $noAccessEmp = employeeFor($employeeRole, ['first_name' => 'Plain', 'last_name' => 'Staff']);

    $cycle = reviewCycleRow();
    $rows = [];
    foreach ([$good, $noLogin, $disabledEmp, $noAccessEmp] as $i => $reviewer) {
        $rows[$reviewer->id] = reviewRow($cycle, cyPerson(['first_name' => 'P'.$i]), ['status' => 'manager_review', 'reviewer_id' => $reviewer->id]);
    }
    $hrRow = reviewRow($cycle, cyPerson(['first_name' => 'P9']), ['status' => 'manager_review', 'reviewer_id' => null]);
    $closedRow = reviewRow($cycle, cyPerson(['first_name' => 'P8']), ['status' => 'closed', 'reviewer_id' => $noLogin->id, 'final_score' => 300]);

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}?per_page=50")
        ->assertInertia(fn ($p) => $p->where('reviews.data', function ($data) use ($rows, $hrRow, $closedRow, $good, $noLogin, $disabledEmp, $noAccessEmp) {
            $problems = collect($data)->pluck('reviewer_problem', 'id');

            return $problems[$rows[$good->id]->id] === false
                && $problems[$rows[$noLogin->id]->id] === true
                && $problems[$rows[$disabledEmp->id]->id] === true
                && $problems[$rows[$noAccessEmp->id]->id] === true
                && $problems[$hrRow->id] === false
                && $problems[$closedRow->id] === false;
        }));
});

it('filters by state, search, department, reviewer and overdue', function () {
    [, $boss] = cpReviewer();
    $other = employeeFor(userWithRole('manager'), ['first_name' => 'Nadia', 'last_name' => 'Lead']);
    $dept = Department::factory()->create(['name' => 'Sales Floor']);
    $anna = cyPerson(['first_name' => 'Anna', 'last_name' => 'Zed', 'manager_id' => $boss->id, 'department_id' => $dept->id]);
    $bilal = cyPerson(['first_name' => 'Bilal', 'last_name' => 'Yar', 'manager_id' => $other->id]);
    $cara = cyPerson(['first_name' => 'Cara', 'last_name' => 'Xu', 'manager_id' => null]);
    $cycle = cyOpen(null, '2026-Q3', '2026-10-14');
    Review::query()->where('employee_id', $bilal->id)->update(['status' => 'closed']);

    $this->actingAs(userWithRole('hr'));
    $names = fn ($url) => collect($this->get($url)->assertOk()->viewData('page')['props']['reviews']['data'])->pluck('employee.name')->all();
    $base = "/performance/cycles/{$cycle->id}";

    expect($names("{$base}?state=closed"))->toBe(['Bilal Yar'])
        ->and($names("{$base}?search=ann"))->toBe(['Anna Zed'])
        ->and($names("{$base}?search={$cara->employee_code}"))->toBe(['Cara Xu'])
        ->and($names("{$base}?department={$dept->id}"))->toBe(['Anna Zed'])
        ->and($names("{$base}?reviewer={$boss->id}"))->toBe(['Anna Zed'])
        ->and($names("{$base}?reviewer=hr"))->toBe(['Cara Xu'])
        ->and($names("{$base}?overdue=1"))->toBe(['Anna Zed', 'Cara Xu'])
        ->and($names("{$base}?state=zzz&department=abc&reviewer=%25&search[]=1"))->toHaveCount(3);

    $this->get("{$base}?state=closed&overdue=1")->assertInertia(fn ($p) => $p->has('reviews.data', 0));
});

it('shows nothing as overdue before the due date or in a closed cycle', function () {
    cyPerson();
    $open = cyOpen(null, '2026-Q3', '2026-12-31');
    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$open->id}?overdue=1")
        ->assertInertia(fn ($p) => $p->where('cycle.overdue', false)->has('reviews.data', 0)->where('filters.overdue', true));

    $closed = reviewCycleRow(['status' => 'closed', 'due_on' => '2026-01-01']);
    $this->get("/performance/cycles/{$closed->id}")->assertInertia(fn ($p) => $p->where('cycle.overdue', false));
});

it('lists the departments and reviewers that are in the cycle for the filters', function () {
    [, $boss] = cpReviewer();
    cyPerson(['manager_id' => $boss->id]);
    $cycle = cyOpen();

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->has('options.departments', 1)
            ->where('options.reviewers.0.id', $boss->id)
            ->where('options.reviewers.0.name', 'Omar Khan')
            ->where('options.reviewer_choices.0.id', $boss->id));
});

it('pages the reviews 25 at a time and clamps a page past the end', function () {
    for ($i = 0; $i < 26; $i++) {
        cyPerson();
    }
    $cycle = cyOpen();
    $this->actingAs(userWithRole('hr'));

    $this->get("/performance/cycles/{$cycle->id}")->assertInertia(fn ($p) => $p->has('reviews.data', 25)->where('reviews.last_page', 2));
    $this->get("/performance/cycles/{$cycle->id}?page=2")->assertInertia(fn ($p) => $p->has('reviews.data', 1));
    $this->get("/performance/cycles/{$cycle->id}?page=7")->assertRedirect("/performance/cycles/{$cycle->id}?page=2");
});

it('runs the same number of queries for 5 and for 25 reviews', function () {
    [, $boss] = cpReviewer();
    $cycle = reviewCycleRow();
    for ($i = 0; $i < 5; $i++) {
        reviewRow($cycle, cyPerson(['manager_id' => $boss->id]), ['reviewer_id' => $boss->id]);
    }
    $hr = userWithRole('hr');

    $count = function () use ($hr, $cycle) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get("/performance/cycles/{$cycle->id}")->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    $count(); // the first request loads the account's permissions
    $five = $count();

    for ($i = 0; $i < 20; $i++) {
        reviewRow($cycle, cyPerson(['manager_id' => $boss->id]), ['reviewer_id' => $boss->id]);
    }

    expect($count())->toBe($five);
});

// ---------------------------------------------------------------- not added

it('lists the people not in the cycle: eligible ones can be added, those without KPIs say why', function () {
    cyPerson(['first_name' => 'Already', 'last_name' => 'In']);
    $cycle = cyOpen();
    $ready = cyPerson(['first_name' => 'Ready', 'last_name' => 'Now']);
    $bare = cyPerson(['first_name' => 'Bare', 'last_name' => 'Cupboard', 'kpi' => false]);
    cyPerson(['first_name' => 'Left', 'last_name' => 'Firm', 'status' => 'terminated']);
    cyPerson(['first_name' => 'Too', 'last_name' => 'Late', 'joining_date' => '2026-10-01']);

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->where('not_added.total', 2)
            ->where('not_added.people.0.id', $ready->id)
            ->where('not_added.people.0.reason', 'Can be added')
            ->where('not_added.people.0.addable', true)
            ->where('not_added.people.1.id', $bare->id)
            ->where('not_added.people.1.reason', 'No KPIs')
            ->where('not_added.people.1.addable', false)
            ->where('not_added.people.1.code', $bare->employee_code));
});

it('keeps a person off the list once they are added', function () {
    cyPerson();
    $cycle = cyOpen();
    $late = cyPerson();
    $hr = userWithRole('hr');
    $this->actingAs($hr)->post("/performance/cycles/{$cycle->id}/people", ['employee_ids' => [$late->id]])->assertRedirect();

    $this->get("/performance/cycles/{$cycle->id}")->assertInertia(fn ($p) => $p->where('not_added.total', 0)->has('reviews.data', 2));
});

// ---------------------------------------------------------------- can

it('turns every action off when the cycle is closed, and lists nobody to add', function () {
    $a = cyPerson();
    $cycle = cyOpen();
    DB::table('reviews')->update(['status' => 'closed', 'final_score' => 300]);
    $cycle->forceFill(['status' => 'closed'])->save();
    cyPerson(); // would be addable if the cycle were open

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p
            ->where('can', ['add_people' => false, 'close' => false, 'waive' => false, 'reviewer' => false, 'skip' => false, 'restore' => false, 'reopen' => false])
            ->where('reviews.data.0.can', ['waive' => false, 'reviewer' => false, 'skip' => false, 'restore' => false, 'reopen' => false])
            ->where('not_added', ['total' => 0, 'people' => []])
            ->where('options.reviewer_choices', [])
            ->where('cycle.close_hint', null));
});

it('gives each row only the actions its state allows', function () {
    $people = collect(range(1, 5))->map(fn () => cyPerson());
    $cycle = cyOpen();
    foreach (['self_review', 'manager_review', 'ready_to_close', 'closed', 'skipped'] as $i => $state) {
        Review::query()->where('employee_id', $people[$i]->id)->update(['status' => $state, 'skipped_from' => $state === 'skipped' ? 'manager_review' : null]);
    }

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->where('reviews.data', function ($data) {
            $by = collect($data)->keyBy('state');
            $flags = fn (string $state) => array_keys(array_filter($by[$state]['can']));

            return $flags('self_review') === ['waive', 'reviewer', 'skip']
                && $flags('manager_review') === ['reviewer', 'skip']
                && $flags('ready_to_close') === ['reviewer', 'skip']
                && $flags('closed') === ['reopen']
                && $flags('skipped') === ['reviewer', 'restore'];
        }));
});

it('gives HR no actions on their own review', function () {
    $hr = userWithRole('hr');
    $mine = employeeFor($hr, ['joining_date' => '2024-01-15', 'status' => 'active']);
    kpiRow(['designation' => $mine->designation, 'weight' => 10]);
    userWithRole('admin'); // a second holder of performance.manage, so HR is not the sole manager
    $cycle = cyOpen($hr);

    $this->actingAs($hr)->get("/performance/cycles/{$cycle->id}")
        ->assertInertia(fn ($p) => $p->where('reviews.data.0.employee.id', $mine->id)
            ->where('reviews.data.0.can', ['waive' => false, 'reviewer' => false, 'skip' => false, 'restore' => false, 'reopen' => false]));
});

it('works for a cycle with no reviews', function () {
    $cycle = reviewCycleRow();

    $this->actingAs(userWithRole('hr'))->get("/performance/cycles/{$cycle->id}")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->has('reviews.data', 0)->where('cycle.total', 0)->where('can.close', true)->where('cycle.close_hint', null));
});
