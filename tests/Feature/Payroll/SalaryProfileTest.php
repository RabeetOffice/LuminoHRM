<?php

use App\Actions\Payroll\SaveSalaryProfile;
use App\Models\Employee;
use App\Models\SalaryComponent;
use App\Models\SalaryProfile;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| Payroll > Salary Profiles: the list, one person's history and the new-salary form, and the writes (create, update,
| delete). Salary data is Admin / HR only; HR never edits their own salary or an Admin's / HR person's. "Now" is
| 2026-12-03 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-03 10:00:00', 'Asia/Karachi'));
});

/** A user holding $role with an employee record (joined 2024-03-01). */
function spUser(string $role): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01']);

    return $user->refresh();
}

/** An employee with no login (an Employee-role person for every rule here). */
function spPerson(array $attrs = []): Employee
{
    return Employee::factory()->create(['joining_date' => '2024-03-01', ...$attrs]);
}

/** The design's example profile as the form sends it (whole rupees). */
function spPayload(array $overrides = []): array
{
    return [
        'effective_from' => '2026-12-01',
        'basic' => 60000,
        'tax_mode' => 'percentage',
        'tax_percent' => '5',
        'tax_base' => 'gross_taxable',
        'note' => 'Annual review',
        'components' => [
            ['name' => 'House rent', 'type' => 'allowance', 'amount' => 24000, 'is_taxable' => true],
            ['name' => 'Medical', 'type' => 'allowance', 'amount' => 6000, 'is_taxable' => false],
            ['name' => 'Provident fund', 'type' => 'deduction', 'amount' => 3000],
        ],
        ...$overrides,
    ];
}

function spEntries(string $description): Collection
{
    return Activity::query()->where('log_name', 'payroll')->where('description', $description)->get();
}

// ---------------------------------------------------------------- access

