import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import type { StatusTone } from '@/components/hrm/status-pill';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { status as statusRoute } from '@/routes/employees';

export const STATUS_LABELS: Record<string, string> = {
    active: 'Active',
    probation: 'Probation',
    inactive: 'Inactive',
    resigned: 'Resigned',
    terminated: 'Terminated',
};

/** The pill colour of each status, shared by the list, the profile and the edit page. */
export const STATUS_TONE: Record<string, StatusTone> = {
    active: 'green',
    probation: 'amber',
    inactive: 'grey',
    resigned: 'grey',
    terminated: 'red',
};

const NEEDS_DAY = ['resigned', 'terminated'];
const SHOWS_DAY = ['inactive', 'resigned', 'terminated'];

type Props = {
    employee: {
        id: number;
        name: string;
        status: string;
        last_working_day: string | null;
        joining_date: string;
    };
    statuses: string[];
    onClose: () => void;
};

/** Change an employee's status. Mount it only while open (with a key) so it starts fresh. */
export function StatusDialog({ employee, statuses, onClose }: Props) {
    const form = useForm({
        status: employee.status,
        last_working_day: employee.last_working_day ?? '',
    });

    const needsDay = NEEDS_DAY.includes(form.data.status);
    const showsDay = SHOWS_DAY.includes(form.data.status);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        // The dialog may sit inside another page's <form> in the React tree (it renders in a portal, but events still
        // bubble to React parents): the page's own form must never see this submit.
        event.stopPropagation();

        form.transform((data) => ({
            status: data.status,
            last_working_day: showsDay ? data.last_working_day : '',
        }));
        form.submit(statusRoute(employee.id), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>Change status</DialogTitle>
                        <DialogDescription>
                            {employee.name}. Inactive, Resigned and Terminated
                            also turn off this person&apos;s login; Active and
                            Probation turn it back on.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="status-new">New status</Label>
                        <Select
                            value={form.data.status}
                            onValueChange={(value) =>
                                form.setData('status', value)
                            }
                        >
                            <SelectTrigger
                                id="status-new"
                                className="w-full"
                                aria-invalid={
                                    form.errors.status ? true : undefined
                                }
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {statuses.map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {STATUS_LABELS[status] ?? status}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError role="alert" message={form.errors.status} />
                    </div>

                    {showsDay && (
                        <div className="grid gap-2">
                            <Label htmlFor="status-last-day">
                                Last working day
                                {needsDay ? '' : ' (optional)'}
                            </Label>
                            <Input
                                id="status-last-day"
                                type="date"
                                min={employee.joining_date}
                                value={form.data.last_working_day}
                                onChange={(e) =>
                                    form.setData(
                                        'last_working_day',
                                        e.target.value,
                                    )
                                }
                                aria-invalid={
                                    form.errors.last_working_day
                                        ? true
                                        : undefined
                                }
                                aria-describedby={
                                    form.errors.last_working_day
                                        ? 'status-last-day-error'
                                        : undefined
                                }
                            />
                            <InputError
                                id="status-last-day-error"
                                role="alert"
                                message={form.errors.last_working_day}
                            />
                        </div>
                    )}

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
                            Change status
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
