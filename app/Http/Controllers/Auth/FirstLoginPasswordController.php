<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FirstLoginPasswordRequest;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\EndOtherSessions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Welcome — choose your own password": the first sign-in of an account whose password somebody else chose
 * (users.must_change_password). App\Http\Middleware\EnsurePasswordChanged sends every other page here until it is done.
 */
class FirstLoginPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/first-login-password', [
            'email' => $user->email,
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function update(FirstLoginPasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->must_change_password) {
            return redirect()->route('dashboard');
        }

        DB::transaction(function () use ($user, $request): void {
            $user->forceFill([
                'password' => $request->validated('password'),
                'must_change_password' => false,
            ])->save();

            // The new password is never recorded.
            Audit::record('auth', 'Password changed at first sign-in', $user, causer: $user);
        });

        $request->session()->regenerate();
        EndOtherSessions::for($user, $request->session()->getId());

        return redirect()->route('dashboard')->with('success', 'Your new password is saved. Welcome to Lumino HRM.');
    }
}