it('lets Admin and HR read the list and a person\'s page', function (string $role) {
    $person = spPerson();
    salaryProfile($person);

    $this->actingAs(spUser($role))->get('/payroll/salary-profiles')->assertOk()
        ->assertInertia(fn ($p) => $p->component('payroll/salary-profiles/index'));
    $this->actingAs(spUser($role))->get("/payroll/salary-profiles/{$person->id}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('payroll/salary-profiles/show'));
})->with(['admin', 'hr']);

it('refuses a Manager or Employee every route, their own record included', function (string $role) {
    $user = spUser($role);
    $own = $user->employee;
    $ownProfile = salaryProfile($own);
    $other = spPerson();
    $otherProfile = salaryProfile($other);

    $this->actingAs($user)->get('/payroll/salary-profiles')->assertForbidden();

    foreach ([[$own, $ownProfile], [$other, $otherProfile]] as [$e, $p]) {
        $this->actingAs($user)->get("/payroll/salary-profiles/{$e->id}")->assertForbidden();
        $this->actingAs($user)->post("/payroll/salary-profiles/{$e->id}", spPayload())->assertForbidden();
        $this->actingAs($user)->put("/payroll/salary-profiles/{$e->id}/{$p->id}", spPayload())->assertForbidden();
        $this->actingAs($user)->delete("/payroll/salary-profiles/{$e->id}/{$p->id}")->assertForbidden();
    }

    expect(SalaryProfile::query()->count())->toBe(2)
        ->and(Activity::query()->where('log_name', 'payroll')->count())->toBe(0);
})->with(['manager', 'employee']);

it('sends guests to sign in', function () {
    $this->get('/payroll/salary-profiles')->assertRedirect('/login');
});

it('lets HR create a salary for an Employee-role person, stored in paisa with its components, and audits it', function () {
    $hr = spUser('hr');
    $person = spUser('employee')->employee;

    $this->actingAs($hr)->post("/payroll/salary-profiles/{$person->id}", spPayload())
        ->assertRedirect("/payroll/salary-profiles/{$person->id}")
        ->assertSessionHasNoErrors();

    $profile = SalaryProfile::query()->with('components')->sole();

    expect($profile->employee_id)->toBe($person->id)
        ->and($profile->effective_from->toDateString())->toBe('2026-12-01')
        ->and($profile->basic)->toBe(6_000_000)
        ->and($profile->tax_mode)->toBe('percentage')
        ->and($profile->tax_value)->toBe(500)
        ->and($profile->tax_base)->toBe('gross_taxable')
        ->and($profile->note)->toBe('Annual review')
        ->and($profile->created_by)->toBe($hr->id)
        ->and($profile->gross())->toBe(9_000_000)
        ->and($profile->taxableGross())->toBe(8_400_000)
        ->and($profile->components->map(fn (SalaryComponent $c) => [$c->name, $c->type, $c->amount, $c->is_taxable, $c->sort_order])->all())->toBe([
            ['House rent', 'allowance', 2_400_000, true, 0],
            ['Medical', 'allowance', 600_000, false, 1],
            ['Provident fund', 'deduction', 300_000, false, 2],
        ]);

    $entry = spEntries('Salary profile created')->sole();
    expect($entry->subject_id)->toBe($person->id)
        ->and($entry->causer_id)->toBe($hr->id)
        ->and($entry->properties['profile'])->toBe($profile->id)
        ->and($entry->properties['attributes'])->toMatchArray([
            'effective_from' => '2026-12-01',
            'basic' => 6_000_000,
            'basic_text' => 'Rs 60,000',
            'tax_mode' => 'percentage',
            'tax_value' => 500,
            'tax_text' => '5% of gross taxable',
        ])
        ->and($entry->properties['attributes']['components'][2])->toBe([
            'name' => 'Provident fund', 'type' => 'deduction', 'amount' => 300_000, 'is_taxable' => false, 'amount_text' => 'Rs 3,000',
        ]);
});

it('refuses HR their own salary and an Admin\'s or HR person\'s, with 403 and nothing written', function () {
    $hr = spUser('hr');

    foreach ([$hr->employee, spUser('admin')->employee, spUser('hr')->employee] as $target) {
        $this->actingAs($hr)->post("/payroll/salary-profiles/{$target->id}", spPayload())->assertForbidden();

        $profile = salaryProfile($target, from: '2026-12-01');
        $this->actingAs($hr)->put("/payroll/salary-profiles/{$target->id}/{$profile->id}", spPayload(['basic' => 70000]))->assertForbidden();
        $this->actingAs($hr)->delete("/payroll/salary-profiles/{$target->id}/{$profile->id}")->assertForbidden();
        expect($profile->refresh()->basic)->toBe(6_000_000);
    }

    expect(Activity::query()->where('log_name', 'payroll')->count())->toBe(0);
});

it('lets an Admin edit anyone, their own salary included', function () {
    $admin = spUser('admin');

    foreach ([$admin->employee, spUser('admin')->employee, spUser('hr')->employee, spPerson()] as $target) {
        $this->actingAs($admin)->post("/payroll/salary-profiles/{$target->id}", spPayload())->assertSessionHasNoErrors()->assertRedirect();
    }

    expect(SalaryProfile::query()->count())->toBe(4);
});

it('shows each row can_edit and the page can_edit by the edit rule', function () {
    $hr = spUser('hr');
    $person = spPerson();
    salaryProfile($person, from: '2026-12-01');
    $admin = spUser('admin');
    salaryProfile($admin->employee, from: '2026-12-01');

    $onPerson = $this->actingAs($hr)->get("/payroll/salary-profiles/{$person->id}")->inertiaProps();
    $onAdmin = $this->actingAs($hr)->get("/payroll/salary-profiles/{$admin->employee->id}")->inertiaProps();

    expect($onPerson['can_edit'])->toBeTrue()
        ->and($onPerson['history'][0]['can_edit'])->toBeTrue()
        ->and($onAdmin['can_edit'])->toBeFalse()
        ->and($onAdmin['history'][0]['can_edit'])->toBeFalse()
        // HR still sees the Admin's figures (needed to review a run).
        ->and($onAdmin['history'][0]['basic'])->toBe(6_000_000);
});

// ---------------------------------------------------------------- refusals

it('refuses a salary that starts before the joining date', function () {
    $person = spPerson(['first_name' => 'Sara', 'last_name' => 'Ahmed', 'joining_date' => '2026-11-16']);

    $this->actingAs(spUser('hr'))->post("/payroll/salary-profiles/{$person->id}", spPayload(['effective_from' => '2026-11-15']))
        ->assertSessionHasErrors(['effective_from' => 'A salary cannot start before Sara Ahmed joined (16 Nov 2026).']);

    expect(SalaryProfile::query()->count())->toBe(0);
});

it('refuses a second salary on the same date', function () {
    $person = spPerson(['first_name' => 'Sara', 'last_name' => 'Ahmed']);
    salaryProfile($person, from: '2026-12-01');

    $this->actingAs(spUser('hr'))->post("/payroll/salary-profiles/{$person->id}", spPayload())
        ->assertSessionHasErrors(['effective_from' => 'Sara Ahmed already has a salary starting on 1 Dec 2026. Edit that one instead.']);

    expect(SalaryProfile::query()->count())->toBe(1);
});

it('after an approved November run: refuses editing or deleting a profile it used, accepts 1 Dec, refuses 20 Nov', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $used = salaryProfile($person, from: '2026-01-01');
    payrollRun('2026-11', 'approved');

    $this->actingAs($hr)->put("/payroll/salary-profiles/{$person->id}/{$used->id}", spPayload(['effective_from' => '2026-01-01', 'basic' => 99000]))
        ->assertSessionHasErrors(['effective_from' => SaveSalaryProfile::USED_MESSAGE]);
    $this->actingAs($hr)->delete("/payroll/salary-profiles/{$person->id}/{$used->id}")
        ->assertSessionHasErrors(['effective_from' => SaveSalaryProfile::USED_MESSAGE]);

    $this->actingAs($hr)->post("/payroll/salary-profiles/{$person->id}", spPayload(['effective_from' => '2026-11-20']))
        ->assertSessionHasErrors(['effective_from' => 'Payroll up to November 2026 is approved. Start the new salary after it.']);

    $this->actingAs($hr)->post("/payroll/salary-profiles/{$person->id}", spPayload(['effective_from' => '2026-12-01']))
        ->assertSessionHasNoErrors();

    expect($used->refresh()->basic)->toBe(6_000_000)
        ->and(SalaryProfile::query()->count())->toBe(2);

    $props = $this->actingAs($hr)->get("/payroll/salary-profiles/{$person->id}")->inertiaProps();
    expect(collect($props['history'])->pluck('can_edit', 'effective_from')->all())->toBe(['2026-12-01' => true, '2026-01-01' => false])
        ->and($props['latest_locked'])->toBe('2026-11');
});

