<?php

namespace App\Http\Middleware;

use App\Support\Branding;
use App\Support\EmployeeRoles;
use App\Support\System\SystemUpdater;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Company settings the shell needs. Display only: PHP's and the app's timezone are not changed by it.
            'app' => fn () => [
                'timezone' => settings('company.timezone'),
                'dateFormat' => settings('company.date_format'),
                'name' => settings('company.name'),
                'accent' => Branding::accent(),
                'logoUrl' => Branding::url(settings('branding.logo')),
                'logoDarkUrl' => Branding::url(settings('branding.logo_dark')),
            ],
            'auth' => [
                // An explicit list on purpose: sharing the model would serialise any relation a page loads on it.
                'user' => $request->user()?->only(['id', 'name', 'email', 'email_verified_at']),
                // The same answer as the privileged checks (the most powerful role, should an account hold two).
                'role' => EmployeeRoles::currentRole($request->user()) ?? '',
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            // One-shot messages set with redirect()->with('success'|'error'|'info', ...).
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                // Neutral notices ("No change."): shown as an info toast.
                'info' => fn () => $request->session()->get('info'),
            ],
            // Admins only (settings.manage): whether "Update system" has something to apply, for the sidebar's
            // "New" badge on Updates. Cached for a minute; false on a server whose tables do not exist yet.
            'updateFlag' => fn () => $request->user()?->can('settings.manage') ? SystemUpdater::flag() : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
