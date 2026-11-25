<?php

use App\Http\Requests\Settings\BrandingRequest;
use App\Mail\TestMail;
use App\Models\Setting;
use App\Support\Settings;
use App\Support\SettingsStore;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

// ---- access ---------------------------------------------------------------

it('lets hr save attendance rules but not company, payroll or email', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->put('/hrm-settings/attendance', validAttendance(['grace_minutes' => 20]))->assertSessionHasNoErrors();
    expect(settings('attendance.grace_minutes'))->toBe(20);
    foreach (['company', 'branding', 'payroll', 'email', 'letters'] as $g) {
        $this->actingAs($hr)->put("/hrm-settings/$g", ['name' => 'Hacked'])->assertForbidden();
    }
    expect(settings('company.name'))->toBe('Lumino Digital');
});

it('shows hr only the groups they may edit', function () {
    $this->actingAs(userWithRole('hr'))->get('/hrm-settings')
        ->assertInertia(fn ($p) => $p->where('groups', ['attendance', 'leave'])->missing('values.payroll')->missing('values.email'));
});

it('shows admin every group in order', function () {
    $this->actingAs(userWithRole('admin'))->get('/hrm-settings')
        ->assertInertia(fn ($p) => $p->component('hrm-settings/index')
            ->where('groups', ['company', 'branding', 'attendance', 'leave', 'payroll', 'email', 'letters'])
            ->where('active', 'company')
            ->has('values.company')->has('values.letters'));
});

it('opens the group named in the query and falls back to the first for an unknown or forbidden one', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->get('/hrm-settings?group=leave')->assertInertia(fn ($p) => $p->where('active', 'leave'));
    $this->actingAs($hr)->get('/hrm-settings?group=payroll')->assertInertia(fn ($p) => $p->where('active', 'attendance'));
    $this->actingAs($hr)->get('/hrm-settings?group[]=x')->assertInertia(fn ($p) => $p->where('active', 'attendance'));
});

it('forbids managers and employees entirely', function (string $role) {
    $user = userWithRole($role);
    $this->actingAs($user)->get('/hrm-settings')->assertForbidden();
    $this->actingAs($user)->put('/hrm-settings/attendance', validAttendance())->assertForbidden();
    $this->actingAs($user)->post('/hrm-settings/email/test')->assertForbidden();
    expect(settings('attendance.grace_minutes'))->toBe(15);
})->with(['manager', 'employee']);

it('sends guests to the login page', function () {
    $this->get('/hrm-settings')->assertRedirect('/login');
    $this->put('/hrm-settings/company', [])->assertRedirect('/login');
});

it('returns 404 for an unknown group', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/bogus', [])->assertNotFound();
});

it('gives hr no way to read or write the admin groups through the API', function () {
    $hr = userWithRole('hr');
    $this->actingAs($hr)->post('/hrm-settings/email/test')->assertForbidden();
    $this->actingAs($hr)->get('/hrm-settings?group=email')->assertInertia(fn ($p) => $p->missing('values.email'));
});

// ---- validation -----------------------------------------------------------

it('validates attendance rules', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/attendance', validAttendance(['grace_minutes' => -5, 'shift_end' => '08:00', 'weekly_offs' => ['funday']]))
        ->assertSessionHasErrors(['grace_minutes', 'shift_end', 'weekly_offs.0']);
});

it('enforces the attendance ranges', function (string $field, mixed $bad, mixed $good) {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance([$field => $bad]))->assertSessionHasErrors($field);
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance([$field => $good]))->assertSessionHasNoErrors();
})->with([
    'grace over' => ['grace_minutes', 121, 120],
    'late rule zero' => ['late_rule_count', 0, 1],
    'late rule over' => ['late_rule_count', 32, 31],
    'half day zero' => ['half_day_hours', 0, 1],
    'half day over' => ['half_day_hours', 13, 12],
    'shift start format' => ['shift_start', '9am', '07:30'],
]);

it('rejects an equal shift end and an array where text is expected', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance(['shift_start' => '09:00', 'shift_end' => '09:00']))->assertSessionHasErrors('shift_end');
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance(['shift_start' => ['x']]))->assertSessionHasErrors('shift_start');
});