it('refuses moving an editable profile into an approved month, and a paid month locks too', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $profile = salaryProfile($person, from: '2026-12-01');
    payrollRun('2026-10', 'paid');
    payrollRun('2026-11', 'draft');

    $this->actingAs($hr)->put("/payroll/salary-profiles/{$person->id}/{$profile->id}", spPayload(['effective_from' => '2026-10-31']))
        ->assertSessionHasErrors(['effective_from' => 'Payroll up to October 2026 is approved. Start the new salary after it.']);

    // A draft November run locks nothing: a November start is fine.
    $this->actingAs($hr)->put("/payroll/salary-profiles/{$person->id}/{$profile->id}", spPayload(['effective_from' => '2026-11-01']))
        ->assertSessionHasNoErrors();

    expect($profile->refresh()->effective_from->toDateString())->toBe('2026-11-01');
});

it('allows future-dated salaries', function () {
    $person = spPerson();

    $this->actingAs(spUser('hr'))->post("/payroll/salary-profiles/{$person->id}", spPayload(['effective_from' => '2027-07-01']))
        ->assertSessionHasNoErrors();

    expect(SalaryProfile::query()->sole()->effective_from->toDateString())->toBe('2027-07-01');
});

it('returns 404 for a profile of another employee', function () {
    $admin = spUser('admin');
    $a = spPerson();
    $b = spPerson();
    $ofB = salaryProfile($b, from: '2026-12-01');

    $this->actingAs($admin)->put("/payroll/salary-profiles/{$a->id}/{$ofB->id}", spPayload())->assertNotFound();
    $this->actingAs($admin)->delete("/payroll/salary-profiles/{$a->id}/{$ofB->id}")->assertNotFound();
    $this->actingAs($admin)->get('/payroll/salary-profiles/abc')->assertNotFound();

    expect($ofB->refresh()->exists)->toBeTrue();
});

// ---------------------------------------------------------------- update and delete

