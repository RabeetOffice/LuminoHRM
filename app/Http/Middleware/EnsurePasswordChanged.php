<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While a signed-in account still has a password somebody else chose (users.must_change_password, set on the first
 * Admin made from HRM_ADMIN_PASSWORD), every page leads to /first-login/password. Only that screen, signing out and the
 * sign-in essentials (password confirmation, email verification) stay open. Guests pass straight through.
 */
class EnsurePasswordChanged
{
    public const ROUTE = 'first-login.password';

    /** Route names that stay reachable while the password still has to be changed. */
    private const ALLOWED = [
        'first-login.password',
        'first-login.password.update',
        'logout',
        'password.confirm',
        'password.confirm.store',
        'password.confirmation',
        'verification.notice',
        'verification.verify',
        'verification.send',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->must_change_password || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Choose your own password first.'], 403);
        }

        // 303 (not 302): this runs before Inertia's middleware, which would otherwise have turned a
        // PUT/PATCH/DELETE redirect into a GET of the same method.
        return redirect()->route(self::ROUTE, status: 303);
    }
}
