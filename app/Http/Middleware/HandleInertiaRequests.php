<?php

namespace App\Http\Middleware;

use App\Support\Branding;
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
                'name' => settings('company.name'),
                'accent' => Branding::accent(),
                'logoUrl' => Branding::url(settings('branding.logo')),
                'logoDarkUrl' => Branding::url(settings('branding.logo_dark')),
            ],
            'auth' => [
                'user' => $request->user(),
                'role' => $request->user()?->getRoleNames()->first() ?? '',
                'permissions' => $request->user()?->getAllPermissions()->pluck('name')->values()->all() ?? [],
            ],
            // One-shot messages set with redirect()->with('success'|'error', ...).
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
