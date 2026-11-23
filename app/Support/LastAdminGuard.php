<?php

namespace App\Support;

use App\Exceptions\AccountRuleViolation;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Keeps at least one active Admin in the system.
 *
 * Any code that demotes, disables or (one day) deletes a user wraps the change:
 *
 *     LastAdminGuard::protect($user, function () use ($user) {
 *         $user->delete();
 *     });
 *
 * The check and the change run in one database transaction, and the active
 * admin rows are locked (SELECT ... FOR UPDATE) for the duration, so two admins
 * demoting each other at the same moment cannot both pass the check. SQLite
 * (used by the tests) has no row locks; MariaDB and MySQL do.
 */
final class LastAdminGuard
{
    public const MESSAGE = 'There must be at least one active Admin.';

    /**
     * @template T
     *
     * @param  Closure(): T  $change  runs inside the transaction; throw to roll back
     * @param  bool  $removesAdmin  false when the change keeps $user an active admin (then only the transaction is used)
     * @return T
     *
     * @throws AccountRuleViolation when $user is currently the only active admin
     */
    public static function protect(User $user, Closure $change, bool $removesAdmin = true): mixed
    {
        return DB::transaction(function () use ($user, $change, $removesAdmin) {
            if ($removesAdmin) {
                $activeAdminIds = User::role('admin')
                    ->where('users.is_active', true)
                    ->lockForUpdate()
                    ->pluck('users.id');

                if ($activeAdminIds->contains($user->getKey()) && $activeAdminIds->count() < 2) {
                    throw new AccountRuleViolation(self::MESSAGE);
                }
            }

            return $change();
        });
    }
}
