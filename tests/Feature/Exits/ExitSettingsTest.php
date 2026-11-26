<?php

use App\Http\Requests\Settings\ExitRequest;
use App\Models\LeaveType;
use Spatie\Activitylog\Models\Activity;

/*
| Settings > Exit (clearance items and the experience letter; Admin and HR) and the encashment leave type picker in
| Settings > Payroll (Admin).
*/

/** @param array<string, mixed> $overrides */
function xsPayroll(array $overrides = []): array
{
    return array_merge([
        'day_pay_basis' => 'calendar_days',
        'default_tax_mode' => 'none',
        'leave_encashment' => true,
        'notice_period_days' => 30,
        'payslip_footer' => null,
        'tax_year_start_month' => 7,
    ], $overrides);
}

it('declares the exit group, its defaults and its permission', function () {
    expect(config('hrm.settings_groups.exit'))->toBe(['exit.clearance'])
        ->and(config('hrm.settings_groups'))->not->toHaveKey('letters')
        ->and(settings('exit.clearance_items'))->toBe([])
        ->and(settings('exit.experience_template'))->toContain('{employee_name}')->toContain('{company_name}')
        ->and(settings('payroll.encashment_leave_type_id'))->toBeNull();
});

it('lets Admin and HR open and save Settings > Exit, and nobody else', function (string $role, bool $allowed) {
    $user = userWithRole($role);
    $payload = ['clearance_items' => ['Return ID card'], 'experience_template' => 'Certified: {employee_name}.'];

    $response = $this->actingAs($user)->put('/hrm-settings/exit', $payload);

    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect(settings('exit.clearance_items'))->toBe(['Return ID card']);
        $this->actingAs($user)->get('/hrm-settings?group=exit')
            ->assertInertia(fn ($p) => $p->where('active', 'exit')->where('values.exit.clearance_items', ['Return ID card']));
    } else {
        $response->assertForbidden();
        expect(settings('exit.clearance_items'))->toBe([]);
    }
})->with([['admin', true], ['hr', true], ['manager', false], ['employee', false]]);

it('saves the clearance items trimmed and in order, and the letter', function () {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put('/hrm-settings/exit', [
        'clearance_items' => ['  Return ID card ', 'Hand over keys', 'Exit interview'],
        'experience_template' => 'Dear {name}, thank you from {company}.',
    ])->assertSessionHasNoErrors();

    expect(settings('exit.clearance_items'))->toBe(['Return ID card', 'Hand over keys', 'Exit interview'])
        ->and(settings('exit.experience_template'))->toBe('Dear {name}, thank you from {company}.');

    // A card that saves only the list leaves the letter alone.
    $this->actingAs($admin)->put('/hrm-settings/exit', ['clearance_items' => []])->assertSessionHasNoErrors();
    expect(settings('exit.clearance_items'))->toBe([])
        ->and(settings('exit.experience_template'))->toBe('Dear {name}, thank you from {company}.');
});

it('refuses bad clearance items with the exact messages', function (array $items, string $message) {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/exit', ['clearance_items' => $items])
        ->assertSessionHasErrors(['clearance_items' => $message]);

    expect(settings('exit.clearance_items'))->toBe([]);
})->with([
    'empty item' => [['Keys', '  '], 'Clearance items must be 1 to 80 characters.'],
    'too long' => [[str_repeat('a', 81)], 'Clearance items must be 1 to 80 characters.'],
    'not text' => [[['x']], 'Clearance items must be 1 to 80 characters.'],
    'duplicate ignoring case' => [['Keys', 'keys'], 'Each clearance item must be different.'],
    'duplicate after trimming' => [['Keys', ' Keys '], 'Each clearance item must be different.'],
    'twenty one' => [array_map(fn ($i) => "Item {$i}", range(1, 21)), 'At most 20 clearance items.'],
]);

it('accepts exactly twenty items of eighty characters', function () {
    $items = array_map(fn ($i) => str_pad((string) $i, 80, 'x'), range(1, 20));

    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/exit', ['clearance_items' => $items])->assertSessionHasNoErrors();

    expect(settings('exit.clearance_items'))->toHaveCount(20);
});

