import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

/**
 * Turns server flash messages into toasts. Mounted once, by the Toaster.
 *
 * - `redirect()->with('success'|'error'|'info', 'Message')` is shared by the Inertia
 *   middleware as `flash.success` / `flash.error` / `flash.info` and shown after each visit.
 * - `Inertia::flash('toast', ['type' => ..., 'message' => ...])` (used by the
 *   kit's settings pages) is shown through the flash event.
 */
export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);

    useEffect(() => {
        return router.on('success', (event) => {
            const flash = (event as CustomEvent).detail?.page?.props?.flash as
                | {
                      success?: string | null;
                      error?: string | null;
                      info?: string | null;
                  }
                | undefined;

            if (flash?.success) {
                toast.success(flash.success);
            }

            if (flash?.error) {
                toast.error(flash.error);
            }

            if (flash?.info) {
                toast.info(flash.info);
            }
        });
    }, []);
}
