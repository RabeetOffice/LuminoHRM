import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DepartmentController from '@/actions/App/Http/Controllers/DepartmentController';
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
import { Spinner } from '@/components/ui/spinner';

export type DepartmentRow = {
    id: number;
    name: string;
    designations_count: number;
    created_at: string | null;
};

type Props = {
    /** The department being edited, or null to add a new one. */
    department: DepartmentRow | null;
    onClose: () => void;
};

/** Add / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function DepartmentDialog({ department, onClose }: Props) {
    const form = useForm({ name: department?.name ?? '' });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (department) {
            form.submit(DepartmentController.update(department.id), options);
        } else {
            form.submit(DepartmentController.store(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {department ? 'Edit department' : 'Add department'}
                        </DialogTitle>
                        <DialogDescription>
                            Departments group designations and, later,
                            employees.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="department-name">Name</Label>
                        <Input
                            id="department-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                            autoFocus
                            autoComplete="off"
                            aria-invalid={form.errors.name ? true : undefined}
                            aria-describedby={
                                form.errors.name
                                    ? 'department-name-error'
                                    : undefined
                            }
                        />
                        <InputError
                            id="department-name-error"
                            role="alert"
                            message={form.errors.name}
                        />
                    </div>

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
                            {department ? 'Save changes' : 'Add department'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
