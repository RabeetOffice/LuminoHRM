<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Names;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Creates a sign-in account and emails its owner a link to set a password.
 *
 * Works without an HTTP request and without a signed-in user, so the employee
 * form (Phase 2) can call it too. The caller is responsible for authorisation.
 *
 * - The password is random and never leaves this method: nobody is told it, it
 *   is not logged or emailed. The only way in is the set-password link.
 * - The address is marked verified: the administrator vouches for it, and the
 *   starter kit's `verified` middleware would otherwise lock the new user out of
 *   every page right after they set their password.
 */
class CreateUserAccount
{
    private bool $linkSent = false;

    /**
     * Whether the set-password link was created and handed to the mail queue by the last handle() call.
     * handle() does not throw when only the link fails: the account exists and the caller decides what to tell
     * the user (the admin can resend from the user list).
     */
    public function linkSent(): bool
    {
        return $this->linkSent;
    }

    public function handle(string $name, string $email, string $role): User
    {
        if (! in_array($role, config('hrm.roles'), true)) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }

        $user = DB::transaction(function () use ($name, $email, $role) {
            $user = new User;
            $user->name = Names::normalise($name);
            $user->email = Str::lower(trim($email));
            $user->password = Str::random(64); // hashed by the model cast
            $user->email_verified_at = now();
            $user->is_active = true;
            $user->save();
            $user->syncRoles([$role]);

            return $user;
        });

        $this->linkSent = false;

        try {
            $status = Password::broker()->sendResetLink(
                ['email' => $user->email],
                fn (User $account, string $token) => $account->notify(new SetPasswordNotification($token)),
            );
            $this->linkSent = $status === Password::RESET_LINK_SENT;
        } catch (Throwable $e) {
            // Class only: the message can carry addresses or tokens.
            Log::warning('Set-password link could not be sent for a new account.', ['exception' => $e::class]);
        }

        return $user;
    }
}