it('saves a weekly off list and an empty one', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance(['weekly_offs' => ['friday']]))->assertSessionHasNoErrors();
    expect(settings('attendance.weekly_offs'))->toBe(['friday']);
    $this->actingAs($admin)->put('/hrm-settings/attendance', validAttendance(['weekly_offs' => []]))->assertSessionHasNoErrors();
    expect(settings('attendance.weekly_offs'))->toBe([]);
});

it('saves leave rules with the right types', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/leave', ['hr_step_enabled' => false, 'prorate_new_joiners' => false])->assertSessionHasNoErrors();
    expect(settings('leave.hr_step_enabled'))->toBeFalse();
    expect(settings('leave.prorate_new_joiners'))->toBeFalse();
    $this->actingAs($admin)->put('/hrm-settings/leave', ['hr_step_enabled' => 'maybe', 'prorate_new_joiners' => true])->assertSessionHasErrors('hr_step_enabled');
});

it('has no carry-forward cap in leave settings: the cap lives on each leave type', function () {
    $admin = userWithRole('admin');

    // An old form posting the key, even an invalid value, is neither validated nor stored.
    $this->actingAs($admin)->put('/hrm-settings/leave', ['hr_step_enabled' => true, 'carry_forward_cap' => -1, 'prorate_new_joiners' => true])
        ->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/hrm-settings/leave', ['hr_step_enabled' => true, 'carry_forward_cap' => 7, 'prorate_new_joiners' => true])
        ->assertSessionHasNoErrors();

    expect(Setting::query()->where('group', 'leave')->where('key', 'carry_forward_cap')->exists())->toBeFalse()
        ->and(Settings::group('leave'))->toBe(['hr_step_enabled' => true, 'prorate_new_joiners' => true]);

    $this->actingAs($admin)->get('/hrm-settings?group=leave')
        ->assertInertia(fn ($p) => $p->missing('values.leave.carry_forward_cap')->where('values.leave.prorate_new_joiners', true));
});

it('validates and saves company details', function () {
    $admin = userWithRole('admin');
    $ok = ['name' => 'Acme Ltd', 'address' => '1 Main Road', 'currency' => 'USD', 'timezone' => 'Europe/London', 'date_format' => 'Y-m-d'];
    $this->actingAs($admin)->put('/hrm-settings/company', $ok)->assertSessionHasNoErrors();
    expect(settings('company.name'))->toBe('Acme Ltd')->and(settings('company.timezone'))->toBe('Europe/London');

    $this->actingAs($admin)->put('/hrm-settings/company', ['name' => '', 'currency' => 'pk', 'timezone' => 'Mars/Base', 'date_format' => 'zzz'])
        ->assertSessionHasErrors(['name', 'currency', 'timezone', 'date_format']);
    expect(settings('company.name'))->toBe('Acme Ltd');
});

it('does not change the php or app timezone when the company timezone changes', function () {
    $before = [date_default_timezone_get(), config('app.timezone')];
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/company', ['name' => 'Acme', 'address' => null, 'currency' => 'PKR', 'timezone' => 'Pacific/Auckland', 'date_format' => 'd M Y'])->assertSessionHasNoErrors();
    expect([date_default_timezone_get(), config('app.timezone')])->toBe($before);
});

it('validates payroll rules', function () {
    $admin = userWithRole('admin');
    $ok = ['day_pay_basis' => 'thirty', 'default_tax_mode' => 'none', 'leave_encashment' => true, 'notice_period_days' => 60, 'payslip_footer' => 'Thank you'];
    $this->actingAs($admin)->put('/hrm-settings/payroll', $ok)->assertSessionHasNoErrors();
    expect(settings('payroll.day_pay_basis'))->toBe('thirty')->and(settings('payroll.notice_period_days'))->toBe(60);

    $this->actingAs($admin)->put('/hrm-settings/payroll', [...$ok, 'day_pay_basis' => 'weekly', 'notice_period_days' => 400])->assertSessionHasErrors(['day_pay_basis', 'notice_period_days']);

    // An empty footer arrives as null from the form and is stored as an empty string.
    $this->actingAs($admin)->put('/hrm-settings/payroll', [...$ok, 'payslip_footer' => ''])->assertSessionHasNoErrors();
    expect(settings('payroll.payslip_footer'))->toBe('');
});

it('saves the experience letter template', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/letters', ['experience_template' => 'This is to certify {employee_name}.'])->assertSessionHasNoErrors();
    expect(settings('letters.experience_template'))->toBe('This is to certify {employee_name}.');
    $this->actingAs($admin)->put('/hrm-settings/letters', ['experience_template' => ''])->assertSessionHasErrors('experience_template');
    $this->actingAs($admin)->get('/hrm-settings?group=letters')
        ->assertInertia(fn ($p) => $p->where('values.letters.experience_template', 'This is to certify {employee_name}.'));
});

