<?php

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('returns the spec default when nothing is saved', function () {
    expect(settings('attendance.grace_minutes'))->toBe(15);
    expect(settings('attendance.weekly_offs'))->toBe(['saturday', 'sunday']);
    expect(settings('nope.missing', 'x'))->toBe('x');
});

it('returns a saved value over the default and keeps its type', function () {
    Settings::set('attendance', ['grace_minutes' => 10, 'overtime_enabled' => true]);

    expect(settings('attendance.grace_minutes'))->toBe(10);
    expect(settings('attendance.overtime_enabled'))->toBeTrue();
});

it('keeps arrays and strings as saved', function () {
    Settings::set('attendance', ['weekly_offs' => ['friday']]);
    Settings::set('company', ['name' => 'Acme']);

    expect(settings('attendance.weekly_offs'))->toBe(['friday']);
    expect(Settings::get('company.name'))->toBe('Acme');
    expect(Settings::group('company')['currency'])->toBe('PKR');
});

it('ignores keys and groups that are not declared in the defaults', function () {
    Settings::set('company', ['name' => 'Acme', 'secret_flag' => true]);
    Settings::set('bogus', ['x' => 1]);

    expect(DB::table('settings')->where('key', 'secret_flag')->exists())->toBeFalse();
    expect(DB::table('settings')->where('group', 'bogus')->exists())->toBeFalse();
    expect(settings('bogus.x', 'fallback'))->toBe('fallback');
});

it('stores numeric strings from form posts as the declared type', function () {
    Settings::set('attendance', ['grace_minutes' => '20', 'overtime_enabled' => '1', 'half_day_hours' => 5]);

    expect(settings('attendance.grace_minutes'))->toBe(20);
    expect(settings('attendance.overtime_enabled'))->toBeTrue();
});

it('reads the table once per request and clears the cache on set', function () {
    settings('company.name');
    DB::flushQueryLog();
    DB::enableQueryLog();

    settings('company.name');
    settings('attendance.grace_minutes');
    expect(DB::getQueryLog())->toHaveCount(0);

    Settings::set('company', ['name' => 'Changed']);
    expect(settings('company.name'))->toBe('Changed');
});

it('does not leak cached values into the next request or job', function () {
    Settings::set('company', ['name' => 'First']);
    expect(settings('company.name'))->toBe('First');

    DB::table('settings')->where('key', 'name')->update(['value' => json_encode('Second')]);
    expect(settings('company.name'))->toBe('First');

    // What the queue worker and Octane do between units of work.
    app()->forgetScopedInstances();
    expect(settings('company.name'))->toBe('Second');
});

it('falls back to defaults and still boots when the settings table does not exist', function () {
    Schema::drop('settings');
    app()->forgetScopedInstances();

    expect(settings('company.name'))->toBe('Lumino Digital');
    expect(Settings::group('attendance')['grace_minutes'])->toBe(15);

    // A mailer is resolved with no settings table and no error.
    app()->forgetInstance('mail.manager');
    expect(app('mail.manager')->mailer('array'))->not->toBeNull();

    // An artisan command that runs during a deploy.
    expect(Artisan::call('hrm:sync-access'))->toBe(0);
});
