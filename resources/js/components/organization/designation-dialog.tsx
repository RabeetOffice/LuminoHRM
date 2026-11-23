import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import DesignationController from '@/actions/App/Http/Controllers/DesignationController';
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

export type DesignationRow = {
    id: number;
    name: string;
    department_id: number;
    department_name: string;
    created_at: string | null;
};

export type DepartmentOption = { id: number; name: string };

type Props = {
    /** The designation being edited, or null to add a new one. */
    designation: DesignationRow | null;
    departments: DepartmentOption[];
    /** Department preselected when adding (for example the active filter). */
    defaultDepartmentId?: number | null;
    onClose: () => void;
};

/** Add / edit dialog. Mount it only while open (with a key) so the form starts fresh. */
export function DesignationDialog({
    designation,
    departments,
    defaultDepartmentId,
    onClose,
}: Props) {
    const form = useForm({
        name: designation?.name ?? '',
        department_id:
            designation?.department_id ??
            defaultDepartmentId ??
            ('' as number | ''),
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
        };

        if (designation) {
            form.submit(DesignationController.update(designation.id), options);
        } else {
            form.submit(DesignationController.store(), options);
        }
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent>
                <form onSubmit={submit} className="grid gap-4" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {designation
                                ? 'Edit designation'
                                : 'Add designation'}
                        </DialogTitle>
                        <DialogDescription>
                            A designation is a job title inside one department.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="designation-department">
                            Department
                        </Label>
                        <Select
                            value={
                                form.data.department_id === ''
                                    ? ''
                                    : String(form.data.department_id)
                            }
                            onValueChange={(value) =>
                                form.setData('department_id', Number(value))
                            }
                        >
                            <SelectTrigger
                                id="designation-department"
                                className="w-full"
                                aria-invalid={
                                    form.errors.department_id ? true : undefined
                                }
                            >
                                <SelectValue placeholder="Select a department" />
                            </SelectTrigger>
                            <SelectContent>
                                {departments.map((department) => (
                                    <SelectItem
                                        key={department.id}
                                        value={String(department.id)}
                                    >
                                        {department.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError
                            role="alert"
                            message={form.errors.department_id}
                        />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="designation-name">Name</Label>
                        <Input
                            id="designation-name"
                            value={form.data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={100}
                            autoComplete="off"
                            aria-invalid={form.errors.name ? true : undefined}
                            aria-describedby={
                                form.errors.name
                                    ? 'designation-name-error'
                                    : undefined
                            }
                        />
                        <InputError
                            id="designation-name-error"
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
                            {designation ? 'Save changes' : 'Add designation'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
