<?php

use App\Actions\Exits\IssueExperienceLetter;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Exits\ExperienceLetter;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| The experience letter: placeholders and aliases, company date format, who may issue and when, re-issue audit, and the
| employee's download only after issue. "Now" is Wednesday 2026-12-16 14:00 in Karachi.
*/

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-12-16 14:00:00', 'Asia/Karachi'));
    Settings::set('company', ['name' => 'Lumino Ltd', 'date_format' => 'd M Y']);
});

/** @return array{0: Employee, 1: Resignation} an approved exit of Sara Ahmed */
function elExit(string $status = 'approved'): array
{
    $user = userWithRole('employee');
    $employee = employeeFor($user, [
        'first_name' => 'Sara', 'last_name' => 'Ahmed', 'joining_date' => '2024-03-01',
        'department_id' => Department::factory()->create(['name' => 'Operations'])->id,
        'designation_id' => Designation::factory()->create(['name' => 'Analyst'])->id,
    ]);
    $exit = approvedExit($employee, '2026-12-10');

    if ($status !== 'approved') {
        $exit->forceFill(['status' => $status])->save();
    }

    return [$employee, $exit];
}

it('lists the placeholders with their aliases', function () {
    expect(ExperienceLetter::placeholders())->toEqualCanonicalizing([
        '{employee_name}', '{name}', '{designation}', '{department}', '{joining_date}', '{last_working_day}', '{company_name}', '{company}',
    ]);
});

it('fills every placeholder and alias with dates in the company format', function () {
    [, $exit] = elExit();

    $text = ExperienceLetter::render('{employee_name}/{name} {designation} {department} {joining_date} {last_working_day} {company_name}/{company} {unknown}', $exit);

    expect($text)->toBe('Sara Ahmed/Sara Ahmed Analyst Operations 01 Mar 2024 10 Dec 2026 Lumino Ltd/Lumino Ltd {unknown}');

    Settings::set('company', ['name' => 'Lumino Ltd', 'date_format' => 'Y-m-d']);

    expect(ExperienceLetter::render('{joining_date} to {last_working_day}', $exit->refresh()))->toBe('2024-03-01 to 2026-12-10');
});

it('issues the letter, stores the rendered text and audits it', function () {
    [, $exit] = elExit();
    $hr = userWithRole('hr');

    $issued = app(IssueExperienceLetter::class)->handle($hr, $exit);

    expect($issued->letter_text)->toContain('Sara Ahmed')->toContain('Lumino Ltd')->not->toContain('{')
        ->and($issued->letter_issued_by)->toBe($hr->id)
        ->and($issued->letter_issued_at)->not->toBeNull();

    $entry = Activity::query()->where('description', 'Experience letter issued')->sole();
    expect($entry->properties['reissue'])->toBeFalse()->and($entry->properties['new_length'])->toBe(mb_strlen($issued->letter_text));
});

it('re-issues with the old and new length in the audit entry and never the text', function () {
    [, $exit] = elExit();
    $hr = userWithRole('hr');
    $first = app(IssueExperienceLetter::class)->handle($hr, $exit);

    Settings::set('exit', ['experience_template' => 'Short letter for {name}.', 'clearance_items' => []]);
    $second = app(IssueExperienceLetter::class)->handle($hr, $first);

    expect($second->letter_text)->toBe('Short letter for Sara Ahmed.');

    $entry = Activity::query()->where('description', 'Experience letter issued')->latest('id')->first();
    expect($entry->properties['reissue'])->toBeTrue()
        ->and($entry->properties['old_length'])->toBe(mb_strlen($first->letter_text))
        ->and($entry->properties['new_length'])->toBe(mb_strlen('Short letter for Sara Ahmed.'))
        ->and(json_encode($entry->properties))->not->toContain('Short letter');
});

it('refuses a letter before the exit is approved', function () {
    [, $exit] = elExit('manager_approved');

    expect(fn () => app(IssueExperienceLetter::class)->handle(userWithRole('hr'), $exit))
        ->toThrow(ValidationException::class, 'The exit must be approved before a letter is issued.');
});

it('refuses your own letter', function () {
    [$employee, $exit] = elExit();
    $hr = userWithRole('hr');
    // The record belongs to one login only: move it from the employee's login to the HR login.
    User::query()->where('employee_id', $employee->id)->update(['employee_id' => null]);
    $hr->forceFill(['employee_id' => $employee->id])->save();

    expect(fn () => app(IssueExperienceLetter::class)->handle($hr, $exit))
        ->toThrow(ValidationException::class, 'You cannot issue your own experience letter.');
});

it('refuses a Manager and another employee', function () {
    [, $exit] = elExit();

    expect(fn () => app(IssueExperienceLetter::class)->handle(userWithRole('manager'), $exit))->toThrow(AuthorizationException::class)
        ->and(fn () => app(IssueExperienceLetter::class)->handle(userWithRole('employee'), $exit))->toThrow(AuthorizationException::class);
});

it('serves the letter PDF to HR only after it is issued', function () {
    [, $exit] = elExit();
    $hr = userWithRole('hr');

    $this->actingAs($hr)->get(route('exit.letter', $exit))->assertNotFound();

    $this->actingAs($hr)->post(route('exit.letter.issue', $exit))->assertRedirect();

    $this->actingAs($hr)->get(route('exit.letter', $exit))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(userWithRole('manager'))->post(route('exit.letter.issue', $exit))->assertForbidden();
});

it('lets the employee download their own letter once issued and nobody else', function () {
    [$employee, $exit] = elExit();
    $owner = $employee->user;

    $this->actingAs($owner)->get(route('my.resignation.letter', $exit))->assertNotFound();

    app(IssueExperienceLetter::class)->handle(userWithRole('hr'), $exit);

    $this->actingAs($owner)->get(route('my.resignation.letter', $exit))->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $other = userWithRole('employee');
    employeeFor($other);
    $this->actingAs($other)->get(route('my.resignation.letter', $exit))->assertForbidden();
});

it('builds the PDF from the stored text, not the current template', function () {
    [, $exit] = elExit();
    $issued = app(IssueExperienceLetter::class)->handle(userWithRole('hr'), $exit);

    Settings::set('exit', ['experience_template' => 'Changed.', 'clearance_items' => []]);

    expect($issued->refresh()->letter_text)->not->toBe('Changed.')
        ->and(ExperienceLetter::pdf($issued))->toStartWith('%PDF');
});