// ---- branding -------------------------------------------------------------

it('accepts only a 6-digit hex accent colour', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => 'red;}'])->assertSessionHasErrors('accent');
    foreach (['#fff', '#12345g', '10b77f', '#10b77f;', '#10B77F00', ['#10b77f']] as $bad) {
        $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => $bad])->assertSessionHasErrors('accent');
    }
    expect(settings('branding.accent'))->toBe('#10b77f');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#3366FF'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p->where('app.accent', '#3366FF'));
});

it('does not let a trailing newline through the accent rule', function () {
    // The web middleware trims input, so check the rule itself: "$" would accept this, "\z" does not.
    $rules = (new BrandingRequest)->rules();
    $v = Validator::make(['accent' => '#10b77f'.PHP_EOL], ['accent' => $rules['accent']]);

    expect($v->fails())->toBeTrue();
});

it('rejects a logo that is not an image or is over 1 MB', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->create('x.php', 10)])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('l.png')->size(2000)])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo_dark' => UploadedFile::fake()->createWithContent('l.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')])->assertSessionHasErrors('logo_dark');
    expect(Storage::disk('local')->allFiles('branding'))->toBe([]);
});

it('stores a logo privately under branding with a generated name and replaces the old one', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('Evil Name.png', 40, 40)])->assertSessionHasNoErrors();

    $first = settings('branding.logo');
    expect($first)->toMatch('#^branding/[A-Za-z0-9]{40}\.png$#');
    Storage::disk('local')->assertExists($first);
    Storage::disk('public')->assertMissing($first);

    $this->actingAs($admin)->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('app.logoUrl', '/media/'.$first)->where('app.logoDarkUrl', null));

    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('b.jpg', 40, 40)])->assertSessionHasNoErrors();
    $second = settings('branding.logo');
    expect($second)->not->toBe($first)->and($second)->toMatch('#\.jpg$#');
    Storage::disk('local')->assertMissing($first);
    Storage::disk('local')->assertExists($second);

    // Saving again without a file keeps the logo.
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#112233'])->assertSessionHasNoErrors();
    expect(settings('branding.logo'))->toBe($second);

    // Remove it.
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#112233', 'remove_logo' => true])->assertSessionHasNoErrors();
    expect(settings('branding.logo'))->toBeNull();
    Storage::disk('local')->assertMissing($second);
});

it('serves a stored logo to anyone with a correct type, cache headers and no cookies', function () {
    $name = str_repeat('a1', 20).'.png';
    Storage::disk('local')->put("branding/$name", UploadedFile::fake()->image('x.png', 10, 10)->getContent());

    $r = $this->get("/media/branding/$name");
    $r->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($r->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=')
        ->and($r->headers->getCookies())->toBe([]);
});

it('refuses anything under media/branding that the app did not generate', function (string $path) {
    Storage::disk('local')->put('branding/secret.txt', 'nope');
    Storage::disk('local')->put('private.png', 'nope');

    $this->get($path)->assertNotFound();
})->with([
    'plain name' => '/media/branding/secret.txt',
    'traversal' => '/media/branding/..%2Fprivate.png',
    'dots' => '/media/branding/../private.png',
    'backslash' => '/media/branding/..%5Cprivate.png',
    'php' => '/media/branding/a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1.php',
    'svg' => '/media/branding/a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1.svg',
    'missing' => '/media/branding/a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1a1.png',
]);

// ---- email ----------------------------------------------------------------

it('never sends the SMTP password to the browser and keeps it when left blank', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $this->actingAs($admin)->get('/hrm-settings?group=email')->assertInertia(fn ($p) => $p->missing('values.email.password')->where('values.email.has_password', true));
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => '']));
    expect(Crypt::decryptString(Settings::group('email')['password']))->toBe('secret-1');
});

it('stores the SMTP password encrypted, never in plain text', function () {
    $this->actingAs(userWithRole('admin'))->put('/hrm-settings/email', validEmail(['password' => 'secret-1']))->assertSessionHasNoErrors();

    expect(DB::table('settings')->where('group', 'email')->where('key', 'password')->value('value'))->not->toContain('secret-1');
    expect(json_encode(DB::table('settings')->get()))->not->toContain('secret-1');
});

