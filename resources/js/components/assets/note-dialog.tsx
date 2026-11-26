import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import AssignmentController from '@/actions/App/Http/Controllers/Assets/AssignmentController';
import { Field, fieldAria } from '@/components/settings/settings-form';
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
import { Textarea } from '@/components/ui/textarea';
import { personLabel } from '@/lib/assets';
import type { AssetPerson } from '@/lib/assets';

type Props = {
    assignment: {
        id: number;
        employee: AssetPerson;
        /** The return date; null while the asset is still out (then there is no return note to edit). */
        returned_on: string | null;
        note: string | null;
        return_note: string | null;
    };
    onClose: () => void;
};

/**
 * Edit the hand-over note and, once the asset is back, the return note (assets.assign). Only the notes whose text
 * changed are sent: a note that is not in the request is left as it is. Mount it only while open; it stays open when
 * the server refuses.
 */
export function NoteDialog({ assignment, onClose }: Props) {
    const returned = assignment.returned_on !== null;
    const initial = {
        note: assignment.note ?? '',
        return_note: assignment.return_note ?? '',
    };
    const form = useForm(initial);
    const { data, errors } = form;
    const changed: Partial<typeof initial> = {};

    if (data.note !== initial.note) {
        changed.note = data.note;
    }

    if (returned && data.return_note !== initial.return_note) {
        changed.return_note = data.return_note;
    }

    const nothingChanged = Object.keys(changed).length === 0;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        if (nothingChanged) {
            onClose();

            return;
        }

        form.transform(() => changed);
        form.submit(AssignmentController.note(assignment.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>Edit notes</DialogTitle>
                        <DialogDescription>
                            The hand-over to {personLabel(assignment.employee)}.
                            Only the notes can change; the dates and the person
                            stay as recorded.
                        </DialogDescription>
                    </DialogHeader>

                    <Field
                        id="note-out"
                        label="Hand-over note"
                        error={errors.note}
                        hint="Optional. Leave it empty to remove the note."
                    >
                        <Textarea
                            {...fieldAria('note-out', errors.note, true)}
                            value={data.note}
                            onChange={(e) =>
                                form.setData('note', e.target.value)
                            }
                            maxLength={500}
                            rows={2}
                        />
                    </Field>

                    {returned && (
                        <Field
                            id="note-back"
                            label="Return note"
                            error={errors.return_note}
                            hint="Optional. Leave it empty to remove the note."
                        >
                            <Textarea
                                {...fieldAria(
                                    'note-back',
                                    errors.return_note,
                                    true,
                                )}
                                value={data.return_note}
                                onChange={(e) =>
                                    form.setData('return_note', e.target.value)
                                }
                                maxLength={500}
                                rows={2}
                            />
                        </Field>
                    )}

                    <DialogFooter className="flex-col-reverse gap-2 sm:flex-row">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                            disabled={form.processing}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save notes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
