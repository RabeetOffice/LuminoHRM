import { usePage } from '@inertiajs/react';

/**
 * Returns a checker for the signed-in user's permissions (shared Inertia prop
 * `auth.permissions`). Use it to hide controls; the server still enforces access.
 */
export function useCan(): (permission: string) => boolean {
    const permissions = usePage().props.auth?.permissions ?? [];

    return (permission: string) => permissions.includes(permission);
}