it('can clear the stored SMTP password on request', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['clear_password' => true]));
    $this->actingAs($admin)->get('/hrm-settings?group=email')->assertInertia(fn ($p) => $p->where('values.email.has_password', false));
});

it('validates the email settings', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['port' => 70000, 'encryption' => 'rot13', 'from_address' => 'nope']))
        ->assertSessionHasErrors(['port', 'encryption', 'from_address']);
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['host' => 'bad host!']))->assertSessionHasErrors('host');
});

it('applies saved email settings to the mailer when it is first resolved', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1', 'encryption' => 'ssl', 'port' => 465]))->assertSessionHasNoErrors();

    app()->forgetScopedInstances();
    app()->forgetInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(465)
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps')
        ->and(config('mail.mailers.smtp.username'))->toBe('mailer')
        ->and(config('mail.mailers.smtp.password'))->toBe('secret-1')
        ->and(config('mail.from.address'))->toBe('hr@example.com')
        ->and(config('mail.from.name'))->toBe('Lumino HR');
});

it('leaves the environment mailer alone when no SMTP host is saved', function () {
    app()->forgetInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.default'))->toBe('array');
});

it('does not break the mailer when the stored password cannot be decrypted', function () {
    Settings::set('email', ['host' => 'smtp.example.com', 'port' => 587, 'password' => 'not-encrypted']);

    app()->forgetScopedInstances();
    app()->forgetInstance('mail.manager');
    app('mail.manager');

    expect(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.password'))->toBeNull();
});

it('sends a test email to the signed-in admin', function () {
    Mail::fake();
    $admin = userWithRole('admin');
    $this->actingAs($admin)->post('/hrm-settings/email/test')->assertSessionHas('success');
    Mail::assertSent(TestMail::class, fn ($m) => $m->hasTo($admin->email));
});

it('reports a failed test email instead of crashing, without echoing the exception message', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('Connection refused for user mailer password hunter2'));
    $this->actingAs(userWithRole('admin'))->post('/hrm-settings/email/test')->assertSessionHas('error');
    expect(session('error'))->toContain('RuntimeException')->not->toContain('hunter2')->not->toContain('Connection refused');
});

it('rate limits the test email per user', function () {
    Mail::fake();
    $admin = userWithRole('admin');
    foreach (range(1, 5) as $i) {
        $this->actingAs($admin)->post('/hrm-settings/email/test')->assertSessionHas('success');
    }
    $this->actingAs($admin)->post('/hrm-settings/email/test')->assertStatus(429);
    Mail::assertSentCount(5);
});

// ---- shared props and boundaries -------------------------------------------

it('shares the app prop with branding and the company timezone', function () {
    $this->actingAs(userWithRole('admin'))->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('app.timezone', 'Asia/Karachi')->where('app.name', 'Lumino Digital')
        ->where('app.accent', '#10b77f')->where('app.logoUrl', null)->where('app.logoDarkUrl', null));

    Settings::set('company', ['name' => 'Acme', 'timezone' => 'Europe/London']);
    $this->actingAs(userWithRole('hr'))->get('/dashboard')->assertInertia(fn ($p) => $p
        ->where('app.timezone', 'Europe/London')->where('app.name', 'Acme'));
});

it('does not read settings on the iclock endpoints, apart from the company time zone where punches need it', function () {
    config(['hrm.devices.allowed_serials' => ['TESTSN001']]);
    $settingsReads = function (callable $request): array {
        app(SettingsStore::class)->forget();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $request();
        DB::disableQueryLog();

        return collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], '"settings"') || str_contains($q['query'], '`settings`'))
            ->pluck('query')->values()->all();
    };
    $this->get('/iclock/cdata?SN=TESTSN001')->assertOk(); // creates the device row
    $zoneRead = fn () => Settings::get('company.timezone');

    // None at all: command poll, command results, tables other than ATTLOG.
    expect($settingsReads(fn () => $this->get('/iclock/getrequest?SN=TESTSN001')->assertOk()))->toBe([])
        ->and($settingsReads(fn () => $this->call('POST', '/iclock/devicecmd?SN=TESTSN001', [], [], [], [], 'ID=1&Return=0')->assertOk()))->toBe([])
        ->and($settingsReads(fn () => $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=OPERLOG', [], [], [], [], "OPLOG 4\n")->assertOk()))->toBe([]);

    // Exactly the one read behind settings('company.timezone'): the handshake's TimeZone= and the ATTLOG clock check.
    $expected = $settingsReads($zoneRead);
    expect($expected)->toHaveCount(1)
        ->and($settingsReads(fn () => $this->get('/iclock/cdata?SN=TESTSN001&options=all')->assertOk()))->toBe($expected)
        ->and($settingsReads(fn () => $this->call('POST', '/iclock/cdata?SN=TESTSN001&table=ATTLOG', [], [], [], [], "1\t2026-01-01 09:00:00\n")->assertOk()))->toBe($expected);
});