it('replaces the components wholesale on update and audits old and new', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $profile = salaryProfile($person, from: '2026-12-01');
    $oldIds = $profile->components->modelKeys();

    $this->actingAs($hr)->put("/payroll/salary-profiles/{$person->id}/{$profile->id}", spPayload([
        'basic' => 65000,
        'tax_mode' => 'fixed',
        'tax_value_rupees' => 2000,
        'components' => [['name' => 'Fuel', 'type' => 'allowance', 'amount' => 5000, 'is_taxable' => false]],
    ]))->assertSessionHasNoErrors();

    $profile->refresh()->load('components');

    expect($profile->basic)->toBe(6_500_000)
        ->and($profile->tax_mode)->toBe('fixed')
        ->and($profile->tax_value)->toBe(200_000)
        ->and($profile->tax_base)->toBeNull()
        ->and($profile->components->map(fn ($c) => [$c->name, $c->amount])->all())->toBe([['Fuel', 500_000]])
        ->and(SalaryComponent::query()->whereIn('id', $oldIds)->exists())->toBeFalse();

    $entry = spEntries('Salary profile updated')->sole();
    expect($entry->properties['old'])->toMatchArray(['basic' => 6_000_000, 'basic_text' => 'Rs 60,000', 'tax_mode' => 'percentage', 'tax_text' => '5% of gross taxable'])
        ->and(array_column($entry->properties['old']['components'], 'name'))->toBe(['House rent', 'Medical', 'Provident fund'])
        ->and($entry->properties['attributes'])->toMatchArray(['basic' => 6_500_000, 'basic_text' => 'Rs 65,000', 'tax_mode' => 'fixed', 'tax_value' => 200_000, 'tax_text' => 'Rs 2,000 fixed'])
        ->and(array_column($entry->properties['attributes']['components'], 'name'))->toBe(['Fuel']);
});

it('writes nothing when an update changes nothing', function () {
    $person = spPerson();
    $profile = salaryProfile($person, from: '2026-12-01', taxMode: 'percentage', taxValue: 500);

    $this->actingAs(spUser('hr'))->put("/payroll/salary-profiles/{$person->id}/{$profile->id}", spPayload(['note' => null]))
        ->assertSessionHasNoErrors();

    expect(spEntries('Salary profile updated'))->toHaveCount(0);
});

it('deletes a profile with its components and audits the old values', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $profile = salaryProfile($person, from: '2026-12-01');

    $this->actingAs($hr)->delete("/payroll/salary-profiles/{$person->id}/{$profile->id}")->assertRedirect("/payroll/salary-profiles/{$person->id}");

    expect(SalaryProfile::query()->count())->toBe(0)
        ->and(SalaryComponent::query()->count())->toBe(0)
        ->and(spEntries('Salary profile deleted')->sole()->properties['old']['basic_text'])->toBe('Rs 60,000');
});

it('locks the employee row first in the save transaction', function () {
    $person = spPerson();
    $admin = spUser('admin');
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = $query->sql;
    });

    app(SaveSalaryProfile::class)->create($admin, $person, [
        'effective_from' => '2026-12-01', 'basic' => 100, 'tax_mode' => 'none', 'tax_value' => 0, 'tax_base' => null, 'note' => null, 'components' => [],
    ]);

    $first = collect($statements)->first(fn (string $sql) => ! str_contains($sql, 'savepoint') && ! str_contains($sql, 'roles') && ! str_contains($sql, 'permissions'));
    expect($first)->toContain('from "employees"')->toContain('where "employees"."id" = ?');
});

// ---------------------------------------------------------------- validation and money

it('takes whole rupees only and converts a percentage to basis points exactly', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $url = "/payroll/salary-profiles/{$person->id}";

    $this->actingAs($hr)->post($url, spPayload(['basic' => '1234.5']))->assertSessionHasErrors('basic');
    $this->actingAs($hr)->post($url, spPayload(['basic' => '64,930']))->assertSessionHasErrors('basic');
    $this->actingAs($hr)->post($url, spPayload(['basic' => 100_000_000]))->assertSessionHasErrors('basic');
    $this->actingAs($hr)->post($url, spPayload(['basic' => -1]))->assertSessionHasErrors('basic');
    $this->actingAs($hr)->post($url, spPayload(['tax_percent' => '5.255']))->assertSessionHasErrors('tax_percent');
    $this->actingAs($hr)->post($url, spPayload(['tax_percent' => '100.5']))->assertSessionHasErrors('tax_percent');
    $this->actingAs($hr)->post($url, spPayload(['tax_mode' => 'slab']))->assertSessionHasErrors('tax_mode');
    $this->actingAs($hr)->post($url, spPayload(['tax_mode' => 'fixed', 'tax_value_rupees' => '20.5']))->assertSessionHasErrors('tax_value_rupees');
    $this->actingAs($hr)->post($url, spPayload(['effective_from' => '01/12/2026']))->assertSessionHasErrors('effective_from');
    expect(SalaryProfile::query()->count())->toBe(0);

    $this->actingAs($hr)->post($url, spPayload(['basic' => '64930', 'tax_percent' => '5.25', 'tax_base' => 'basic']))->assertSessionHasNoErrors();

    $profile = SalaryProfile::query()->sole();
    expect($profile->basic)->toBe(6_493_000)
        ->and($profile->tax_value)->toBe(525)
        ->and($profile->tax_base)->toBe('basic');
});

