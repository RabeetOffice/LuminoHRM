import { useState } from 'react';
import type { ComponentProps } from 'react';
import { StatusDialog } from '@/components/employees/status-dialog';
import { Button } from '@/components/ui/button';

type Props = {
    employee: {
        id: number;
        name: string;
        status: string;
        last_working_day: string | null;
        joining_date: string;
    };
    /** Every status the person can be moved to (the server's list). */
    statuses: string[];
    /** Disable it when the viewer may not change the status (the server refuses the request anyway). */
    disabled?: boolean;
    variant?: ComponentProps<typeof Button>['variant'];
    size?: ComponentProps<typeof Button>['size'];
};

/**
 * "Change status" and the dialog behind it, shared by the edit page and the profile. The dialog is mounted only
 * while open, so it starts from the stored status every time.
 */
export function ChangeStatusButton({
    employee,
    statuses,
    disabled = false,
    variant = 'outline',
    size = 'sm',
}: Props) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button
                type="button"
                variant={variant}
                size={size}
                disabled={disabled}
                title={
                    disabled
                        ? 'Only an Admin can change an Admin or HR record.'
                        : undefined
                }
                onClick={() => setOpen(true)}
            >
                Change status
            </Button>
            {open && (
                <StatusDialog
                    employee={employee}
                    statuses={statuses}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}