it('has no route under a reserved prefix', function () {
    $reserved = ['app', 'config', 'database', 'deploy', 'docs', 'resources', 'routes', 'scripts', 'storage', 'tests', 'vendor', 'bootstrap', 'node_modules', 'iclock'];

    $offenders = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($r) => trim($r->uri(), '/'))
        ->filter(fn ($uri) => collect($reserved)->contains(fn ($p) => $uri === $p || str_starts_with($uri, $p.'/')))
        // The iclock endpoints themselves are the one place that prefix is used.
        ->reject(fn ($uri) => str_starts_with($uri, 'iclock/'))
        ->values();

    expect($offenders->all())->toBe([]);
});

// ---- fix round 1: matrix, partial saves, hardening ---------------------------

/** group => [valid payload, [setting key, value stored by that payload]] */
function matrixCases(): array
{
    return [
        'company' => [['name' => 'Matrix Co', 'address' => null, 'currency' => 'USD', 'timezone' => 'UTC', 'date_format' => 'Y-m-d'], ['company.name', 'Matrix Co']],
        'branding' => [['accent' => '#112233'], ['branding.accent', '#112233']],
        'attendance' => [validAttendance(['grace_minutes' => 33]), ['attendance.grace_minutes', 33]],
        'leave' => [['hr_step_enabled' => false, 'prorate_new_joiners' => true], ['leave.hr_step_enabled', false]],
        'payroll' => [['day_pay_basis' => 'thirty', 'default_tax_mode' => 'none', 'leave_encashment' => false, 'notice_period_days' => 45, 'payslip_footer' => null], ['payroll.notice_period_days', 45]],
        'email' => [validEmail(['host' => 'matrix.example.com']), ['email.host', 'matrix.example.com']],
        'letters' => [['experience_template' => 'Matrix {company_name}'], ['letters.experience_template', 'Matrix {company_name}']],
    ];
}

function mayEdit(string $role, string $group): bool
{
    foreach (config("hrm.settings_groups.$group") as $permission) {
        if (in_array($role, config('hrm.permissions')[$permission], true)) {
            return true;
        }
    }

    return false;
}

it('follows the permission map for every group and role, on the page and on save', function (string $role, string $group) {
    [$payload, [$key, $value]] = matrixCases()[$group];
    $before = settings($key);
    $user = userWithRole($role);
    $allowed = mayEdit($role, $group);

    // Page: managers and employees get nothing; admin and hr get only the groups they may edit.
    $page = $this->actingAs($user)->get("/hrm-settings?group=$group");
    if (! mayEdit($role, 'attendance')) {
        $page->assertForbidden();
    } elseif ($allowed) {
        $page->assertInertia(fn ($p) => $p->where('active', $group)->has("values.$group"));
    } else {
        $page->assertInertia(fn ($p) => $p->missing("values.$group")->where('active', 'attendance'));
    }

    // Save: a valid payload is accepted or refused per the map; a refusal changes nothing.
    $response = $this->actingAs($user)->put("/hrm-settings/$group", $payload);
    if ($allowed) {
        $response->assertSessionHasNoErrors();
        expect(settings($key))->toBe($value);
    } else {
        $response->assertForbidden();
        expect(settings($key))->toBe($before);
    }
})->with(['admin', 'hr', 'manager', 'employee'])->with(array_keys(matrixCases()));

it('treats a differently cased group as unknown and answers 403 before 404 for users without settings access', function () {
    foreach (['admin', 'hr'] as $role) {
        $this->actingAs(userWithRole($role))->put('/hrm-settings/Attendance', validAttendance())->assertNotFound();
        $this->actingAs(userWithRole($role))->put('/hrm-settings/ATTENDANCE', validAttendance())->assertNotFound();
    }
    $this->actingAs(userWithRole('manager'))->put('/hrm-settings/bogus', [])->assertForbidden();
    $this->actingAs(userWithRole('employee'))->put('/hrm-settings/Attendance', [])->assertForbidden();
});

