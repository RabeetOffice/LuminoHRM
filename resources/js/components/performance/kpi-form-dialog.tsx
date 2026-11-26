import { useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import KpiController from '@/actions/App/Http/Controllers/Performance/KpiController';
import { CheckField } from '@/components/settings/fields';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';

export type KpiTarget = {
    type: 'designation' | 'employee';
    id: number;
    name: string | null;
    code?: string | null;
};

/** A KPI as the KPI page sends it. */
export type KpiRow = {
    id: number;
    name: string;
    description: string | null;
    weight: number;
    active: boolean;
    target: KpiTarget;
    /** How many review copies use it. */
    uses: number;
    can_delete: boolean;
};

export type KpiOptions = {
    designations: { id: number; name: string }[];
    employees: { id: number; code: string; name: string }[];
};

type Props = {
    /** The KPI being edited, or null to add a new one. */
    kpi: KpiRow | null;
    options: KpiOptions;
    onClose: () => void;
};

/**
 * Add / edit dialog (performance.kpis.store / .update). Mount it only while open so the form starts fresh. The server's
 * refusals (a weight out of range, a person who has left) show under the field they belong to.
 */
export function KpiFormDialog({ kpi, options, onClose }: Props) {
    const form = useForm({
        name: kpi?.name ?? '',
        description: kpi?.description ?? '',
        weight: kpi ? String(kpi.weight) : '',
        is_active: kpi?.active ?? true,
        target: kpi?.target.type ?? 'designation',
        designation_id:
            kpi?.target.type === 'designation' ? String(kpi.target.id) : '',
        employee_id:
            kpi?.target.type === 'employee' ? String(kpi.target.id) : '',
    });
    const { data, errors } = form;
    const targetError =
        errors.target ?? errors.designation_id ?? errors.employee_id;

    const submit = (event: FormEvent) => {
        event.preventDefault();

        // Only the chosen target's id is sent; the other is null.
        form.transform((current) => ({
            ...current,
            designation_id:
                current.target === 'designation' && current.designation_id
                    ? current.designation_id
                    : null,
            employee_id:
                current.target === 'employee' && current.employee_id
                    ? current.employee_id
                    : null,
        }));

        const options = { preserveScroll: true, onSuccess: () => onClose() };

        if (kpi) {
            form.submit(KpiController.update(kpi.id), options);
        } else {
            form.submit(KpiController.store(), options);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(open) => !open && !form.processing && onClose()}
        >
            <DialogContent className="max-h-[90dvh] overflow-y-auto sm:max-w-lg">
                <form onSubmit={submit} className="grid gap-5" noValidate>
                    <DialogHeader>
                        <DialogTitle>
                            {kpi ? 'Edit KPI' : 'Add KPI'}
                        </DialogTitle>
                        <DialogDescription>
                            {kpi
                                ? 'Reviews that are already open keep the KPI as it was when they started.'
                                : 'A KPI is copied onto each review when a cycle opens. Add it before you open the cycle.'}
                        </DialogDescription>
                    </DialogHeader>

                    <Field id="kpi-name" label="Name" error={errors.name}>
                        <Input
                            {...fieldAria('kpi-name', errors.name)}
                            value={data.name}
                            onChange={(e) =>
                                form.setData('name', e.target.value)
                            }
                            maxLength={120}
                            autoComplete="off"
                            placeholder="For example: Quality of work"
                        />
                    </Field>

                    <Field
                        id="kpi-description"
                        label="Description"
                        error={errors.description}
                        hint="Optional. What good looks like."
                    >
                        <Textarea
                            {...fieldAria(
                                'kpi-description',
                                errors.description,
                                true,
                            )}
                            value={data.description}
                            onChange={(e) =>
                                form.setData('description', e.target.value)
                            }
                            maxLength={500}
                            rows={3}
                        />
                    </Field>

                    <Field
                        id="kpi-weight"
                        label="Weight"
                        error={errors.weight}
                        hint="A relative weight: a KPI with 40 counts twice as much as one with 20. Weights do not need to add up to 100."
                    >
                        <Input
                            {...fieldAria('kpi-weight', errors.weight, true)}
                            inputMode="numeric"
                            className="w-28"
                            value={data.weight}
                            onChange={(e) =>
                                form.setData('weight', e.target.value)
                            }
                            autoComplete="off"
                        />
                    </Field>

                    <div className="grid gap-5 sm:grid-cols-2">
                        <Field id="kpi-target" label="Applies to">
                            <Select
                                value={data.target}
                                onValueChange={(value) =>
                                    form.setData(
                                        'target',
                                        value as 'designation' | 'employee',
                                    )
                                }
                            >
                                <SelectTrigger
                                    {...fieldAria('kpi-target', undefined)}
                                    className="w-full"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="designation">
                                        A designation
                                    </SelectItem>
                                    <SelectItem value="employee">
                                        One person
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </Field>

                        {data.target === 'designation' ? (
                            <Field
                                id="kpi-designation"
                                label="Designation"
                                error={targetError}
                            >
                                <Select
                                    value={data.designation_id}
                                    onValueChange={(value) =>
                                        form.setData('designation_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldAria(
                                            'kpi-designation',
                                            targetError,
                                        )}
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Choose a designation" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.designations.map((d) => (
                                            <SelectItem
                                                key={d.id}
                                                value={String(d.id)}
                                            >
                                                {d.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        ) : (
                            <Field
                                id="kpi-employee"
                                label="Person"
                                error={targetError}
                            >
                                <Select
                                    value={data.employee_id}
                                    onValueChange={(value) =>
                                        form.setData('employee_id', value)
                                    }
                                >
                                    <SelectTrigger
                                        {...fieldAria(
                                            'kpi-employee',
                                            targetError,
                                        )}
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Choose a person" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {options.employees.map((e) => (
                                            <SelectItem
                                                key={e.id}
                                                value={String(e.id)}
                                            >
                                                {e.name} ({e.code})
                                            </SelectItem>
                                        ))}
                                        {/* An old KPI of someone who has since left stays editable. */}
                                        {kpi?.target.type === 'employee' &&
                                            !options.employees.some(
                                                (e) => e.id === kpi.target.id,
                                            ) && (
                                                <SelectItem
                                                    value={String(
                                                        kpi.target.id,
                                                    )}
                                                >
                                                    {kpi.target.name}
                                                </SelectItem>
                                            )}
                                    </SelectContent>
                                </Select>
                            </Field>
                        )}
                    </div>

                    <CheckField
                        id="kpi-active"
                        label="Active"
                        hint="An inactive KPI is left out of new reviews. Reviews already open keep it."
                        checked={data.is_active}
                        error={errors.is_active}
                        onChange={(v) => form.setData('is_active', v)}
                    />

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
                            {kpi ? 'Save changes' : 'Add KPI'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
