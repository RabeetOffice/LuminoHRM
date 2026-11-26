import { useForm, usePage } from '@inertiajs/react';
import ExitController from '@/actions/App/Http/Controllers/Exits/ExitController';
import { DialogFrame } from '@/components/exit/dialog-frame';
import { Field, fieldAria } from '@/components/settings/settings-form';
import { Input } from '@/components/ui/input';
import { generalErrors } from '@/lib/exit';
import { formatDate } from '@/lib/format-date';

type Props = {
    exitId: number;
    person: string;
    current: string;
    /** After the status has changed the day may only move to a date before today. */
    statusApplied: boolean;
    today: string;
    hasDraft: boolean;
    onClose: () => void;
};

/**
 * Move an approved exit's last working day (until the settlement is approved). The draft settlement, if any, follows the new
 * day and must be recalculated. The server's refusals (the joining date, 365 days, a locked month, the settlement) show in
 * the dialog, which stays open. Mount it only while open.
 */
export function ChangeDayDialog({
    exitId,
    person,
    current,
    statusApplied,
    today,
    hasDraft,
    onClose,
}: Props) {
    const dateFormat = usePage().props.app.dateFormat;
    const form = useForm({ last_working_day: current });
    const { errors } = form;
    const general = generalErrors(
        errors as Record<string, string | undefined>,
        ['last_working_day'],
    );

    const submit = () => {
        form.submit(ExitController.lastDay(exitId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <DialogFrame
            title="Change the last working day"
            description={
                <>
                    {person}'s last working day is{' '}
                    {formatDate(current, dateFormat)}.
                    {hasDraft &&
                        ' The draft settlement will follow the new day and must be recalculated.'}
                    {statusApplied &&
                        ' Their status has already changed, so the day can only move to a date before today.'}
                </>
            }
            submitLabel="Change day"
            processing={form.processing}
            onClose={onClose}
            onSubmit={submit}
            general={general}
        >
            <Field
                id="exit-new-day"
                label="Last working day"
                error={errors.last_working_day}
            >
                <Input
                    {...fieldAria('exit-new-day', errors.last_working_day)}
                    type="date"
                    max={statusApplied ? today : undefined}
                    value={form.data.last_working_day}
                    onChange={(e) =>
                        form.setData('last_working_day', e.target.value)
                    }
                    className="sm:w-56"
                />
            </Field>
        </DialogFrame>
    );
}
