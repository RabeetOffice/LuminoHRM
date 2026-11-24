<?php

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Spatie\Activitylog\Models\Activity;

/** Fixes "now" at 10:00 on 24 Nov 2026 in the company zone (Asia/Karachi). */
function hoToday(): void
{
    test()->travelTo(CarbonImmutable::parse('2026-11-24 10:00:00', 'Asia/Karachi'));
}

// ---------------------------------------------------------------- access

it('lets admin and hr manage holidays', function (string $role) {
    hoToday();
    $user = userWithRole($role);

    $this->actingAs($user)->get('/leave/holidays')->assertOk()
        ->assertInertia(fn ($p) => $p->component('holidays/index'));

    $this->actingAs($user)->post('/leave/holidays', ['name' => 'Quaid Day', 'date' => '2026-12-25'])->assertSessionHasNoErrors()->assertSessionHas('success');
    $holiday = Holiday::where('name', 'Quaid Day')->firstOrFail();
    expect($holiday->date->format('Y-m-d'))->toBe('2026-12-25');

    $this->actingAs($user)->put("/leave/holidays/{$holiday->id}", ['name' => 'Christmas', 'date' => '2026-12-25'])->assertSessionHasNoErrors();
    expect($holiday->fresh()->name)->toBe('Christmas');

    $this->actingAs($user)->delete("/leave/holidays/{$holiday->id}")->assertSessionHas('success');
    expect($holiday->fresh())->toBeNull();
})->with(['admin', 'hr']);

it('forbids managers and employees on every holiday route', function (string $role) {
    $user = userWithRole($role);
    $holiday = Holiday::factory()->create(['name' => 'Keep', 'date' => '2026-08-14']);

    $this->actingAs($user)->get('/leave/holidays')->assertForbidden();
    $this->actingAs($user)->post('/leave/holidays', ['name' => 'X', 'date' => '2026-01-01'])->assertForbidden();
    $this->actingAs($user)->put("/leave/holidays/{$holiday->id}", ['name' => 'Y', 'date' => '2026-08-14'])->assertForbidden();
    $this->actingAs($user)->delete("/leave/holidays/{$holiday->id}")->assertForbidden();

    expect(Holiday::count())->toBe(1)->and($holiday->fresh()->name)->toBe('Keep');
})->with(['manager', 'employee']);

it('redirects guests to the sign-in page', function () {
    $this->get('/leave/holidays')->assertRedirect('/login');
    $this->post('/leave/holidays', ['name' => 'X', 'date' => '2026-01-01'])->assertRedirect('/login');
});

it('returns 404 for an unknown holiday', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/leave/holidays/999999', ['name' => 'X', 'date' => '2026-01-01'])->assertNotFound();
    $this->actingAs($admin)->delete('/leave/holidays/999999')->assertNotFound();
});

// ---------------------------------------------------------------- list and year filter

it('lists the current year\'s holidays sorted by date', function () {
    hoToday();
    Holiday::factory()->create(['name' => 'Christmas', 'date' => '2026-12-25']);
    Holiday::factory()->create(['name' => 'Kashmir Day', 'date' => '2026-02-05']);
    Holiday::factory()->create(['name' => 'Next year', 'date' => '2027-02-05']);
    Holiday::factory()->create(['name' => 'Last year', 'date' => '2025-03-23']);

    $this->actingAs(userWithRole('admin'))->get('/leave/holidays')
        ->assertInertia(fn ($p) => $p
            ->where('filters.year', 2026)
            ->has('holidays', 2)
            ->where('holidays.0.name', 'Kashmir Day')
            ->where('holidays.0.date', '2026-02-05')
            ->where('holidays.0.weekday', 'Thursday')
            ->where('holidays.1.name', 'Christmas')
            ->where('years', [2025, 2026, 2027]));
});

it('filters by the chosen year and falls back to this year for a bad one', function (mixed $year, int $expected) {
    hoToday();
    Holiday::factory()->create(['name' => 'Old', 'date' => '2024-08-14']);

    $this->actingAs(userWithRole('admin'))->get('/leave/holidays?year='.urlencode(is_array($year) ? '' : (string) $year))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('filters.year', $expected));
})->with([['2024', 2024], ['abc', 2026], ['1999', 2026], ['3000', 2026], ['', 2026]]);

it('offers the years that have holidays plus last, this and next year', function () {
    hoToday();
    Holiday::factory()->create(['name' => 'Old', 'date' => '2020-08-14']);

    $this->actingAs(userWithRole('admin'))->get('/leave/holidays?year=2020')
        ->assertInertia(fn ($p) => $p
            ->where('years', [2020, 2025, 2026, 2027])
            ->has('holidays', 1)
            ->where('holidays.0.name', 'Old'));
});

it('does not fail on an array-valued year', function () {
    hoToday();
    $this->actingAs(userWithRole('admin'))->get('/leave/holidays?year[]=2024')
        ->assertOk()->assertInertia(fn ($p) => $p->where('filters.year', 2026));
});

