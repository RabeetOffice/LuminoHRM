<?php

use App\Models\Employee;
use App\Models\Resignation;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Spatie\Activitylog\Models\Activity;

/*
| hrm:exit-apply-status: the night after the last working day the person becomes resigned / terminated and the login is
| disabled; idempotent; a refusal is stored on the exit and retried. "Now" is Wednesday 2026-12-16 14:00 in Karachi.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-16 14:00:00', 'Asia/Karachi'));
    Settings::set('attendance', ['start_date' => '2026-01-01']);
});

/** @return array{0: User, 1: Employee, 2: Resignation} an approved exit ending $day */
function aesExit(string $day, array $attrs = [], string $role = 'employee'): array
{
    $user = userWithRole($role);
    $employee = employeeFor($user, ['joining_date' => '2026-01-01']);

    return [$user, $employee, approvedExit($employee, $day, $attrs)];
}

it('does nothing on the last working day and applies from the day after', function () {
    [$user, $employee, $exit] = aesExit('2026-12-16');

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(0)
        ->and($employee->fresh()->status)->not->toBe('resigned')
        ->and($exit->fresh()->status_applied_at)->toBeNull();

    expect(Artisan::call('hrm:exit-apply-status', ['--date' => '2026-12-17']))->toBe(0)
        ->and($employee->fresh()->status)->toBe('resigned')
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($exit->fresh()->status_applied_at)->not->toBeNull();
});

it('sets resigned for a resignation and terminated for a termination, disables the login and audits', function () {
    [$resUser, $resigner, $resignation] = aesExit('2026-12-10');
    [$termUser, $terminated, $termination] = aesExit('2026-12-12', ['type' => 'termination']);

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(0)
        ->and($resigner->fresh()->status)->toBe('resigned')
        ->and($terminated->fresh()->status)->toBe('terminated')
        ->and($resUser->fresh()->is_active)->toBeFalse()
        ->and($termUser->fresh()->is_active)->toBeFalse()
        ->and($resigner->fresh()->last_working_day->format('Y-m-d'))->toBe('2026-12-10');

    $entries = Activity::query()->where('description', 'Exit status applied')->orderBy('id')->get();
    expect($entries)->toHaveCount(2)
        ->and($entries[0]->subject_id)->toBe($resignation->id)
        ->and($entries[0]->causer_id)->toBeNull()
        ->and($entries[0]->properties['attributes']['status'])->toBe('resigned')
        ->and($entries[1]->subject_id)->toBe($termination->id);
});

it('is idempotent: a second run changes and audits nothing', function () {
    [, , $exit] = aesExit('2026-12-10');

    Artisan::call('hrm:exit-apply-status');
    $appliedAt = $exit->fresh()->status_applied_at;
    Artisan::call('hrm:exit-apply-status');

    expect($exit->fresh()->status_applied_at->equalTo($appliedAt))->toBeTrue()
        ->and(Activity::query()->where('description', 'Exit status applied')->count())->toBe(1);
});

it('only marks an exit applied when HR already set the status', function () {
    [$user, $employee, $exit] = aesExit('2026-12-10');
    $employee->forceFill(['status' => 'resigned'])->save();

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(0)
        ->and($exit->fresh()->status_applied_at)->not->toBeNull()
        ->and($employee->fresh()->status)->toBe('resigned');
});

it('stores the refusal for the last active Admin, fails, and retries on the next run', function () {
    [$admin, $employee, $exit] = aesExit('2026-12-10', [], 'admin');

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(1);

    $stored = $exit->fresh();
    expect($stored->status_applied_at)->toBeNull()
        ->and($stored->status_error)->not->toBeNull()->not->toBe('')
        ->and($employee->fresh()->status)->not->toBe('resigned')
        ->and($admin->fresh()->is_active)->toBeTrue();

    // Another active Admin now exists: the next run succeeds and clears the error.
    userWithRole('admin');

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(0)
        ->and($exit->fresh()->status_applied_at)->not->toBeNull()
        ->and($exit->fresh()->status_error)->toBeNull()
        ->and($employee->fresh()->status)->toBe('resigned');
});

it('ignores pending, manager-approved, cancelled, rejected and withdrawn exits', function () {
    $rows = [];

    foreach (['pending', 'manager_approved', 'cancelled', 'rejected', 'withdrawn'] as $status) {
        $user = userWithRole('employee');
        $employee = employeeFor($user, ['joining_date' => '2026-01-01']);
        $rows[] = [$employee, resignationRow($employee, ['status' => $status, 'last_working_day' => '2026-12-10'])];
    }

    expect(Artisan::call('hrm:exit-apply-status'))->toBe(0);

    foreach ($rows as [$employee, $exit]) {
        expect($employee->fresh()->status)->not->toBe('resigned')
            ->and($exit->fresh()->status_applied_at)->toBeNull();
    }
});

it('refuses a date that is not a date', function () {
    expect(Artisan::call('hrm:exit-apply-status', ['--date' => '2026-13-45']))->toBe(1);
});
