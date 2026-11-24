<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Support\SharedDirectory;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // ZKTeco machines: no web group (no session, CSRF or cookies).
            Route::middleware([])->group(base_path('routes/iclock.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Real client address behind the host's CDN: only proxies listed in HRM_TRUSTED_PROXIES are believed (none by default).
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            EnsureUserIsActive::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

// On the shared host, keep .env and storage outside the (replaceable) document root.
// No effect when <base>/../shared does not exist. See docs/deploy.md.
$shared = SharedDirectory::forBasePath($app->basePath());

if ($shared->exists()) {
    $app->useEnvironmentPath($shared->environmentPath($app->environmentPath()));
    $app->useStoragePath($shared->storagePath($app->basePath()));
}

return $app;
