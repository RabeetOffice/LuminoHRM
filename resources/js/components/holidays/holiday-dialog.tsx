import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import HolidayController from '@/actions/App/Http/Controllers/HolidayController';
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
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';

export type HolidayRow = {
    id: number;
    name: string;
    /** "Y-m-d" */
    date: string;
    /** e.g. "Thursday" */
    weekday: string;
};

type Props = {
    /** The holiday being edited, or null to add a new one. */
    holiday: HolidayRow | null;
    onClose: () => void;
};

/** Add / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function HolidayDialog({ holiday, onClose }: Props) {
    const form = useForm({
        name: holiday?.name ?? '',
        date: holiday?.date ?? '',
    });
    const { data, errors } = form;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (holiday) {
            form.submit(HolidayController.update(holiday.id), options);
        } else {
            form.submit(HolidayController.store(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {holiday ? 'Edit holiday' : 'Add holiday'}
                        </DialogTitle>
                        <DialogDescription>
                            Nobody is expected at work on a holiday. Time worked
                            that day counts as overtime.
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="holiday-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('holiday-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                            autoComplete="off"
                        />
                    </Field>

                    <Field id="holiday-date" label="Date" error={errors.date}>
                        <Input
                            {...fieldAria('holiday-date', errors.date)}
                            type="date"
                            value={data.date}
                            onChange={(e) =>
                                form.setData('date', e.target.value)
                            }
                        />
                    </Field>

                    <DialogFooter>
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
                            {holiday ? 'Save changes' : 'Add holiday'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
