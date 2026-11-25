<?php

use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/*
| The attendance-rule settings added by the Task 3 fix round: attendance.start_date (null), attendance.duplicate_punch_minutes
| (5) and attendance.day_cutoff_time ("04:00"). Backend defaults and validation only; the Settings screen inputs come later.
*/

it('has the attendance rule defaults', function () {
    expect(settings('attendance.start_date'))->toBeNull()
        ->and(settings('attendance.duplicate_punch_minutes'))->toBe(5)
        ->and(settings('attendance.day_cutoff_time'))->toBe('04:00');
});

it('saves the attendance rules', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', validAttendance([
        'start_date' => '2026-12-01', 'duplicate_punch_minutes' => 0, 'day_cutoff_time' => '03:30',
    ]))->assertSessionHasNoErrors();

    expect(settings('attendance.start_date'))->toBe('2026-12-01')
        ->and(settings('attendance.duplicate_punch_minutes'))->toBe(0)
        ->and(settings('attendance.day_cutoff_time'))->toBe('03:30');

    // Cleared again: attendance not started.
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', ['start_date' => ''])->assertSessionHasNoErrors();
    expect(settings('attendance.start_date'))->toBeNull()
        ->and(settings('attendance.day_cutoff_time'))->toBe('03:30');
});

it('keeps the stored values of the new rules when a card saves only its own fields', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['duplicate_punch_minutes' => 10])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['grace_minutes' => 20])->assertSessionHasNoErrors();

    expect(settings('attendance.duplicate_punch_minutes'))->toBe(10)
        ->and(settings('attendance.start_date'))->toBeNull()
        ->and(settings('attendance.day_cutoff_time'))->toBe('04:00');
});

it('validates the attendance rules', function (string $field, mixed $bad, mixed $good) {
    $admin = userWithRole('admin');

    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance([$field => $bad]))->assertSessionHasErrors($field);
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance([$field => $good]))->assertSessionHasNoErrors();
})->with([
    'start date not a date' => ['start_date', '01/12/2026', '2026-12-01'],
    'start date impossible' => ['start_date', '2026-02-30', '2026-02-28'],
    'start date as a list' => ['start_date', ['2026-12-01'], null],
    'double tap below 0' => ['duplicate_punch_minutes', -1, 0],
    'double tap above 60' => ['duplicate_punch_minutes', 61, 60],
    'double tap not a number' => ['duplicate_punch_minutes', 'five', 5],
    'cutoff not a time' => ['day_cutoff_time', '4am', '04:00'],
    'cutoff with seconds' => ['day_cutoff_time', '04:00:00', '00:00'],
    'cutoff after 06:00' => ['day_cutoff_time', '06:01', '06:00'],
]);

it('requires the day cutoff to stay at least 2 hours before the earliest shift start', function () {
    $admin = userWithRole('admin');
    shiftFor(null, ['name' => 'Early', 'start_time' => '07:30', 'end_time' => '16:00']);

    $this->actingAs($admin)->put('/hrm-settings/attendance', ['day_cutoff_time' => '05:31'])
        ->assertSessionHasErrors(['day_cutoff_time' => 'The day cutoff must be 05:30 or earlier: at least 2 hours before the earliest shift start (Early, 07:30).']);
    expect(settings('attendance.day_cutoff_time'))->toBe('04:00');

    $this->actingAs($admin)->put('/hrm-settings/attendance', ['day_cutoff_time' => '05:30'])->assertSessionHasNoErrors();
    expect(settings('attendance.day_cutoff_time'))->toBe('05:30');
});

it('checks the cutoff against the settings shift start while no shift exists yet', function () {
    $admin = userWithRole('admin');
    DB::table('shifts')->delete();
    Settings::set('attendance', ['shift_start' => '07:00', 'shift_end' => '16:00']);

    $this->actingAs($admin)->put('/hrm-settings/attendance', ['day_cutoff_time' => '05:01'])
        ->assertSessionHasErrors(['day_cutoff_time' => 'The day cutoff must be 05:00 or earlier: at least 2 hours before the default shift start (07:00).']);
    expect(settings('attendance.day_cutoff_time'))->toBe('04:00');

    $this->actingAs($admin)->put('/hrm-settings/attendance', ['day_cutoff_time' => '05:00'])->assertSessionHasNoErrors();

    // The shift start moved too close to the stored cutoff: refused on the start.
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['shift_start' => '06:30'])
        ->assertSessionHasErrors(['shift_start' => 'The default shift start must be 07:00 or later: at least 2 hours after the day cutoff (05:00).']);
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['shift_start' => '07:00'])->assertSessionHasNoErrors();

    // Once a shift exists, the shifts decide and the legacy start is not checked.
    shiftFor(null, ['name' => 'Late', 'start_time' => '10:00', 'end_time' => '18:00']);
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['shift_start' => '06:00'])->assertSessionHasNoErrors();
});

it('does not re-check an unchanged cutoff when another card of the group is saved', function () {
    $admin = userWithRole('admin');
    Settings::set('attendance', ['day_cutoff_time' => '06:00']);
    // Written behind the validation's back (older data): a shift too close to the stored cutoff.
    DB::table('shifts')->insert([
        'name' => 'Legacy', 'start_time' => '07:00:00', 'end_time' => '15:00:00', 'grace_minutes' => 0,
        'half_day_hours' => '4.00', 'weekly_offs' => '[]', 'is_default' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($admin)->put('/hrm-settings/attendance', ['grace_minutes' => 20])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['day_cutoff_time' => '05:30'])->assertSessionHasErrors('day_cutoff_time');
});
