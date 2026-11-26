<?php

use App\Models\Employee;
use App\Models\OpeningBalance;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;

/*
 * Payroll > Opening Figures: opening tax deducted and taxable income to date per employee per tax year (unique), the
 * tax years offered (current 2026-27 and previous 2025-26 on 2026-11-25), who may edit, audit. Money in paisa.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'Asia/Karachi'));
});

function ofUser(string $role = 'hr'): User
{
    $user = userWithRole($role);
    employeeFor($user, ['joining_date' => '2024-03-01']);

    return $user->fresh();
}

function ofPerson(): Employee
{
    return ofUser('employee')->employee;
}

test('managers and employees get 403 on the opening figures routes', function (string $role) {
    $user = ofUser($role);
    $person = ofPerson();

    $this->actingAs($user)->get('/payroll/opening')->assertForbidden();
    $this->actingAs($user)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => 1, 'tax_deducted' => 1])->assertForbidden();
    expect(OpeningBalance::query()->count())->toBe(0);
})->with(['manager', 'employee']);

it('lists people for the current tax year by default, with the two tax years offered', function () {
    $hr = ofUser('hr');
    $person = ofPerson();
    OpeningBalance::query()->create(['employee_id' => $person->id, 'tax_year' => 2026, 'tax_deducted' => 2100000, 'taxable_income' => 42000000]);

    $this->actingAs($hr)->get('/payroll/opening')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('payroll/opening/index')
        ->where('tax_year', 2026)
        ->where('tax_year_label', '2026-27')
        ->where('years', [['value' => 2026, 'label' => '2026-27'], ['value' => 2025, 'label' => '2025-26']])
        ->where('totals', ['people' => 1, 'taxable_income' => 42000000, 'tax_deducted' => 2100000])
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('employee.id', $person->id)['tax_deducted'] === 2100000
            && collect($rows)->firstWhere('employee.id', $hr->employee_id)['can_edit'] === false));

    $this->actingAs($hr)->get('/payroll/opening?tax_year=2025')->assertInertia(fn (Assert $page) => $page
        ->where('tax_year', 2025)->where('totals.people', 0));
    $this->actingAs($hr)->get('/payroll/opening?tax_year=2019')->assertInertia(fn (Assert $page) => $page->where('tax_year', 2026));
});

it('saves opening tax figures, sets rather than adds, and audits old and new', function () {
    $hr = ofUser('hr');
    $person = ofPerson();

    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => '420,000', 'tax_deducted' => 21000, 'note' => 'From the old sheet'])
        ->assertSessionHasNoErrors()->assertRedirect();

    $row = OpeningBalance::query()->sole();
    expect($row->tax_year)->toBe(2026)
        ->and($row->taxable_income)->toBe(42000000)
        ->and($row->tax_deducted)->toBe(2100000)
        ->and($row->note)->toBe('From the old sheet');

    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => 430000, 'tax_deducted' => 21500, 'note' => ''])
        ->assertSessionHasNoErrors();
    $row->refresh();
    expect(OpeningBalance::query()->count())->toBe(1)
        ->and($row->taxable_income)->toBe(43000000)
        ->and($row->tax_deducted)->toBe(2150000)
        ->and($row->note)->toBeNull();

    $audit = Activity::query()->where('description', 'Opening tax saved')->latest('id')->first();
    expect($audit->log_name)->toBe('payroll')
        ->and($audit->subject_id)->toBe($person->id)
        ->and($audit->properties['tax_year_label'])->toBe('2026-27')
        ->and($audit->properties['old']['tax_deducted'])->toBe(2100000)
        ->and($audit->properties['attributes']['tax_deducted'])->toBe(2150000)
        ->and($audit->properties['attributes']['tax_deducted_formatted'])->toBe('Rs 21,500');

    // The same figures again: nothing written, nothing audited.
    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => 430000, 'tax_deducted' => 21500])
        ->assertSessionHasNoErrors();
    expect(Activity::query()->where('description', 'Opening tax saved')->count())->toBe(2);
});

it('refuses bad amounts and a tax year that is not offered', function () {
    $hr = ofUser('hr');
    $person = ofPerson();

    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => -1, 'tax_deducted' => 'abc'])
        ->assertSessionHasErrors(['taxable_income', 'tax_deducted']);
    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2026", ['taxable_income' => '100.5', 'tax_deducted' => 100000000])
        ->assertSessionHasErrors(['taxable_income', 'tax_deducted']);
    $this->actingAs($hr)->put("/payroll/opening/{$person->id}/2024", ['taxable_income' => 1, 'tax_deducted' => 1])->assertNotFound();
    expect(OpeningBalance::query()->count())->toBe(0);
});

test('HR cannot set their own, an Admin\'s or another HR person\'s figures; Admin can', function () {
    $hr = ofUser('hr');
    $admin = ofUser('admin');
    $otherHr = ofUser('hr')->employee;
    $payload = ['taxable_income' => 1000, 'tax_deducted' => 50];

    $this->actingAs($hr)->put("/payroll/opening/{$hr->employee_id}/2026", $payload)->assertForbidden();
    $this->actingAs($hr)->put("/payroll/opening/{$admin->employee_id}/2026", $payload)->assertForbidden();
    $this->actingAs($hr)->put("/payroll/opening/{$otherHr->id}/2026", $payload)->assertForbidden();
    expect(OpeningBalance::query()->count())->toBe(0);

    $this->actingAs($admin)->put("/payroll/opening/{$otherHr->id}/2026", $payload)->assertSessionHasNoErrors();
    $this->actingAs($admin)->put("/payroll/opening/{$admin->employee_id}/2025", $payload)->assertSessionHasNoErrors();
    expect(OpeningBalance::query()->count())->toBe(2);
});
