import { useForm } from '@inertiajs/react';
import ExitController from '@/actions/App/Http/Controllers/Exits/ExitController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Textarea } from '@/components/ui/textarea';
import { generalErrors } from '@/lib/exit';

type Props = {
    exitId: number;
    person: string;
    hasDraft: boolean;
    onClose: () => void;
};

/**
 * Cancel an approved exit (until the settlement is approved): the last working day is cleared from the employee record,
 * a draft settlement and its lines are deleted, the clearance rows stay as history. Leave already cancelled for the old
 * day is not restored. A reason is required. The dialog stays open on a refusal. Mount it only while open.
 */
export function CancelDialog({ exitId, person, hasDraft, onClose }: Props) {
    const form = useForm({ reason: '' });
    const { errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        ['reason'],
    );

    const submit = () => {
        form.submit(ExitController.cancel(exitId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogFrame
            title={`Cancel ${person}'s exit?`}
            description={
                <>
                    The last working day is cleared and {person} stays employed.
                    {hasDraft &&
                        ' The draft settlement and its lines are deleted.'}{' '}
                    Leave already cancelled for the old last day stays
                    cancelled.
                </>
            }
            submitLabel="Cancel exit"
            destructive
            processing={form.processing}
            onClose={onClose}
            onSubmit={submit}
            general={general}
        >
            <Field
                id="exit-cancel-reason"
                label="Reason"
                error={errors.reason}
                hint="Required. At most 500 characters."
            >
                <Textarea
                    {...fieldAria('exit-cancel-reason', errors.reason, true)}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                    maxLength={500}
                    rows={3}
                />
            </Field>
        </DialogFrame>
    );
}
