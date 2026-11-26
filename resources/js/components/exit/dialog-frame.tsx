import { TriangleAlert } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';

type Props = {
    title: string;
    description: ReactNode;
    submitLabel: string;
    processing: boolean;
    onClose: () => void;
    onSubmit: () => void;
    /** Refusals that belong to no field: shown above the fields, the dialog stays open. */
    general?: string[];
    destructive?: boolean;
    submitDisabled?: boolean;
    children?: ReactNode;
    className?: string;
};

/**
 * The shell every Exit dialog uses: title, description, the server's whole-action refusals in an alert, the fields, and
 * Cancel / submit. Mount the dialog only while open so its form starts empty. It never closes by itself: the caller closes
 * it from the success callback, so a refusal leaves it open with the message in it.
 */
export function DialogFrame({
    title,
    description,
    submitLabel,
    processing,
    onClose,
    onSubmit,
    general = [],
    destructive,
    submitDisabled,
    children,
    className,
}: Props) {
    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSubmit();
    };

    return (
        <Dialog open onOpenChange={(open) => !open && !processing && onClose()}>
            <DialogContent
                className={
                    className ?? 'max-h-[90dvh] overflow-y-auto sm:max-w-lg'
                }
            >
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        <DialogDescription>{description}</DialogDescription>
                    </DialogHeader>

                    {general.length > 0 && (
                        <Alert variant="destructive" role="alert">
                            <TriangleAlert />
                            <AlertDescription>
                                {general.map((message) => (
                                    <span key={message} className="block">
                                        {message}
                                    </span>
                                ))}
                            </AlertDescription>
                        </Alert>
                    )}

                    {children}

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            variant={destructive ? 'destructive' : 'default'}
                            disabled={processing || submitDisabled}
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