// ---------------------------------------------------------------- rules

it('rejects a second holiday on the same date, but lets a holiday keep its own date', function () {
    $admin = userWithRole('admin');
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-03-20']);

    $this->actingAs($admin)->post('/leave/holidays', ['name' => 'Other', 'date' => '2026-03-20'])
        ->assertSessionHasErrors(['date' => 'There is already a holiday on this date.']);
    $this->actingAs($admin)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-03-20'])->assertSessionHasNoErrors();

    $other = Holiday::factory()->create(['name' => 'Other', 'date' => '2026-03-21']);
    $this->actingAs($admin)->put("/leave/holidays/{$other->id}", ['name' => 'Other', 'date' => '2026-03-20'])->assertSessionHasErrors('date');
});

it('validates name and date', function (array $payload, string $field) {
    $this->actingAs(userWithRole('admin'))->post('/leave/holidays', $payload)->assertSessionHasErrors($field);
    expect(Holiday::count())->toBe(0);
})->with([
    'missing name' => [['name' => '  ', 'date' => '2026-01-01'], 'name'],
    'long name' => [['name' => str_repeat('a', 101), 'date' => '2026-01-01'], 'name'],
    'array name' => [['name' => ['x'], 'date' => '2026-01-01'], 'name'],
    'missing date' => [['name' => 'X', 'date' => ''], 'date'],
    'impossible date' => [['name' => 'X', 'date' => '2026-02-30'], 'date'],
    'wrong format' => [['name' => 'X', 'date' => '01/02/2026'], 'date'],
    'date with time' => [['name' => 'X', 'date' => '2026-01-01 00:00:00'], 'date'],
    'too early' => [['name' => 'X', 'date' => '1999-12-31'], 'date'],
    'array date' => [['name' => 'X', 'date' => ['2026-01-01']], 'date'],
]);

it('tidies the name and audits every change in the attendance area', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/leave/holidays', ['name' => '  Labour   Day ', 'date' => '2026-05-01'])->assertSessionHasNoErrors();
    $holiday = Holiday::where('name', 'Labour Day')->firstOrFail();

    $this->actingAs($admin)->put("/leave/holidays/{$holiday->id}", ['name' => 'Labour Day', 'date' => '2026-05-04']);
    $this->actingAs($admin)->delete("/leave/holidays/{$holiday->id}");

    expect(Activity::where('log_name', 'attendance')->orderBy('id')->pluck('description')->all())
        ->toBe(['Holiday created', 'Holiday updated', 'Holiday deleted']);
});

// ---------------------------------------------------------------- rebuild marking

it('marks the date\'s non-manual rows for rebuild when a holiday is added', function () {
    $a = Employee::factory()->create();
    $b = Employee::factory()->create();
    $closed = Attendance::factory()->create(['employee_id' => $a->id, 'date' => '2026-11-20', 'closed' => true, 'status' => 'absent']);
    $manual = Attendance::factory()->create(['employee_id' => $b->id, 'date' => '2026-11-20', 'is_manual' => true]);
    $otherDay = Attendance::factory()->create(['employee_id' => $a->id, 'date' => '2026-11-19']);

    $this->actingAs(userWithRole('hr'))->post('/leave/holidays', ['name' => 'Snow day', 'date' => '2026-11-20'])->assertSessionHasNoErrors();

    expect($closed->fresh()->needs_rebuild)->toBeTrue()
        ->and($manual->fresh()->needs_rebuild)->toBeFalse()
        ->and($otherDay->fresh()->needs_rebuild)->toBeFalse();
});

it('marks both the old and the new date when a holiday moves, and nothing when only the name changes', function () {
    $e = Employee::factory()->create();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-03-20']);
    $old = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-03-20', 'status' => 'holiday']);
    $new = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-03-21']);
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-03-20'])->assertSessionHasNoErrors();
    expect($old->fresh()->needs_rebuild)->toBeFalse();

    $this->actingAs($admin)->put("/leave/holidays/{$holiday->id}", ['name' => 'Eid ul Fitr', 'date' => '2026-03-21'])->assertSessionHasNoErrors();
    expect($old->fresh()->needs_rebuild)->toBeTrue()
        ->and($new->fresh()->needs_rebuild)->toBeTrue();
});

it('marks the date\'s rows when a holiday is removed', function () {
    $e = Employee::factory()->create();
    $holiday = Holiday::factory()->create(['name' => 'Eid', 'date' => '2026-03-20']);
    $row = Attendance::factory()->create(['employee_id' => $e->id, 'date' => '2026-03-20', 'status' => 'holiday', 'closed' => true]);

    $this->actingAs(userWithRole('admin'))->delete("/leave/holidays/{$holiday->id}")->assertSessionHas('success');

    expect($row->fresh()->needs_rebuild)->toBeTrue();
});
