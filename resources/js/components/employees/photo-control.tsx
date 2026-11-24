import { router } from '@inertiajs/react';
import { Trash2, Upload } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ChangeEvent } from 'react';
import { Avatar } from '@/components/hrm/avatar';
import { ConfirmDialog } from '@/components/hrm/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { PHOTO_ACCEPT, photoProblem } from '@/lib/employee-files';
import {
    destroy as destroyPhoto,
    update as updatePhoto,
} from '@/routes/employees/photo';

type Props = {
    /** The saved employee, or null on the "add" form (a photo needs a record to belong to). */
    employeeId: number | null;
    /** Gives the initials shown when there is no photo. */
    name: string;
    /** The current photo (the authorised photo route), or null. */
    photoUrl: string | null;
    /** Whether the viewer may change this record's photo (the edit-record rule). */
    canEdit: boolean;
};

const ERROR_ID = 'employee-photo-error';
const HINT_ID = 'employee-photo-hint';

/**
 * The photo on the employee form: the current photo or the initials, and Upload / Replace / Remove. A chosen file is
 * previewed at once and sent by its own request (not with the form's Save), so typed but unsaved fields stay as they
 * are. The server checks the file again (type from the content, size, dimensions).
 */
export function PhotoControl({ employeeId, name, photoUrl, canEdit }: Props) {
    const input = useRef<HTMLInputElement>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [progress, setProgress] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);

    // Let go of the preview's memory when it is replaced or the control goes away.
    useEffect(() => {
        return () => {
            if (preview) {
                URL.revokeObjectURL(preview);
            }
        };
    }, [preview]);

    if (employeeId === null) {
        return (
            <div className="flex items-center gap-4">
                <Avatar name={name} size="xl" />
                <p className="text-sm text-muted-foreground">
                    You can add a photo after the employee is saved.
                </p>
            </div>
        );
    }

    const upload = (event: ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        // Clear the picker so choosing the same file again still counts as a change.
        event.target.value = '';

        if (!file) {
            return;
        }

        const problem = photoProblem(file);

        if (problem) {
            setError(problem);

            return;
        }

        setError(null);
        setPreview(URL.createObjectURL(file));
        setBusy(true);
        setProgress(0);

        router.post(
            updatePhoto.url(employeeId),
            { photo: file },
            {
                forceFormData: true,
                errorBag: 'photo',
                preserveScroll: true,
                // Keep the page (and the form's unsaved fields) as it is; only the props change.
                preserveState: true,
                onProgress: (event) => setProgress(event?.percentage ?? null),
                onError: (errors) => {
                    setPreview(null);
                    setError(
                        errors.photo ?? 'The photo could not be uploaded.',
                    );
                },
                onSuccess: () => setPreview(null),
                onFinish: () => {
                    setBusy(false);
                    setProgress(null);
                },
            },
        );
    };

    const remove = () => {
        setBusy(true);
        setError(null);

        router.delete(destroyPhoto.url(employeeId), {
            errorBag: 'photo',
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                setBusy(false);
                setConfirming(false);
            },
        });
    };

    const shown = preview ?? photoUrl;

    return (
        <div className="flex flex-wrap items-center gap-4">
            <Avatar src={shown} name={name} size="xl" />
            <div className="grid min-w-0 gap-2">
                {canEdit ? (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <input
                                ref={input}
                                type="file"
                                accept={PHOTO_ACCEPT}
                                className="sr-only"
                                tabIndex={-1}
                                aria-hidden="true"
                                onChange={upload}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                disabled={busy}
                                aria-describedby={
                                    error ? `${HINT_ID} ${ERROR_ID}` : HINT_ID
                                }
                                onClick={() => input.current?.click()}
                            >
                                {busy && progress !== null ? (
                                    <Spinner />
                                ) : (
                                    <Upload />
                                )}
                                {photoUrl ? 'Replace' : 'Upload photo'}
                            </Button>
                            {photoUrl && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="text-destructive"
                                    disabled={busy}
                                    onClick={() => setConfirming(true)}
                                >
                                    <Trash2 />
                                    Remove
                                </Button>
                            )}
                        </div>
                        <p
                            id={HINT_ID}
                            className="text-xs text-muted-foreground"
                        >
                            PNG, JPG or WebP, up to 2 MB, from 64×64 to
                            2000×2000 pixels. Saved straight away.
                        </p>
                        <p
                            aria-live="polite"
                            className="text-xs text-muted-foreground empty:hidden"
                        >
                            {busy && progress !== null
                                ? `Uploading… ${progress}%`
                                : ''}
                        </p>
                        <InputError
                            id={ERROR_ID}
                            role="alert"
                            message={error ?? undefined}
                        />
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        Only an Admin can change this person&apos;s photo.
                    </p>
                )}
            </div>

            <ConfirmDialog
                open={confirming}
                onOpenChange={setConfirming}
                title="Remove photo?"
                description={`${name || 'This employee'} will show initials instead of a photo.`}
                confirmLabel="Remove"
                processing={busy}
                onConfirm={remove}
            />
        </div>
    );
}