it('keeps the password out of props, errors and old input when email validation fails', function () {
    $admin = userWithRole('admin');
    $response = $this->actingAs($admin)->from('/hrm-settings?group=email')
        ->put('/hrm-settings/email', validEmail(['password' => 'hunter2-secret', 'port' => 99999]));

    $response->assertSessionHasErrors('port');
    expect(json_encode(session('errors')->getBag('default')->toArray()))->not->toContain('hunter2');
    expect(session()->getOldInput())->not->toHaveKey('password');
    expect(json_encode(session()->all()))->not->toContain('hunter2-secret');

    $page = $this->actingAs($admin)->get('/hrm-settings?group=email');
    expect($page->getContent())->not->toContain('hunter2-secret');
});

it('keeps the password out of the flash, the session and the log when a test email fails', function () {
    Log::spy();
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('AUTH failed for hunter2-secret'));

    $this->actingAs(userWithRole('admin'))->post('/hrm-settings/email/test')->assertSessionHas('error');

    expect(json_encode(session()->all()))->not->toContain('hunter2-secret');
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (...$args) => ! str_contains(json_encode($args), 'hunter2-secret') && $args[1] === ['exception' => RuntimeException::class]);
    Log::shouldNotHaveReceived('error');
});

it('limits logo pixel dimensions', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('big.png', 2001, 100)])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo_dark' => UploadedFile::fake()->image('tall.png', 100, 2001)])->assertSessionHasErrors('logo_dark');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('tiny.png', 8, 8)])->assertSessionHasErrors('logo');
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#10b77f', 'logo' => UploadedFile::fake()->image('ok.png', 2000, 16)])->assertSessionHasNoErrors();
});

it('saves only the fields sent and validates them against the stored rest of the group', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['grace_minutes' => 25])->assertSessionHasNoErrors();
    expect(settings('attendance.grace_minutes'))->toBe(25)
        ->and(settings('attendance.shift_end'))->toBe('18:00')
        ->and(settings('attendance.weekly_offs'))->toBe(['saturday', 'sunday']);

    // shift_end alone is compared with the stored shift_start (09:00).
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['shift_end' => '08:00'])->assertSessionHasErrors('shift_end');
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['shift_end' => '19:30'])->assertSessionHasNoErrors();
    expect(settings('attendance.shift_end'))->toBe('19:30');

    // A bad field is still refused on its own, and nothing is stored.
    $this->actingAs($admin)->put('/hrm-settings/attendance', ['grace_minutes' => 500])->assertSessionHasErrors('grace_minutes');
    expect(settings('attendance.grace_minutes'))->toBe(25);

    // Email: the stored password survives a partial save of other fields.
    $this->actingAs($admin)->put('/hrm-settings/email', validEmail(['password' => 'secret-1']));
    $this->actingAs($admin)->put('/hrm-settings/email', ['from_name' => 'Renamed'])->assertSessionHasNoErrors();
    expect(settings('email.from_name'))->toBe('Renamed')
        ->and(Crypt::decryptString(Settings::group('email')['password']))->toBe('secret-1');

    // Branding: the accent alone, and a logo alone without disturbing the accent.
    $this->actingAs($admin)->put('/hrm-settings/branding', ['accent' => '#445566'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->put('/hrm-settings/branding', ['logo' => UploadedFile::fake()->image('a.png', 40, 40)])->assertSessionHasNoErrors();
    expect(settings('branding.accent'))->toBe('#445566')->and(settings('branding.logo'))->not->toBeNull();
});

it('rejects unknown placeholders in the letter template and lists the supported ones', function () {
    $admin = userWithRole('admin');
    $this->actingAs($admin)->put('/hrm-settings/letters', ['experience_template' => 'Dear {employee_nam}, from {company_name}'])
        ->assertSessionHasErrors('experience_template');
    expect(session('errors')->first('experience_template'))->toContain('{employee_nam}')->toContain('{last_working_day}');
    $this->actingAs($admin)->put('/hrm-settings/letters', ['experience_template' => 'Plain {employee_name} {designation} {department} {joining_date} {last_working_day} {company_name} { not one }'])
        ->assertSessionHasNoErrors();
});
