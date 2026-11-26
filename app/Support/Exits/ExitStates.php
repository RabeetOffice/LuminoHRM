<?php

namespace App\Support\Exits;

/**
 * The states of an exit (resignations.status) in one table: what each is called for people, whether it is open, and
 * where it may go. Nothing else spells a status out.
 *
 *   pending           -> manager_approved | approved | rejected | withdrawn
 *   manager_approved  -> approved | rejected | withdrawn
 *   approved          -> cancelled
 *   rejected, withdrawn, cancelled: ended; the row is history and never changes.
 *
 * (A final approver may also approve a pending exit directly: the manager step is skipped.)
 */
final class ExitStates
{
    public const PENDING = 'pending';

    public const MANAGER_APPROVED = 'manager_approved';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const WITHDRAWN = 'withdrawn';

    public const CANCELLED = 'cancelled';

    /** status => label shown to people. */
    public const LABELS = [
        self::PENDING => 'With manager',
        self::MANAGER_APPROVED => 'With HR',
        self::APPROVED => 'Approved',
        self::REJECTED => 'Rejected',
        self::WITHDRAWN => 'Withdrawn',
        self::CANCELLED => 'Cancelled',
    ];

    /** status => the statuses it may move to. */
    public const TRANSITIONS = [
        self::PENDING => [self::MANAGER_APPROVED, self::APPROVED, self::REJECTED, self::WITHDRAWN],
        self::MANAGER_APPROVED => [self::APPROVED, self::REJECTED, self::WITHDRAWN],
        self::APPROVED => [self::CANCELLED],
        self::REJECTED => [],
        self::WITHDRAWN => [],
        self::CANCELLED => [],
    ];

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** Open = still in progress or approved and not yet cancelled (config hrm.exit.open_statuses). */
    public static function isOpen(string $status): bool
    {
        return in_array($status, (array) config('hrm.exit.open_statuses'), true);
    }

    public static function isEnded(string $status): bool
    {
        return ! self::isOpen($status);
    }

    public static function canMove(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @return list<string> */
    public static function open(): array
    {
        return array_values((array) config('hrm.exit.open_statuses'));
    }
}
