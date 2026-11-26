import { useForm, usePage } from '@inertiajs/react';
import ExitController from '@/actions/App/Http/Controllers/Exits/ExitController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format-date';
import { generalErrors } from '@/lib/exit';

type Props = {
    exitId: number;
    mode: 'approve' | 'reject';
    /** Which step the viewer decides: the manager step moves it on to HR; the final step approves it. */
    stage: 'manager' | 'final';
    person: string;
    lastWorkingDay: string;
    onClose: () => void;
};

/**
 * Approve or reject an exit at the step the viewer may decide. Approving at the final step sets the last working day on the
 * employee record and opens the clearance checklist; rejecting needs a note the employee reads. Mount it only while open.
 * The dialog stays open on a refusal and shows it.
 */
export function DecideDialog({
    exitId,
    mode,
    stage,
    person,
    lastWorkingDay,
    onClose,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const form = useForm({ decision: mode, note: '' });
    const { errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        ['note'],
    );
    const reject = mode === 'reject';

    const submit = () => {
        form.submit(ExitController.decide(exitId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogFrame
            title={
                reject
                    ? `Reject ${person}'s exit?`
                    : stage === 'manager'
                      ? `Approve ${person}'s exit?`
                      : `Give the final approval?`
            }
            description={
                reject
                    ? 'The exit ends here and the person sees your note. It cannot be reopened; they would need to resign again.'
                    : stage === 'manager'
                      ? `The exit moves to HR for the final approval. The requested last working day is ${formatDate(lastWorkingDay, dateFormat)}.`
                      : `${person}'s last working day becomes ${formatDate(lastWorkingDay, dateFormat)}: attendance after it stops, approved leave after it is cancelled and the clearance checklist opens.`
            }
            submitLabel={
                reject
                    ? 'Reject exit'
                    : stage === 'manager'
                      ? 'Approve'
                      : 'Approve exit'
            }
            destructive={reject}
            processing={form.processing}
            onClose={onClose}
            onSubmit={submit}
            general={general}
        >
            {reject && (
                <Field
                    id="exit-note"
                    label="Note for the employee"
                    error={errors.note}
                    hint="Required. At most 500 characters."
                >
                    <Textarea
                        {...fieldAria('exit-note', errors.note, true)}
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        maxLength={500}
                        rows={4}
                    />
                </Field>
            )}
        </DialogFrame>
    );
}