it('defaults a percentage tax base to gross taxable and stores 0 for no tax', function () {
    $hr = spUser('hr');
    $a = spPerson();
    $b = spPerson();

    $this->actingAs($hr)->post("/payroll/salary-profiles/{$a->id}", spPayload(['tax_base' => null]))->assertSessionHasNoErrors();
    $this->actingAs($hr)->post("/payroll/salary-profiles/{$b->id}", spPayload(['tax_mode' => 'none', 'tax_percent' => '9']))->assertSessionHasNoErrors();

    expect(SalaryProfile::query()->where('employee_id', $a->id)->sole()->tax_base)->toBe('gross_taxable')
        ->and(SalaryProfile::query()->where('employee_id', $b->id)->sole()->only(['tax_mode', 'tax_value', 'tax_base']))
        ->toBe(['tax_mode' => 'none', 'tax_value' => 0, 'tax_base' => null]);
});

it('checks the components: names unique in any case, at most 20, whole rupees from 1, deductions never taxable', function () {
    $hr = spUser('hr');
    $person = spPerson();
    $url = "/payroll/salary-profiles/{$person->id}";
    $row = fn (string $name, string $type = 'allowance', mixed $amount = 1000, bool $taxable = true) => ['name' => $name, 'type' => $type, 'amount' => $amount, 'is_taxable' => $taxable];

    $this->actingAs($hr)->post($url, spPayload(['components' => [$row('Fuel'), $row('FUEL ')]]))->assertSessionHasErrors('components');
    $this->actingAs($hr)->post($url, spPayload(['components' => array_map(fn ($i) => $row("Item {$i}"), range(1, 21))]))->assertSessionHasErrors('components');
    $this->actingAs($hr)->post($url, spPayload(['components' => [$row('Fuel', amount: 0)]]))->assertSessionHasErrors('components.0.amount');
    $this->actingAs($hr)->post($url, spPayload(['components' => [$row('Fuel', amount: '1.5')]]))->assertSessionHasErrors('components.0.amount');
    $this->actingAs($hr)->post($url, spPayload(['components' => [$row('')]]))->assertSessionHasErrors('components.0.name');
    $this->actingAs($hr)->post($url, spPayload(['components' => [$row(str_repeat('x', 61))]]))->assertSessionHasErrors('components.0.name');
    $this->actingAs($hr)->post($url, spPayload(['components' => [$row('Fuel', 'bonus')]]))->assertSessionHasErrors('components.0.type');
    $this->actingAs($hr)->post($url, spPayload(['note' => str_repeat('x', 256)]))->assertSessionHasErrors('note');
    expect(SalaryProfile::query()->count())->toBe(0);

    $this->actingAs($hr)->post($url, spPayload(['components' => [...array_map(fn ($i) => $row("Item {$i}"), range(1, 19)), $row('Loan cover', 'deduction', 500, true)]]))
        ->assertSessionHasNoErrors();

    $deduction = SalaryComponent::query()->where('type', 'deduction')->sole();
    expect(SalaryComponent::query()->count())->toBe(20)
        ->and($deduction->is_taxable)->toBeFalse()
        ->and($deduction->amount)->toBe(50_000);
});

// ---------------------------------------------------------------- list

