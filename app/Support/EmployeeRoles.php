<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Who may give which role through the employee form. One place, used by the form request and by the actions.
 *
 * An Admin may assign any of the four roles. Anyone else who manages employees (HR) may assign `employee` or
 * `manager` only, and may not change the role of an account that is currently `admin` or `hr`. Changing your own
 * role is refused further down, by the Phase 1 account rules (UpdateUserAccount), with their message.
 */
final class EmployeeRoles
{
    public const DEFAULT = 'employee';

    /** Order of the role choices in the form. */
    public const ORDER = ['employee', 'manager', 'hr', 'admin'];

    /** The role an account holds now (one role per user), or null when it has none. */
    public static function currentRole(?User $account): ?string
    {
        $name = $account?->roles()->value('name');

        return is_string($name) ? $name : null;
    }

    public const PRIVILEGED_MESSAGE = 'This login belongs to an Admin or HR account. Ask an Admin to make this change.';

    /**
     * Whether $by may touch this login through the employee screens (link it, change its name or email, change its
     * status, delete it). Only an Admin may touch an account whose CURRENT role is admin or hr. Call it with the role
     * as stored, before the request's changes.
     */
    public static function mayTouchAccount(User $by, ?User $account): bool
    {
        return $account === null || $by->hasRole('admin') || ! self::isLockedFor($by, self::currentRole($account));
    }

    /** @return list<string> */
    public static function assignable(User $by): array
    {
        return $by->hasRole('admin') ? self::ORDER : ['employee', 'manager'];
    }

    /** Whether $by may not change the role of an account that currently holds $currentRole. */
    public static function isLockedFor(User $by, ?string $currentRole): bool
    {
        return ! $by->hasRole('admin') && in_array($currentRole, ['admin', 'hr'], true);
    }

    /**
     * The role to write, or null to leave the account's role alone.
     *
     * - no current role (a new account): the posted role, or `employee`;
     * - nothing posted on an existing account: it keeps its role;
     * - the posted role equals the current one: nothing to do (this is what a read-only field posts back);
     * - linking an existing account and the plain default `employee` posted: keep the current role, so linking
     *   the first Admin never demotes them by accident;
     * - otherwise it is a real change and must be allowed.
     *
     * @throws AuthorizationException a role the actor may not give, or an account whose role the actor may not change
     */
    public static function resolve(User $by, ?string $current, mixed $posted, bool $linking = false): ?string
    {
        $posted = is_string($posted) && $posted !== '' ? $posted : null;

        if ($posted !== null && ! in_array($posted, config('hrm.roles'), true)) {
            throw new AuthorizationException('Unknown role.');
        }

        // An account with no role yet (brand new, or an odd existing one) gets the posted role or the default.
        if ($current === null) {
            $role = $posted ?? self::DEFAULT;

            if (! in_array($role, self::assignable($by), true)) {
                throw new AuthorizationException('You may not assign this role.');
            }

            return $role;
        }

        if ($posted === null || $posted === $current || ($linking && $posted === self::DEFAULT)) {
            return null;
        }

        if (! in_array($posted, self::assignable($by), true) || self::isLockedFor($by, $current)) {
            throw new AuthorizationException('You may not assign this role.');
        }

        return $posted;
    }
}
