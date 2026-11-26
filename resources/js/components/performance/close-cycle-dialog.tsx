import { router } from '@inertiajs/react';
import { useState } from 'react';
import CycleController from '@/actions/App/Http/Controllers/Performance/CycleController';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { firstError } from '@/lib/performance';

type Props = {
    cycleId: number;
    cycleName: string;
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

/**
 * Confirms closing a cycle (performance.cycles.close). Closing is final: nobody can change a review of a closed cycle. The
 * server refuses while reviews are unfinished (the message says how many); that text shows in the dialog, which stays open.
 */
export function CloseCycleDialog({
    cycleId,
    cycleName,
    open,
    onOpenChange,
}: Props) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const close = () =>
        router.post(
            CycleController.close(cycleId).url,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setError(null);
                },
                onSuccess: () => onOpenChange(false),
                onError: (errors) =>
                    setError(
                        firstError(errors) || 'The cycle could not be closed.',
                    ),
                onFinish: () => setBusy(false),
            },
        );

    return (
        <ConfirmDialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setError(null);
                }

                onOpenChange(next);
            }}
            title={`Close ${cycleName}?`}
            description={
                <>
                    Every review in the cycle is finished. Once the cycle is
                    closed, no review in it can be changed, reopened or added
                    to.
                    {error && (
                        <span
                            role="alert"
                            className="mt-2 block text-red-600 dark:text-red-400"
                        >
                            {error}
                        </span>
                    )}
                </>
            }
            confirmLabel="Close cycle"
            destructive={false}
            processing={busy}
            onConfirm={close}
        />
    );
}