it('lists the placeholders including the aliases and rejects unknown ones', function () {
    $admin = userWithRole('admin');

    expect(ExitRequest::PLACEHOLDERS)->toBe([
        '{employee_name}', '{name}', '{designation}', '{department}', '{joining_date}', '{last_working_day}',
        '{company_name}', '{company}',
    ]);

    $this->actingAs($admin)->get('/hrm-settings?group=exit')
        ->assertInertia(fn ($p) => $p->where('options.placeholders', ExitRequest::PLACEHOLDERS));

    $this->actingAs($admin)->put('/hrm-settings/exit', ['experience_template' => 'Dear {employee_nam}, from {company}'])
        ->assertSessionHasErrors('experience_template');
    expect(session('errors')->first('experience_template'))->toContain('{employee_nam}')->toContain('{company}');

    $this->actingAs($admin)->put('/hrm-settings/exit', ['experience_template' => '{name} {company} {employee_name} {company_name} {designation} {department} {joining_date} {last_working_day}'])
        ->assertSessionHasNoErrors();
});

it('records an exit settings save in the audit log under the settings area', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/exit', ['clearance_items' => ['Return ID card']])->assertSessionHasNoErrors();

    $entry = Activity::where('log_name', 'settings')->where('description', 'Exit settings changed')->sole();
    expect($entry->properties['attributes']['exit.clearance_items'])->toBe(['Return ID card']);
});

// ---------------------------------------------------------------- Settings > Payroll: the encashment leave type

it('saves a paid leave type for encashment and clears it again', function () {
    $admin = userWithRole('admin');
    $annual = leaveType('Annual');

    $this->actingAs($admin)->put('/hrm-settings/payroll', xsPayroll(['encashment_leave_type_id' => $annual->id]))->assertSessionHasNoErrors();
    expect(settings('payroll.encashment_leave_type_id'))->toBe($annual->id)
        ->and(settings('payroll.leave_encashment'))->toBeTrue();

    $this->actingAs($admin)->put('/hrm-settings/payroll', xsPayroll(['encashment_leave_type_id' => null]))->assertSessionHasNoErrors();
    expect(settings('payroll.encashment_leave_type_id'))->toBeNull();
});

it('refuses an unpaid or unknown encashment leave type', function (string $which) {
    $admin = userWithRole('admin');
    $id = $which === 'unpaid' ? leaveType('Unpaid')->id : 999999;

    $this->actingAs($admin)->put('/hrm-settings/payroll', xsPayroll(['encashment_leave_type_id' => $id]))
        ->assertSessionHasErrors(['encashment_leave_type_id' => 'Choose a paid leave type.']);

    expect(settings('payroll.encashment_leave_type_id'))->toBeNull();
})->with(['unpaid', 'unknown']);

it('refuses a non-numeric encashment leave type', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/payroll', xsPayroll(['encashment_leave_type_id' => 'abc']))
        ->assertSessionHasErrors('encashment_leave_type_id');
});

it('keeps the encashment type when another payroll card saves, and lists only paid types on the Payroll page', function () {
    $admin = userWithRole('admin');
    $annual = leaveType('Annual');
    $this->actingAs($admin)->put('/hrm-settings/payroll', xsPayroll(['encashment_leave_type_id' => $annual->id]))->assertSessionHasNoErrors();

    $this->actingAs($admin)->put('/hrm-settings/payroll', ['notice_period_days' => 60])->assertSessionHasNoErrors();
    expect(settings('payroll.notice_period_days'))->toBe(60)
        ->and(settings('payroll.encashment_leave_type_id'))->toBe($annual->id);

    $paid = LeaveType::query()->where('is_paid', true)->orderBy('sort_order')->orderBy('name')->pluck('name')->all();
    $this->actingAs($admin)->get('/hrm-settings?group=payroll')
        ->assertInertia(fn ($p) => $p->where('values.payroll.encashment_leave_type_id', $annual->id)
            ->where('options.leaveTypes', fn ($types) => collect($types)->pluck('name')->all() === $paid && ! in_array('Unpaid', $paid, true)));
});

it('hides the leave type list from HR, who cannot open Settings > Payroll', function () {
    $this->actingAs(userWithRole('hr'))->get('/hrm-settings?group=exit')
        ->assertInertia(fn ($p) => $p->where('options.leaveTypes', [])->missing('values.payroll'));
});
