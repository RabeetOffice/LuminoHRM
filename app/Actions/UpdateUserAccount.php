<?php

namespace App\Actions;

use App\Exceptions\AccountRuleViolation;
use App\Models\User;
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
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if ($actor?->is($account) && $account->roles()->pluck('name')->all() !== [$role]) {
                throw new AccountRuleViolation(self::SELF_MESSAGE);
            }

            // Email changes need no re-verification: these accounts are admin-managed.
            $account->name = Names::normalise($name);
            $account->email = Str::lower(trim($email));
            $account->save();
            $account->syncRoles([$role]);

            return $account;
        }, removesAdmin: $role !== 'admin');
    }
}
