<?php

namespace App\Actions;

use App\Models\User;
use App\Notifications\SetPasswordNotification;
use App\Support\Audit\Audit;
use App\Support\Names;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $user = $this->create($name, $email, $role);
        $this->sendLink($user);

        return $user;
    }

    /**
     * Only the database part: the account, its role and its audit entry, in one transaction (it joins the caller's
     * when there is one). No email is sent, so a caller that writes more in the same transaction (the employee
     * form) can send the link once everything has been committed.
     *
     * @param  string|null  $passwordHash  ONLY a hash the server itself made from a random secret nobody knows (the
     *                                     Excel import makes one per run, so 2,000 logins cost one bcrypt instead of
     *                                     2,000). Never a hash or a password that came from a request or a file. Null
     *                                     (every other caller): a fresh random password, hashed here.
     */
    public function create(string $name, string $email, string $role, ?User $actor = null, #[\SensitiveParameter] ?string $passwordHash = null): User
    {
        if (! in_array($role, config('hrm.roles'), true)) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }

        if ($passwordHash !== null && ! Hash::isHashed($passwordHash)) {
            throw new InvalidArgumentException('The password hash is not a hash.');
        }

        return DB::transaction(function () use ($name, $email, $role, $actor, $passwordHash) {
            $user = new User;
            $user->name = Names::normalise($name);
            $user->email = Str::lower(trim($email));
            // The model's "hashed" cast hashes a plain value and keeps an already hashed one as it is.
            $user->password = $passwordHash ?? Str::random(64);
            $user->email_verified_at = now();
            $user->is_active = true;
            $user->save();
            $user->syncRoles([$role]);

            // Never the password (it is random and unknown). Inside the transaction: no entry, no account.
            Audit::record(
                'users',
                "Account created with role {$role}",
                $user,
                ['attributes' => ['name' => $user->name, 'email' => $user->email, 'role' => $role]],
                $actor,
            );

            return $user;
        });
    }

    /**
     * Emails the set-password link and records the outcome. Never throws: the account already exists and the caller
     * decides what to tell the user (see linkSent()).
     */
    public function sendLink(User $user): bool
    {
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

        // After the account is committed and the mail queued: nothing to roll back, so a logging failure is reported, not thrown.
        Audit::tryRecord('users', $this->linkSent ? 'Set-password link sent' : 'Set-password link failed', $user);

        return $this->linkSent;
    }
}
