import type { ReactNode } from 'react';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: ReactNode;
    /** Runs when the user confirms. The dialog stays open: close it from your own onFinish / onSuccess. */
    onConfirm: () => void;
    confirmLabel?: string;
    cancelLabel?: string;
    /** Red confirm button (default). Pass false for a non-destructive confirmation. */
    destructive?: boolean;
    /** Disables both buttons and shows a spinner while the request runs. */
    processing?: boolean;
};

/**
 * Confirmation for destructive or irreversible actions. Controlled: the caller
 * owns `open`, so it can keep the dialog up while the request runs and close
 * it when the request finishes. Focus starts on Cancel.
 */
export function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    onConfirm,
    confirmLabel = 'Delete',
    cancelLabel = 'Cancel',
    destructive = true,
    processing = false,
}: Props) {
    return (
        <AlertDialog
            open={open}
            onOpenChange={(next) => {
                // Ignore dismissal (Escape, overlay) while a request is running.
                if (!processing) {
                    onOpenChange(next);
                }
            }}
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    <AlertDialogDescription>
                        {description}
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel asChild>
                        <Button variant="outline" disabled={processing}>
                            {cancelLabel}
                        </Button>
                    </AlertDialogCancel>
                    <Button
                        variant={destructive ? 'destructive' : 'default'}
                        disabled={processing}
                        onClick={onConfirm}
                    >
                        {processing && <Spinner />}
                        {confirmLabel}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
