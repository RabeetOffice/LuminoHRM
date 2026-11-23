<?php

namespace App\Actions;

use App\Exceptions\AccountRuleViolation;
use App\Models\User;
use App\Support\LastAdminGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Disables or re-enables an account. Disabling also ends the user's sessions
 * and invalidates their remember-me token, so a copied cookie stops working at
 * once (EnsureUserIsActive would catch it on the next request anyway).
 */
class SetUserActive
{
    /**
     * @param  User|null  $actor  who is making the change (null: no signed-in person)
     *
     * @throws AccountRuleViolation
     */
    public function handle(User $user, bool $active, ?User $actor = null): User
    {
        return LastAdminGuard::protect($user, function () use ($user, $active, $actor) {
            $account = User::query()->lockForUpdate()->findOrFail($user->getKey());

            if (! $active && $actor?->is($account)) {
                throw new AccountRuleViolation(UpdateUserAccount::SELF_MESSAGE);
            }

            $account->is_active = $active;

            if (! $active) {
                $account->remember_token = null;
            }

            $account->save();

            if (! $active) {
                $this->endSessions($account);
            }

            return $account;
        }, removesAdmin: ! $active);
    }

    private function endSessions(User $user): void
    {
        $table = (string) config('session.table', 'sessions');

        if (Schema::hasTable($table)) {
            DB::table($table)->where('user_id', $user->getKey())->delete();
        }
    }
}
