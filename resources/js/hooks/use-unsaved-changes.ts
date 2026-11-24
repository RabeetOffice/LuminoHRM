import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export const UNSAVED_MESSAGE =
    'You have unsaved changes. Leave this page and lose them?';

/**
 * Warns before the person leaves a form with unsaved changes: for a link or button that navigates inside the app
 * (an Inertia GET visit) and for closing the tab or reloading. Saving is a PUT/POST/PATCH visit, which this never
 * blocks, so Save needs no special handling.
 */
export function useUnsavedChangesGuard(dirty: boolean): void {
    useEffect(() => {
        if (!dirty) {
            return;
        }

        const removeVisitGuard = router.on('before', (event) => {
            const method = event.detail.visit.method;

            if (method === 'get' && !window.confirm(UNSAVED_MESSAGE)) {
                event.preventDefault();
            }
        });

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            // Older browsers need a value; the text itself is never shown.
            event.returnValue = '';
        };

        window.addEventListener('beforeunload', onBeforeUnload);

        return () => {
            removeVisitGuard();
            window.removeEventListener('beforeunload', onBeforeUnload);
        };
    }, [dirty]);
}
