import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import { destroy } from '@/routes/employees';

type Props = {
    /** The person to delete; null keeps the dialog closed. */
    employee: { id: number; name: string } | null;
    onClose: () => void;
};

/**
 * "Delete this employee?" with the request behind it, shared by the list and the profile. The server decides: a
 * record with history is refused and its message comes back as a toast; a deleted one redirects to the list.
 */
export function DeleteEmployeeDialog({ employee, onClose }: Props) {
    const [processing, setProcessing] = useState(false);

    const confirm = () => {
        if (employee === null) {
            return;
        }

        router.delete(destroy(employee.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                onClose();
            },
        });
    };

    return (
        <ConfirmDialog
            open={employee !== null}
            onOpenChange={(open) => !open && onClose()}
            title={`Delete ${employee?.name ?? 'this employee'}?`}
            description="This removes the record, its contact and bank details and its login account. It only works for a record that was added by mistake and was never used; otherwise set the status to Inactive, Resigned or Terminated. This cannot be undone."
            confirmLabel="Delete employee"
            onConfirm={confirm}
            processing={processing}
        />
    );
}
