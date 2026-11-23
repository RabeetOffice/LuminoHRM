<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user who was deactivated after logging in. Also the backstop for
 * sign-in routes that bypass the password check (passkeys, two-factor challenge).
 */
class EnsureUserIsActive
{
    public const MESSAGE = 'This account has been disabled. Contact HR.';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => self::MESSAGE], 403);
            }

            return redirect('/login')->withErrors(['email' => self::MESSAGE]);
        }

        return $next($request);
    }
}