it('lists current salaries with gross and a tax label, and the No salary filter', function () {
    $hr = spUser('hr');
    $paid = spPerson(['first_name' => 'Amna']);
    salaryProfile($paid, from: '2026-01-01');
    salaryProfile($paid, basicRupees: 70000, from: '2026-11-01', taxMode: 'fixed', taxValue: 200_000);
    salaryProfile($paid, basicRupees: 80000, from: '2027-01-01');
    $future = spPerson(['first_name' => 'Bilal', 'joining_date' => '2027-01-10']);
    salaryProfile($future, from: '2027-01-10', allowances: [], deductions: [], taxMode: 'none', taxValue: 0);
    $none = spPerson(['first_name' => 'Chand']);
    $leaver = spPerson(['first_name' => 'Dawood', 'status' => 'resigned', 'last_working_day' => '2026-11-30']);
    $noLastDay = spPerson(['first_name' => 'Ehsan', 'status' => 'terminated', 'last_working_day' => null]);

    $props = $this->actingAs($hr)->get('/payroll/salary-profiles?search=')->inertiaProps();
    $rows = collect($props['rows']['data'])->keyBy('id');

    expect($rows->keys()->all())->not->toContain($leaver->id)->not->toContain($noLastDay->id)
        ->and($rows[$paid->id]['salary'])->toBe([
            'basic' => 7_000_000, 'gross' => 10_000_000, 'tax' => 'Rs 2,000 fixed', 'effective_from' => '2026-11-01', 'upcoming' => false,
        ])
        ->and($rows[$future->id]['salary'])->toBe([
            'basic' => 6_000_000, 'gross' => 6_000_000, 'tax' => 'None', 'effective_from' => '2027-01-10', 'upcoming' => true,
        ])
        ->and($rows[$none->id]['salary'])->toBeNull()
        ->and($rows[$hr->employee->id]['salary'])->toBeNull();

    $missing = $this->actingAs($hr)->get('/payroll/salary-profiles?missing=1')->inertiaProps();
    expect(collect($missing['rows']['data'])->pluck('id')->sort()->values()->all())->toBe(collect([$none->id, $hr->employee->id])->sort()->values()->all())
        ->and($missing['filters']['missing'])->toBeTrue();

    $search = $this->actingAs($hr)->get('/payroll/salary-profiles?search=amna')->inertiaProps();
    expect(collect($search['rows']['data'])->pluck('id')->all())->toBe([$paid->id]);
});

it('reads a fixed number of queries whatever the number of people on the page', function () {
    $hr = spUser('hr');
    $count = function () use ($hr): int {
        $this->actingAs($hr)->get('/payroll/salary-profiles')->assertOk(); // warms the permission and settings caches
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($hr)->get('/payroll/salary-profiles')->assertOk();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    };

    salaryProfile(spPerson());
    $few = $count();

    foreach (range(1, 8) as $i) {
        $e = spPerson();
        salaryProfile($e, from: '2026-01-01');
        salaryProfile($e, from: '2026-06-01');
    }

    expect($count())->toBe($few);
});

it('starts the form from the latest profile, or the default tax mode when there is none', function () {
    Settings::set('payroll', ['default_tax_mode' => 'percentage']);
    $hr = spUser('hr');
    $fresh = spPerson();
    $paid = spPerson();
    salaryProfile($paid, from: '2026-01-01');
    salaryProfile($paid, basicRupees: 75000, from: '2027-01-01', taxMode: 'fixed', taxValue: 150_000);

    $onFresh = $this->actingAs($hr)->get("/payroll/salary-profiles/{$fresh->id}")->inertiaProps();
    $onPaid = $this->actingAs($hr)->get("/payroll/salary-profiles/{$paid->id}")->inertiaProps();

    expect($onFresh['defaults'])->toBe(['basic' => null, 'tax_mode' => 'percentage', 'tax_value' => 0, 'tax_base' => 'gross_taxable', 'components' => []])
        ->and($onFresh['history'])->toBe([])
        ->and($onPaid['defaults']['basic'])->toBe(7_500_000)
        ->and($onPaid['defaults']['tax_mode'])->toBe('fixed')
        ->and($onPaid['defaults']['tax_value'])->toBe(150_000)
        ->and(collect($onPaid['history'])->pluck('effective_from')->all())->toBe(['2027-01-01', '2026-01-01'])
        ->and(collect($onPaid['history'])->pluck('current')->all())->toBe([false, true])
        ->and($onPaid['history'][1])->toMatchArray(['gross' => 9_000_000, 'taxable_gross' => 8_400_000, 'deductions' => 300_000, 'tax' => '5% of gross taxable']);
});
