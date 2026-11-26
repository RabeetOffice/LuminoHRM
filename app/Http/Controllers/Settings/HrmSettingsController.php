<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\AttendanceRequest;
use App\Http\Requests\Settings\BrandingRequest;
use App\Http\Requests\Settings\CompanyRequest;
use App\Http\Requests\Settings\EmailRequest;
use App\Http\Requests\Settings\ExitRequest;
use App\Http\Requests\Settings\LeaveRequest;
use App\Http\Requests\Settings\PayrollRequest;
use App\Http\Requests\Settings\PerformanceRequest;
use App\Http\Requests\Settings\SettingsGroupRequest;
use App\Mail\TestMail;
use App\Models\LeaveType;
use App\Models\User;
use App\Support\Branding;
use App\Support\Settings;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The company Settings screen. Which groups exist and who may open them is
 * config('hrm.settings_groups'); the group's FormRequest below validates and authorises
 * every save, so HR can never write (or read) a group they do not hold the permission for.
 */
class HrmSettingsController extends Controller
{
    /** group => FormRequest class. A new group needs an entry here and in config('hrm.settings_groups'). */
    public const REQUESTS = [
        'company' => CompanyRequest::class,
        'branding' => BrandingRequest::class,
        'attendance' => AttendanceRequest::class,
        'leave' => LeaveRequest::class,
        'payroll' => PayrollRequest::class,
        'performance' => PerformanceRequest::class,
        'email' => EmailRequest::class,
        'exit' => ExitRequest::class,
    ];

    private const LABELS = [
        'company' => 'Company',
        'branding' => 'Branding',
        'attendance' => 'Attendance',
        'leave' => 'Leave',
        'payroll' => 'Payroll',
        'performance' => 'Performance',
        'email' => 'Email',
        'exit' => 'Exit',
    ];

    public function index(Request $request): Response
    {
        $groups = $this->allowedGroups($request->user());

        abort_if($groups === [], 403);

        $requested = $request->query('group');
        $active = is_string($requested) && in_array($requested, $groups, true) ? $requested : $groups[0];

        $values = [];

        foreach ($groups as $group) {
            $values[$group] = $this->present($group);
        }

        return Inertia::render('hrm-settings/index', [
            'groups' => $groups,
            'active' => $active,
            'values' => $values,
            'options' => [
                'timezones' => in_array('company', $groups, true) ? DateTimeZone::listIdentifiers() : [],
                'dateFormats' => array_map(
                    fn (string $format) => ['value' => $format, 'example' => now()->format($format)],
                    config('hrm.date_formats'),
                ),
                'placeholders' => ExitRequest::PLACEHOLDERS,
                // The paid leave types Settings > Payroll may pick for leave encashment on exit.
                'leaveTypes' => in_array('payroll', $groups, true)
                    ? LeaveType::query()->where('is_paid', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])
                        ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name])->all()
                    : [],
            ],
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        // 403 for anyone without a settings permission, whatever group they name; 404 after that.
        abort_if($this->allowedGroups($request->user()) === [], 403);

        $class = self::REQUESTS[$group] ?? null;

        abort_if($class === null, 404);

        // Resolving a FormRequest authorises it (403) and validates it (redirect with errors).
        /** @var SettingsGroupRequest $form */
        $form = app($class);

        if ($group === 'branding') {
            $this->saveBranding($form);
        } else {
            Settings::set($group, $form->settingsValues());
        }

        return back()->with('success', self::LABELS[$group].' settings saved.');
    }

    public function testEmail(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            // Always the signed-in user's own address, never one taken from the request.
            Mail::to($user->email)->send(new TestMail((string) settings('company.name')));
        } catch (Throwable $e) {
            // Only the class: the message can carry server replies, and the SMTP password must not leak.
            Log::warning('Settings test email failed', ['exception' => $e::class]);

            return back()->with('error', 'The test email could not be sent ('.$e::class.'). Check the host, port, login and encryption, then try again.');
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return back()->with('success', 'No SMTP host is saved, so the test email was only written to the mail log. Save a host to send real email.');
        }

        return back()->with('success', 'Test email sent to '.$user->email.'.');
    }

    /** @return list<string> */
    private function allowedGroups(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(
            array_keys(config('hrm.settings_groups')),
            fn (string $group) => $user->canAny(config("hrm.settings_groups.$group")),
        ));
    }

    /**
     * The settings groups $user may open, in display order (the ZKTeco Device page uses it for the same left-hand list).
     *
     * @return list<string>
     */
    public static function groupsFor(?User $user): array
    {
        return (new self)->allowedGroups($user);
    }

    /**
     * What the browser may see of a group. Secrets and raw file paths stay on the server.
     *
     * @return array<string, mixed>
     */
    private function present(string $group): array
    {
        $values = Settings::group($group);

        if ($group === 'email') {
            $values['has_password'] = $values['password'] !== '';
            unset($values['password']);
        }

        if ($group === 'branding') {
            $values['accent'] = Branding::accent();
            $values['logo_url'] = Branding::url($values['logo']);
            $values['logo_dark_url'] = Branding::url($values['logo_dark']);
            unset($values['logo'], $values['logo_dark']);
        }

        return $values;
    }

    private function saveBranding(SettingsGroupRequest $form): void
    {
        $values = $form->settingsValues();
        $stored = [];
        $replaced = [];

        foreach (['logo' => 'remove_logo', 'logo_dark' => 'remove_logo_dark'] as $key => $removeKey) {
            $file = $form->file($key);

            if ($file !== null) {
                $replaced[] = settings("branding.$key");
                $values[$key] = $stored[] = Branding::store($file);
            } elseif ($form->boolean($removeKey)) {
                $replaced[] = settings("branding.$key");
                $values[$key] = null;
            }
        }

        try {
            Settings::set('branding', $values);
        } catch (Throwable $e) {
            array_map(Branding::delete(...), $stored);

            throw $e;
        }

        // Only after the new value is saved: a replaced logo is deleted so nothing piles up.
        array_map(Branding::delete(...), $replaced);
    }
}
