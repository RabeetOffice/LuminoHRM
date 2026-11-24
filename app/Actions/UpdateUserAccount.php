<?php

namespace App\Actions;

use App\Exceptions\AccountRuleViolation;
use App\Models\User;
use App\Support\Audit\Audit;
use App\Support\LastAdminGuard;
use App\Support\Names;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Changes a user's name, email and (single) role. Nothing is saved when a rule
 * refuses: the last-admin check and the writes share one locked transaction.
 */
class UpdateUserAccount
{
    public const SELF_MESSAGE = 'You cannot change your own role or disable your own account.';

    /**
     * @param  User|null  $actor  who is making the change (null: no signed-in person, e.g. a console command)
     *
     * @throws AccountRuleViolation
     */
    public function handle(User $user, string $name, string $email, string $role, ?User $actor = null): User
    {
        if (! in_array($role, config('hrm.roles'), true)) {
            throw new InvalidArgumentException("Unknown role [{$role}].");
        }

        return LastAdminGuard::protect($user, function () use ($user, $name, $email, $role, $actor) {
            $account = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($actor?->is($account) && $account->roles()->pluck('name')->all() !== [$role]) {
                throw new AccountRuleViolation(self::SELF_MESSAGE);
            }

            $oldRoles = $account->roles()->pluck('name')->sort()->values()->all();

            // These accounts are admin-managed: an Admin changing the email also marks it verified, so the
            // account can always be repaired (every page needs a verified email); saving an unverified account verifies it too.
            // The model logs a name/email change itself (inside this transaction).
            $account->name = Names::normalise($name);
            $newEmail = Str::lower(trim($email));

            if ($newEmail !== $account->email || $account->email_verified_at === null) {
                $account->email_verified_at = now();
            }

            $account->email = $newEmail;
            $account->save();
            $account->syncRoles([$role]);

            // Roles do not pass through model attributes, so the change is logged here, in the same transaction.
            if ($oldRoles !== [$role]) {
                $from = $oldRoles === [] ? 'no role' : implode(', ', $oldRoles);

                Audit::record(
                    'users',
                    "Role changed from {$from} to {$role}",
                    $account,
                    ['old' => ['role' => $from], 'attributes' => ['role' => $role]],
                    $actor,
                );
            }

            return $account;
        }, removesAdmin: $role !== 'admin');
    }
}
